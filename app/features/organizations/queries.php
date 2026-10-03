<?php
declare(strict_types=1);

/** Organizations — the customer companies (slice 1). Reads through mcp_organizations (notes only with tickets.view_queue), writes to the base tables. */

const ORG_SELECT = 'SELECT o.organization_id, o.name, o.support_tier, o.tags, o.custom_values, o.sla_policy_id, p.name AS policy_name, o.members_see_each_other, o.active, o.notes, o.domains, o.created_at, o.updated_at,
       (SELECT count(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.organization_id = o.organization_id AND s.category NOT IN (\'solved\', \'closed\')) AS open_count,
       (SELECT count(*) FROM tickets t WHERE t.organization_id = o.organization_id) AS ticket_count,
       (SELECT count(*) FROM requesters r WHERE r.organization_id = o.organization_id AND r.merged_into_id IS NULL) AS people_count
  FROM mcp_organizations o LEFT JOIN sla_policies p ON p.id = o.sla_policy_id';

function organization_cast(array $o): array
{
    foreach (['organization_id', 'sla_policy_id', 'open_count', 'ticket_count', 'people_count'] as $k) {
        $o[$k] = $o[$k] === null ? null : (int) $o[$k];
    }
    $o['active'] = (bool) $o['active'];
    $o['members_see_each_other'] = $o['members_see_each_other'] === null ? null : (bool) $o['members_see_each_other'];
    $o['domains'] = pg_text_array((string) ($o['domains'] ?? '{}'));
    $o['tags'] = pg_text_array((string) ($o['tags'] ?? '{}'));
    return $o;
}

/** The live organizations the caller may see. $filters: q (name or domain), tier. */
function find_organizations(PDO $pdo, array $filters = []): array
{
    $where = ['o.active'];
    $args = [];
    if (($filters['q'] ?? '') !== '') {
        $where[] = '(o.name ILIKE :q OR EXISTS (SELECT 1 FROM organization_domains d WHERE d.organization_id = o.organization_id AND d.domain::text ILIKE :q))';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    if (($filters['tier'] ?? '') !== '') {
        $where[] = 'o.support_tier = :tier';
        $args['tier'] = (string) $filters['tier'];
    }
    $st = $pdo->prepare(ORG_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY lower(o.name), o.organization_id');
    $st->execute($args);
    return array_map('organization_cast', $st->fetchAll());
}

function find_organization(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(ORG_SELECT . ' WHERE o.organization_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : organization_cast($r);
}

/** The tiers in use, for the filter. */
function find_tiers(PDO $pdo): array
{
    return $pdo->query('SELECT DISTINCT support_tier FROM mcp_organizations WHERE active AND support_tier IS NOT NULL ORDER BY support_tier')->fetchAll(PDO::FETCH_COLUMN);
}

/** The base row's loggable state. */
function organization_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT name, support_tier, notes, tags, sla_policy_id, members_see_each_other, active,
                                (SELECT array_agg(d.domain::text ORDER BY d.domain) FROM organization_domains d WHERE d.organization_id = o.id) AS domains FROM organizations o WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['sla_policy_id'] = $r['sla_policy_id'] === null ? null : (int) $r['sla_policy_id'];
    $r['members_see_each_other'] = $r['members_see_each_other'] === null ? null : (bool) $r['members_see_each_other'];
    $r['active'] = (bool) $r['active'];
    $r['tags'] = pg_text_array((string) $r['tags']);
    $r['domains'] = pg_text_array((string) ($r['domains'] ?? '{}'));
    return $r;
}

/** Add ($id null) or change an organization. $f: name, support_tier, notes, tags[], sla_policy_id, members_see_each_other. $domains: the whole set. */
function save_organization(PDO $pdo, ?int $id, array $f, array $domains, int $by): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM organizations WHERE lower(name) = lower(:n) AND active AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A company named ' . $f['name'] . ' already exists.');
    }
    foreach ($domains as $d) {
        $own = $pdo->prepare('SELECT o.name FROM organization_domains x JOIN organizations o ON o.id = x.organization_id WHERE x.domain = :d AND x.organization_id IS DISTINCT FROM :id');
        $own->execute(['d' => $d, 'id' => $id]);
        $owner = $own->fetchColumn();
        if ($owner !== false) {
            throw new DomainException($d . ' already belongs to ' . $owner . '.');
        }
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO organizations (name, support_tier, notes, tags, sla_policy_id, members_see_each_other, created_by) VALUES (:n, :t, :no, CAST(:tags AS text[]), :p, :see, :by) RETURNING id');
        $st->execute(['n' => $f['name'], 't' => $f['support_tier'], 'no' => $f['notes'], 'tags' => pg_array_literal($f['tags']), 'p' => $f['sla_policy_id'],
                      'see' => $f['members_see_each_other'] === null ? null : ($f['members_see_each_other'] ? 't' : 'f'), 'by' => $by]);
        $id = (int) $st->fetchColumn();
    } else {
        $pdo->prepare('UPDATE organizations SET name = :n, support_tier = :t, notes = :no, tags = CAST(:tags AS text[]), sla_policy_id = :p, members_see_each_other = :see WHERE id = :id')
            ->execute(['n' => $f['name'], 't' => $f['support_tier'], 'no' => $f['notes'], 'tags' => pg_array_literal($f['tags']), 'p' => $f['sla_policy_id'],
                       'see' => $f['members_see_each_other'] === null ? null : ($f['members_see_each_other'] ? 't' : 'f'), 'id' => $id]);
    }
    $pdo->prepare('DELETE FROM organization_domains WHERE organization_id = :id AND NOT (domain = ANY (CAST(:keep AS citext[])))')->execute(['id' => $id, 'keep' => pg_array_literal($domains)]);
    $ins = $pdo->prepare('INSERT INTO organization_domains (domain, organization_id) VALUES (:d, :id) ON CONFLICT (domain) DO NOTHING');
    foreach ($domains as $d) {
        $ins->execute(['d' => $d, 'id' => $id]);
    }
    return $id;
}

/** Archive (deactivate) an organization. False when there is none live. */
function archive_organization(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('UPDATE organizations SET active = false WHERE id = :id AND active');
    $st->execute(['id' => $id]);
    return $st->rowCount() === 1;
}

/** The organization's live people, as the requesters view gives them. */
function find_organization_requesters(PDO $pdo, int $orgId): array
{
    $st = $pdo->prepare('SELECT requester_id, kind, member_id, display_name, email, phone, ticket_count, last_seen_at FROM mcp_requesters WHERE organization_id = :o AND merged_into_id IS NULL ORDER BY display_name');
    $st->execute(['o' => $orgId]);
    return $st->fetchAll();
}

/** A valid email domain, lower-cased, or null. */
function clean_domain(string $d): ?string
{
    $d = strtolower(trim($d));
    $d = preg_replace('#^https?://#', '', $d) ?? $d;
    $d = ltrim($d, '@');
    return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d) ? $d : null;
}
