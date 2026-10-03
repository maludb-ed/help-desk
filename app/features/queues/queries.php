<?php
declare(strict_types=1);

/**
 * Queues (slice 1, docs/build-specs/queues-people.md): the reads go through mcp_queues / mcp_queue_members / mcp_queue_presence (the
 * database decides what the caller sees), the writes through the base tables under the handler's gate. A queue's state for a log row
 * is queue_state(): before/after of the changed fields only.
 */

const ASSIGNMENT_METHODS = ['manual' => 'Manual', 'round_robin' => 'Round robin', 'least_loaded' => 'Least loaded', 'default_assignee' => 'Default assignee'];
const PRESENCE_STATES = ['online' => 'Online', 'away' => 'Away', 'offline' => 'Offline'];

const QUEUE_SELECT = 'SELECT q.queue_id, q.key, q.name, q.description, q.department_id, q.department_name, q.include_department, q.assignment_method,
       q.default_assignee_member_id, da.display_name AS default_assignee_name, da.member_kind AS default_assignee_kind,
       q.business_hours_id, b.name AS schedule_name, q.sla_policy_id, p.name AS policy_name, q.email_address, q.sort_order, q.mine, q.i_lead, q.open_count,
       (q.queue_id = (SELECT s.default_queue_id FROM app_settings s WHERE s.id = 1)) AS is_default,
       (SELECT count(*) FROM mcp_queue_members m WHERE m.queue_id = q.queue_id) AS member_count,
       (SELECT count(*) FROM mcp_queue_members m WHERE m.queue_id = q.queue_id AND m.how = \'named\') AS named_count,
       (SELECT string_agg(m.display_name, \', \' ORDER BY m.display_name) FROM mcp_queue_members m WHERE m.queue_id = q.queue_id AND m.is_lead) AS lead_names
  FROM mcp_queues q
  LEFT JOIN members da ON da.id = q.default_assignee_member_id
  LEFT JOIN business_hours b ON b.id = q.business_hours_id
  LEFT JOIN sla_policies p ON p.id = q.sla_policy_id';

function queue_cast(array $q): array
{
    foreach (['queue_id', 'department_id', 'default_assignee_member_id', 'business_hours_id', 'sla_policy_id', 'sort_order', 'open_count', 'member_count', 'named_count'] as $k) {
        $q[$k] = $q[$k] === null ? null : (int) $q[$k];
    }
    foreach (['include_department', 'mine', 'i_lead', 'is_default'] as $k) {
        $q[$k] = (bool) $q[$k];
    }
    return $q;
}

/** The live queues the caller may see, with their facts. $filters: mine (bool), q (name). */
function find_queues(PDO $pdo, array $filters = []): array
{
    $where = [];
    $args = [];
    if (!empty($filters['mine'])) {
        $where[] = 'q.mine';
    }
    if (($filters['q'] ?? '') !== '') {
        $where[] = 'q.name ILIKE :q';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
    }
    $st = $pdo->prepare(QUEUE_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY q.sort_order, lower(q.name), q.queue_id');
    $st->execute($args);
    return array_map('queue_cast', $st->fetchAll());
}

function find_queue(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(QUEUE_SELECT . ' WHERE q.queue_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : queue_cast($r);
}

/** The base row (a live or archived queue) as a log row and the edit form read it. */
function queue_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS queue_id, key, name, description, department_id, include_department, assignment_method, default_assignee_member_id,
                                business_hours_id, sla_policy_id, email_address::text AS email_address, sort_order, archived_at FROM queues WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['queue_id', 'department_id', 'default_assignee_member_id', 'business_hours_id', 'sla_policy_id', 'sort_order'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['include_department'] = (bool) $r['include_department'];
    return $r;
}

/** What a queue's log row carries. */
function queue_log_state(array $s): array
{
    return array_intersect_key($s, array_flip(['key', 'name', 'description', 'department_id', 'include_department', 'assignment_method', 'default_assignee_member_id', 'business_hours_id', 'sla_policy_id', 'email_address', 'sort_order']));
}

/** A key from a name: "IT help" → it_help. */
function derive_queue_key(string $name): string
{
    $k = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?? '', '_'));
    if ($k === '' || !ctype_alpha($k[0])) {
        $k = 'q_' . $k;
    }
    return substr(rtrim($k, '_'), 0, 40);
}

/**
 * Add ($id null) or change a queue. $f: key (create only), name, description, department_id, include_department, assignment_method,
 * default_assignee_member_id, business_hours_id, sla_policy_id, email_address, sort_order. A live name and a key are unique. Answers the id.
 */
function save_queue(PDO $pdo, ?int $id, array $f, int $by): int
{
    $dupe = $pdo->prepare('SELECT name FROM queues WHERE lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A queue named ' . $f['name'] . ' already exists.');
    }
    if ($id === null) {
        $k = $pdo->prepare('SELECT 1 FROM queues WHERE key = :k');
        $k->execute(['k' => $f['key']]);
        if ($k->fetchColumn() !== false) {
            throw new DomainException('The key ' . $f['key'] . ' is already in use.');
        }
        $st = $pdo->prepare('INSERT INTO queues (key, name, description, department_id, include_department, assignment_method, default_assignee_member_id, business_hours_id, sla_policy_id, email_address, sort_order, created_by)
                             VALUES (:k, :n, :d, :dept, :inc, :m, :da, :bh, :p, :e, :o, :by) RETURNING id');
        $st->execute(['k' => $f['key'], 'n' => $f['name'], 'd' => $f['description'], 'dept' => $f['department_id'], 'inc' => $f['include_department'] ? 't' : 'f', 'm' => $f['assignment_method'],
                      'da' => $f['default_assignee_member_id'], 'bh' => $f['business_hours_id'], 'p' => $f['sla_policy_id'], 'e' => $f['email_address'], 'o' => $f['sort_order'], 'by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE queues SET name = :n, description = :d, department_id = :dept, include_department = :inc, assignment_method = :m, default_assignee_member_id = :da,
                          business_hours_id = :bh, sla_policy_id = :p, email_address = :e, sort_order = :o WHERE id = :id')
        ->execute(['n' => $f['name'], 'd' => $f['description'], 'dept' => $f['department_id'], 'inc' => $f['include_department'] ? 't' : 'f', 'm' => $f['assignment_method'],
                   'da' => $f['default_assignee_member_id'], 'bh' => $f['business_hours_id'], 'p' => $f['sla_policy_id'], 'e' => $f['email_address'], 'o' => $f['sort_order'], 'id' => $id]);
    return $id;
}

/** The open tickets of a queue (status category not solved/closed): ids. */
function queue_open_ticket_ids(PDO $pdo, int $queueId): array
{
    $st = $pdo->prepare("SELECT t.id FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.queue_id = :q AND s.category NOT IN ('solved', 'closed') ORDER BY t.id");
    $st->execute(['q' => $queueId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Archive a queue: refused for the default; its open tickets move to the default queue. Answers ['moved' => ids, 'to' => default queue id, 'name' => …]. */
function archive_queue(PDO $pdo, int $id, int $by): array
{
    $s = queue_state($pdo, $id) ?? throw new DomainException('Not found.');
    if ($s['archived_at'] !== null) {
        throw new DomainException($s['name'] . ' is already archived.');
    }
    $default = (int) $pdo->query('SELECT default_queue_id FROM app_settings WHERE id = 1')->fetchColumn();
    if ($default === $id) {
        throw new DomainException('Choose another default queue first.');
    }
    $moved = queue_open_ticket_ids($pdo, $id);
    if ($moved !== []) {
        $pdo->prepare('UPDATE tickets SET queue_id = :to, last_activity_at = now() WHERE id = ANY (CAST(:ids AS bigint[]))')->execute(['to' => $default, 'ids' => '{' . implode(',', $moved) . '}']);
    }
    $pdo->prepare('UPDATE queues SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
    return ['moved' => $moved, 'to' => $default, 'name' => $s['name']];
}

/** The queue's members as the view gives them: named first (leads first), then the department's. */
function queue_members(PDO $pdo, int $queueId): array
{
    $st = $pdo->prepare("SELECT member_id, display_name, member_kind, is_lead, how FROM mcp_queue_members WHERE queue_id = :q ORDER BY (how = 'named') DESC, is_lead DESC, display_name");
    $st->execute(['q' => $queueId]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['member_id'] = (int) $r['member_id'];
        $r['is_lead'] = (bool) $r['is_lead'];
    }
    unset($r);
    return $rows;
}

/** Name a member (a person or an agent) in a queue, or change their lead flag. The member must be active with a capability. */
function add_queue_member(PDO $pdo, int $queueId, int $memberId, bool $lead, int $by): void
{
    $m = $pdo->prepare("SELECT 1 FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL");
    $m->execute(['m' => $memberId]);
    if ($m->fetchColumn() === false) {
        throw new DomainException('That member is not active here.');
    }
    $pdo->prepare('INSERT INTO queue_members (queue_id, member_id, is_lead, added_by) VALUES (:q, :m, :l, :by) ON CONFLICT (queue_id, member_id) DO UPDATE SET is_lead = EXCLUDED.is_lead')
        ->execute(['q' => $queueId, 'm' => $memberId, 'l' => $lead ? 't' : 'f', 'by' => $by]);
}

function remove_queue_member(PDO $pdo, int $queueId, int $memberId): bool
{
    $st = $pdo->prepare('DELETE FROM queue_members WHERE queue_id = :q AND member_id = :m');
    $st->execute(['q' => $queueId, 'm' => $memberId]);
    return $st->rowCount() === 1;
}

/** A person's own availability and capacity for least-loaded assignment. */
function set_presence(PDO $pdo, int $memberId, string $availability, ?int $capacity): void
{
    $pdo->prepare('INSERT INTO queue_presence (member_id, availability, capacity) VALUES (:m, :a, :c)
                   ON CONFLICT (member_id) DO UPDATE SET availability = EXCLUDED.availability, capacity = EXCLUDED.capacity, updated_at = now()')
        ->execute(['m' => $memberId, 'a' => $availability, 'c' => $capacity]);
}

function find_my_presence(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT availability, capacity FROM queue_presence WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    return $r === false ? ['availability' => 'online', 'capacity' => null, 'saved' => false]
        : ['availability' => $r['availability'], 'capacity' => $r['capacity'] === null ? null : (int) $r['capacity'], 'saved' => true];
}

/** The presence rows of a queue's members, as the view allows: member_id => {availability, capacity}. */
function find_queue_presence(PDO $pdo, int $queueId): array
{
    $st = $pdo->prepare('SELECT p.member_id, p.availability, p.capacity FROM mcp_queue_presence p WHERE p.member_id IN (SELECT member_id FROM mcp_queue_members WHERE queue_id = :q)');
    $st->execute(['q' => $queueId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[(int) $r['member_id']] = ['availability' => $r['availability'], 'capacity' => $r['capacity'] === null ? null : (int) $r['capacity']];
    }
    return $out;
}
