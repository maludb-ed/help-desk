<?php
declare(strict_types=1);

/**
 * Requesters — the people who ask (slice 1): members, externals and agents. Reads through mcp_requesters (email, phone and notes only as the
 * view allows), writes to the base tables; a new external goes through hd_requester_for_email() so a known address is never doubled.
 */

const REQUESTER_KINDS = ['member' => 'Member', 'external' => 'External', 'agent' => 'Agent'];

const REQUESTER_SELECT = 'SELECT r.requester_id, r.kind, r.member_id, r.display_name, r.organization_id, r.organization_name, r.locale, r.timezone, r.email, r.phone, r.notes,
       r.merged_into_id, r.last_seen_at, r.created_at, r.ticket_count FROM mcp_requesters r';

function requester_cast(array $r): array
{
    foreach (['requester_id', 'member_id', 'organization_id', 'merged_into_id', 'ticket_count'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    return $r;
}

/** The live (unmerged) requesters the caller may see. $filters: q (name or email), kind, organization. */
function find_requesters(PDO $pdo, array $filters = []): array
{
    $where = ['r.merged_into_id IS NULL'];
    $args = [];
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(r.display_name ILIKE :q OR r.email ILIKE :q OR r.requester_id IN (SELECT e.requester_id FROM requester_emails e WHERE e.email::text ILIKE :q))';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (isset(REQUESTER_KINDS[$filters['kind'] ?? ''])) {
        $where[] = 'r.kind = :kind';
        $args['kind'] = $filters['kind'];
    }
    if (($filters['organization'] ?? null) !== null) {
        $where[] = 'r.organization_id = :org';
        $args['org'] = (int) $filters['organization'];
    }
    $st = $pdo->prepare(REQUESTER_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY lower(r.display_name), r.requester_id LIMIT 200');
    $st->execute($args);
    return array_map('requester_cast', $st->fetchAll());
}

function find_requester(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(REQUESTER_SELECT . ' WHERE r.requester_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = requester_cast($r);
    $e = $pdo->prepare('SELECT email::text AS email, verified_at FROM requester_emails WHERE requester_id = :id ORDER BY (email = :primary) DESC, created_at');
    $e->execute(['id' => $id, 'primary' => (string) ($r['email'] ?? '')]);
    $r['emails'] = $e->fetchAll();
    return $r;
}

/** The base row's loggable state (never the notes' text). */
function requester_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT kind, member_id, display_name, primary_email::text AS email, phone, organization_id, locale, timezone, (notes IS NOT NULL) AS has_notes, merged_into_id FROM requesters WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['member_id', 'organization_id', 'merged_into_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['has_notes'] = (bool) $r['has_notes'];
    return $r;
}

/** Who an address belongs to already: ['requester_id', 'display_name'] (a member's requester row made on the way), or null when nobody. */
function requester_owning_email(PDO $pdo, string $email): ?array
{
    $st = $pdo->prepare('SELECT r.id, r.display_name, r.merged_into_id FROM requester_emails e JOIN requesters r ON r.id = e.requester_id WHERE e.email = :e');
    $st->execute(['e' => $email]);
    $r = $st->fetch();
    if ($r !== false) {
        $id = $r['merged_into_id'] !== null ? (int) $r['merged_into_id'] : (int) $r['id'];
        $name = (string) $pdo->query('SELECT display_name FROM requesters WHERE id = ' . $id)->fetchColumn();
        return ['requester_id' => $id, 'display_name' => $name];
    }
    $m = $pdo->prepare("SELECT id, display_name FROM members WHERE email = :e AND status = 'active'");
    $m->execute(['e' => $email]);
    $mr = $m->fetch();
    if ($mr !== false) {
        $rid = (int) $pdo->query('SELECT hd_requester_for_member(' . (int) $mr['id'] . ')')->fetchColumn();
        return ['requester_id' => $rid, 'display_name' => (string) $mr['display_name']];
    }
    return null;
}

/**
 * Add an external by hand: hd_requester_for_email() makes the row (claimed by a domain) after the address is checked unknown; then the fields.
 * $fields: phone, organization_id (overrides the claim when given), locale, timezone, notes. Answers the id.
 */
function create_requester(PDO $pdo, string $name, string $email, array $fields): int
{
    $email = strtolower(trim($email));
    $owner = requester_owning_email($pdo, $email);
    if ($owner !== null) {
        throw new DomainException('That address belongs to ' . $owner['display_name'] . ' — open them instead.|' . $owner['requester_id']);
    }
    $st = $pdo->prepare('SELECT hd_requester_for_email(:e, :n)');
    $st->execute(['e' => $email, 'n' => $name]);
    $id = (int) $st->fetchColumn();
    $set = ['phone' => $fields['phone'] ?? null, 'locale' => $fields['locale'] ?? null, 'timezone' => $fields['timezone'] ?? null, 'notes' => $fields['notes'] ?? null];
    $sql = 'UPDATE requesters SET display_name = :n, phone = :p, locale = :l, timezone = :tz, notes = :no' . (array_key_exists('organization_id', $fields) && $fields['organization_id'] !== null ? ', organization_id = :org' : '') . ' WHERE id = :id';
    $args = ['n' => $name, 'p' => $set['phone'], 'l' => $set['locale'], 'tz' => $set['timezone'], 'no' => $set['notes'], 'id' => $id];
    if (array_key_exists('organization_id', $fields) && $fields['organization_id'] !== null) {
        $args['org'] = $fields['organization_id'];
    }
    $pdo->prepare($sql)->execute($args);
    return $id;
}

/** Change a requester. A member's or an agent's name and email are the mirror's: untouched. $fields: display_name, phone, organization_id, locale, timezone, notes. */
function update_requester(PDO $pdo, int $id, array $fields): void
{
    $cur = requester_state($pdo, $id) ?? throw new DomainException('Not found.');
    $nameSql = $cur['kind'] === 'external' && array_key_exists('display_name', $fields) ? 'display_name = :n, ' : '';
    $args = ['p' => $fields['phone'], 'org' => $fields['organization_id'], 'l' => $fields['locale'], 'tz' => $fields['timezone'], 'no' => $fields['notes'], 'id' => $id];
    if ($nameSql !== '') {
        $args['n'] = $fields['display_name'];
    }
    $pdo->prepare('UPDATE requesters SET ' . $nameSql . 'phone = :p, organization_id = :org, locale = :l, timezone = :tz, notes = :no WHERE id = :id')->execute($args);
}

/** Add an address to a requester. A known address is refused with its owner's name. */
function add_requester_email(PDO $pdo, int $id, string $email): void
{
    $email = strtolower(trim($email));
    if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
        throw new DomainException('That is not an email address.');
    }
    $owner = requester_owning_email($pdo, $email);
    if ($owner !== null) {
        if ($owner['requester_id'] === $id) {
            throw new DomainException('They already have that address.');
        }
        throw new DomainException('That address belongs to ' . $owner['display_name'] . '.|' . $owner['requester_id']);
    }
    $pdo->prepare('INSERT INTO requester_emails (email, requester_id) VALUES (:e, :id)')->execute(['e' => $email, 'id' => $id]);
    $pdo->prepare('UPDATE requesters SET primary_email = COALESCE(primary_email, :e) WHERE id = :id')->execute(['e' => $email, 'id' => $id]);
}

/** Remove an address — never the last; removing the primary makes the oldest other one primary. False when they do not have it. */
function remove_requester_email(PDO $pdo, int $id, string $email): bool
{
    $email = strtolower(trim($email));
    $all = $pdo->prepare('SELECT email::text AS email FROM requester_emails WHERE requester_id = :id ORDER BY created_at');
    $all->execute(['id' => $id]);
    $emails = $all->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($email, array_map('strtolower', $emails), true)) {
        return false;
    }
    if (count($emails) <= 1) {
        throw new DomainException('A requester keeps at least one address.');
    }
    $pdo->prepare('DELETE FROM requester_emails WHERE requester_id = :id AND email = :e')->execute(['id' => $id, 'e' => $email]);
    $rest = array_values(array_filter($emails, static fn (string $x): bool => strtolower($x) !== $email));
    $pdo->prepare('UPDATE requesters SET primary_email = CASE WHEN lower(primary_email::text) = :e THEN CAST(:first AS citext) ELSE primary_email END WHERE id = :id')->execute(['e' => $email, 'first' => $rest[0], 'id' => $id]);
    return true;
}

/** Merge $from into $into: tickets, followers, ratings, messages, attachments and addresses re-pointed; merged_into_id set. Answers the counts. */
function merge_requesters(PDO $pdo, int $from, int $into, int $by): array
{
    if ($from === $into) {
        throw new DomainException('Choose two different requesters.');
    }
    $a = requester_state($pdo, $from) ?? throw new DomainException('Not found.');
    $b = requester_state($pdo, $into) ?? throw new DomainException('Not found.');
    if ($a['merged_into_id'] !== null || $b['merged_into_id'] !== null) {
        throw new DomainException('One of them was merged already.');
    }
    if ($a['kind'] !== 'external' && $b['kind'] === 'external') {
        throw new DomainException('Merge the external into the member, not the other way.');
    }
    if ($a['kind'] !== 'external' && $b['kind'] !== 'external') {
        throw new DomainException('Two members cannot be merged — their identity is the OS\'s.');
    }
    $counts = [];
    foreach ([['tickets', 'requester_id'], ['ticket_followers', 'requester_id'], ['satisfaction_ratings', 'requester_id'], ['messages', 'author_requester_id'], ['attachments', 'uploaded_by_requester_id']] as [$table, $col]) {
        $st = $pdo->prepare("UPDATE {$table} SET {$col} = :into WHERE {$col} = :from");
        $st->execute(['into' => $into, 'from' => $from]);
        $counts[$table] = $st->rowCount();
    }
    $st = $pdo->prepare('UPDATE requester_emails SET requester_id = :into WHERE requester_id = :from');
    $st->execute(['into' => $into, 'from' => $from]);
    $counts['emails'] = $st->rowCount();
    $pdo->prepare('UPDATE requesters SET merged_into_id = :into, organization_id = COALESCE(organization_id, (SELECT organization_id FROM requesters WHERE id = :into2)) WHERE id = :from')->execute(['into' => $into, 'into2' => $into, 'from' => $from]);
    $pdo->prepare('UPDATE requesters SET organization_id = COALESCE(organization_id, :o), phone = COALESCE(phone, :p) WHERE id = :into')->execute(['o' => $a['organization_id'], 'p' => $a['phone'], 'into' => $into]);
    return ['tickets' => $counts['tickets'], 'emails' => $counts['emails'], 'followers' => $counts['ticket_followers'], 'ratings' => $counts['satisfaction_ratings'], 'messages' => $counts['messages']];
}

/** The live organizations, for a select. */
function find_organizations_for_select(PDO $pdo): array
{
    return $pdo->query('SELECT organization_id, name FROM mcp_organizations WHERE active ORDER BY lower(name)')->fetchAll();
}

/** One page of the live organizations, searchable by name or domain — the record picker's source. Reads mcp_organizations: what the caller may see. */
function find_organizations_page(PDO $pdo, string $q, int $page): array
{
    $where = 'active';
    $args = [];
    if ($q !== '') {
        $where .= ' AND (name ILIKE :q OR EXISTS (SELECT 1 FROM unnest(COALESCE(domains, ARRAY[]::text[])) d WHERE d ILIKE :q))';
        $args['q'] = picker_like($q);
    }
    $n = $pdo->prepare("SELECT count(*) FROM mcp_organizations WHERE $where");
    $n->execute($args);
    $st = $pdo->prepare("SELECT organization_id, name, support_tier, domains FROM mcp_organizations WHERE $where ORDER BY lower(name), organization_id LIMIT " . PICKER_PAGE_SIZE . ' OFFSET ' . (($page - 1) * PICKER_PAGE_SIZE));
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['domains'] = pg_text_array((string) ($r['domains'] ?? '{}'));
    }
    unset($r);
    return picker_result($rows, (int) $n->fetchColumn(), $page);
}

/** One live organization's name as the caller sees it — the picker's label lookup. */
function find_organization_name(PDO $pdo, int $id): ?string
{
    $st = $pdo->prepare('SELECT name FROM mcp_organizations WHERE organization_id = :id AND active');
    $st->execute(['id' => $id]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string) $v;
}
