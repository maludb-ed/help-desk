<?php
declare(strict_types=1);

/**
 * SLA policies and business-hours schedules (slice 1). Reads through mcp_sla_policies / mcp_business_hours, writes to the base tables.
 * A policy's conditions and targets are jsonb; the form's grid and an agent's JSON both land in the same shape (db/007).
 */

const PRIORITIES = ['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'];
const SLA_METRICS = ['first_response' => 'First response', 'next_response' => 'Next response', 'resolution' => 'Resolution'];
const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function policy_cast(array $p): array
{
    foreach (['policy_id', 'sort_order', 'business_hours_id', 'warning_percent'] as $k) {
        $p[$k] = $p[$k] === null ? null : (int) $p[$k];
    }
    $p['active'] = (bool) $p['active'];
    $p['conditions'] = is_array($p['conditions']) ? $p['conditions'] : (json_decode((string) $p['conditions'], true) ?: []);
    $p['targets'] = is_array($p['targets']) ? $p['targets'] : (json_decode((string) $p['targets'], true) ?: []);
    return $p;
}

/** The policies in match order (active unless $all), each with its schedule's name and its match conditions in words. */
function find_sla_policies(PDO $pdo, bool $all = false): array
{
    $st = $pdo->prepare('SELECT p.policy_id, p.name, p.description, p.sort_order, p.conditions, p.targets, p.business_hours_id, b.name AS schedule_name, p.warning_percent, p.active, p.updated_at
                           FROM mcp_sla_policies p LEFT JOIN business_hours b ON b.id = p.business_hours_id' . ($all ? '' : ' WHERE p.active') . ' ORDER BY p.sort_order, p.policy_id');
    $st->execute();
    $rows = array_map('policy_cast', $st->fetchAll());
    foreach ($rows as &$r) {
        $r['matches'] = policy_match_words($pdo, $r['conditions']);
    }
    unset($r);
    return $rows;
}

function find_sla_policy(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT p.policy_id, p.name, p.description, p.sort_order, p.conditions, p.targets, p.business_hours_id, b.name AS schedule_name, p.warning_percent, p.active, p.updated_at
                           FROM mcp_sla_policies p LEFT JOIN business_hours b ON b.id = p.business_hours_id WHERE p.policy_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r = policy_cast($r);
    $r['matches'] = policy_match_words($pdo, $r['conditions']);
    return $r;
}

/** The base row's loggable state. */
function policy_state(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT name, description, sort_order, conditions, targets, business_hours_id, warning_percent, active FROM sla_policies WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['sort_order'] = (int) $r['sort_order'];
    $r['business_hours_id'] = $r['business_hours_id'] === null ? null : (int) $r['business_hours_id'];
    $r['warning_percent'] = (int) $r['warning_percent'];
    $r['active'] = (bool) $r['active'];
    $r['conditions'] = json_decode((string) $r['conditions'], true) ?: [];
    $r['targets'] = json_decode((string) $r['targets'], true) ?: [];
    return $r;
}

/** "any organization · IT help · urgent and high · tagged vip" from the conditions. */
function policy_match_words(PDO $pdo, array $c): string
{
    $names = static function (string $table, string $col, array $ids) use ($pdo): array {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $st = $pdo->prepare("SELECT {$col} FROM {$table} WHERE id = ANY (CAST(:ids AS bigint[])) ORDER BY {$col}");
        $st->execute(['ids' => '{' . implode(',', $ids) . '}']);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    };
    $parts = [];
    $orgs = $names('organizations', 'name', $c['organization_ids'] ?? []);
    $parts[] = $orgs === [] ? 'any organization' : implode(', ', $orgs);
    $queues = $names('queues', 'name', $c['queue_ids'] ?? []);
    $parts[] = $queues === [] ? 'any queue' : implode(', ', $queues);
    $types = $names('ticket_types', 'label', $c['type_ids'] ?? []);
    if ($types !== []) {
        $parts[] = implode(', ', $types);
    }
    $pr = array_values(array_intersect(array_keys(PRIORITIES), (array) ($c['priorities'] ?? [])));
    if ($pr !== []) {
        $parts[] = count($pr) === 1 ? $pr[0] : implode(', ', array_slice($pr, 0, -1)) . ' and ' . end($pr);
    }
    $tags = array_values(array_filter(array_map('strval', (array) ($c['tags'] ?? []))));
    if ($tags !== []) {
        $parts[] = 'tagged ' . implode(' or ', $tags);
    }
    return implode(' · ', $parts);
}

/** Add ($id null) or change a policy. $f: name, description, sort_order, business_hours_id, warning_percent; $conditions and $targets as db/007 shapes. */
function save_sla_policy(PDO $pdo, ?int $id, array $f, array $conditions, array $targets): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM sla_policies WHERE lower(name) = lower(:n) AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A policy named ' . $f['name'] . ' already exists.');
    }
    $args = ['n' => $f['name'], 'd' => $f['description'], 'o' => $f['sort_order'], 'c' => json_encode((object) $conditions), 't' => json_encode((object) $targets), 'bh' => $f['business_hours_id'], 'w' => $f['warning_percent']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO sla_policies (name, description, sort_order, conditions, targets, business_hours_id, warning_percent) VALUES (:n, :d, :o, CAST(:c AS jsonb), CAST(:t AS jsonb), :bh, :w) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE sla_policies SET name = :n, description = :d, sort_order = :o, conditions = CAST(:c AS jsonb), targets = CAST(:t AS jsonb), business_hours_id = :bh, warning_percent = :w WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

function archive_sla_policy(PDO $pdo, int $id): bool
{
    $st = $pdo->prepare('UPDATE sla_policies SET active = false WHERE id = :id AND active');
    $st->execute(['id' => $id]);
    return $st->rowCount() === 1;
}

/** Move a policy one place up or down among the active ones: the two sort_orders swap (equal ones are renumbered first). Answers [before, after] sort_order. */
function move_sla_policy(PDO $pdo, int $id, string $dir): array
{
    $rows = $pdo->query('SELECT id, sort_order FROM sla_policies WHERE active ORDER BY sort_order, id')->fetchAll();
    $orders = array_column($rows, 'sort_order');
    if (count(array_unique($orders)) !== count($orders)) {
        foreach ($rows as $i => $r) {
            $rows[$i]['sort_order'] = ($i + 1) * 10;
            $pdo->prepare('UPDATE sla_policies SET sort_order = :o WHERE id = :id')->execute(['o' => ($i + 1) * 10, 'id' => $r['id']]);
        }
    }
    $idx = array_search($id, array_map('intval', array_column($rows, 'id')), true);
    if ($idx === false) {
        throw new DomainException('Not found.');
    }
    $other = $dir === 'up' ? $idx - 1 : $idx + 1;
    if (!isset($rows[$other])) {
        return [(int) $rows[$idx]['sort_order'], (int) $rows[$idx]['sort_order']];
    }
    $a = (int) $rows[$idx]['sort_order'];
    $b = (int) $rows[$other]['sort_order'];
    $pdo->prepare('UPDATE sla_policies SET sort_order = :o WHERE id = :id')->execute(['o' => $b, 'id' => $id]);
    $pdo->prepare('UPDATE sla_policies SET sort_order = :o WHERE id = :id')->execute(['o' => $a, 'id' => $rows[$other]['id']]);
    return [$a, $b];
}

// ---- schedules -------------------------------------------------------------------------------------------------
function schedule_cast(PDO $pdo, array $s): array
{
    $s['schedule_id'] = (int) $s['schedule_id'];
    $s['is_default'] = (bool) $s['is_default'];
    $raw = is_array($s['intervals']) ? $s['intervals'] : (json_decode((string) $s['intervals'], true) ?: []);
    $by = [];
    foreach ($raw as $iv) {
        $by[(int) $iv['weekday']][] = ['opens' => substr((string) $iv['opens_at'], 0, 5), 'closes' => substr((string) $iv['closes_at'], 0, 5)];
    }
    $s['intervals'] = $by;
    $h = $pdo->prepare('SELECT holiday, name FROM business_hours_holidays WHERE schedule_id = :s ORDER BY holiday');
    $h->execute(['s' => $s['schedule_id']]);
    $s['holidays'] = $h->fetchAll();
    $s['summary'] = weekday_summary($by);
    return $s;
}

function find_schedules(PDO $pdo): array
{
    $rows = $pdo->query('SELECT schedule_id, name, timezone, is_default, intervals FROM mcp_business_hours ORDER BY is_default DESC, lower(name)')->fetchAll();
    return array_map(static fn (array $s): array => schedule_cast($pdo, $s), $rows);
}

function find_schedule(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT schedule_id, name, timezone, is_default, intervals FROM mcp_business_hours WHERE schedule_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : schedule_cast($pdo, $r);
}

/** "Mon–Fri 09:00–17:00 · Sat 10:00–14:00" from weekday => intervals. */
function weekday_summary(array $by): string
{
    if ($by === []) {
        return 'Closed every day';
    }
    $sig = [];
    for ($d = 1; $d <= 7; $d++) {               // Monday first
        $w = $d % 7;
        $sig[$w] = isset($by[$w]) ? implode(', ', array_map(static fn (array $iv): string => $iv['opens'] . '–' . $iv['closes'], $by[$w])) : null;
    }
    $parts = [];
    $run = [];
    $prev = null;
    foreach ($sig as $w => $s) {
        if ($s !== null && $s === $prev) {
            $run[] = $w;
            continue;
        }
        if ($run !== [] && $prev !== null) {
            $parts[] = (count($run) > 1 ? WEEKDAYS[$run[0]] . '–' . WEEKDAYS[end($run)] : WEEKDAYS[$run[0]]) . ' ' . $prev;
        }
        $run = $s === null ? [] : [$w];
        $prev = $s;
    }
    if ($run !== [] && $prev !== null) {
        $parts[] = (count($run) > 1 ? WEEKDAYS[$run[0]] . '–' . WEEKDAYS[end($run)] : WEEKDAYS[$run[0]]) . ' ' . $prev;
    }
    return implode(' · ', $parts);
}

/** The loggable state of a schedule. */
function schedule_state(PDO $pdo, int $id): ?array
{
    $s = find_schedule($pdo, $id);
    if ($s === null) {
        return null;
    }
    $flat = [];
    foreach ($s['intervals'] as $w => $ivs) {
        foreach ($ivs as $iv) {
            $flat[] = ['weekday' => $w, 'opens' => $iv['opens'], 'closes' => $iv['closes']];
        }
    }
    return ['name' => $s['name'], 'timezone' => $s['timezone'], 'is_default' => $s['is_default'], 'intervals' => $flat, 'holidays' => count($s['holidays'])];
}

/** Add ($id null) or change a schedule. $f: name, timezone, is_default; $intervals: [[weekday, opens, closes], …] replaced whole. */
function save_schedule(PDO $pdo, ?int $id, array $f, array $intervals): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM business_hours WHERE lower(name) = lower(:n) AND id IS DISTINCT FROM :id');
    $dupe->execute(['n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A schedule named ' . $f['name'] . ' already exists.');
    }
    if ($f['is_default']) {
        $pdo->prepare('UPDATE business_hours SET is_default = false WHERE is_default AND id IS DISTINCT FROM :id')->execute(['id' => $id]);
    } elseif ($id !== null && (bool) $pdo->query('SELECT is_default FROM business_hours WHERE id = ' . $id)->fetchColumn()) {
        throw new DomainException('Mark another schedule as the default first.');
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO business_hours (name, timezone, is_default) VALUES (:n, :tz, :d) RETURNING id');
        $st->execute(['n' => $f['name'], 'tz' => $f['timezone'], 'd' => $f['is_default'] ? 't' : 'f']);
        $id = (int) $st->fetchColumn();
    } else {
        $pdo->prepare('UPDATE business_hours SET name = :n, timezone = :tz, is_default = :d WHERE id = :id')->execute(['n' => $f['name'], 'tz' => $f['timezone'], 'd' => $f['is_default'] ? 't' : 'f', 'id' => $id]);
    }
    $pdo->prepare('DELETE FROM business_hours_intervals WHERE schedule_id = :id')->execute(['id' => $id]);
    $ins = $pdo->prepare('INSERT INTO business_hours_intervals (schedule_id, weekday, opens_at, closes_at) VALUES (:id, :w, :o, :c)');
    foreach ($intervals as [$w, $o, $c]) {
        $ins->execute(['id' => $id, 'w' => $w, 'o' => $o, 'c' => $c]);
    }
    return $id;
}

function add_holiday(PDO $pdo, int $scheduleId, string $date, ?string $name): void
{
    $pdo->prepare('INSERT INTO business_hours_holidays (schedule_id, holiday, name) VALUES (:s, :d, :n) ON CONFLICT (schedule_id, holiday) DO UPDATE SET name = EXCLUDED.name')->execute(['s' => $scheduleId, 'd' => $date, 'n' => $name]);
}

function remove_holiday(PDO $pdo, int $scheduleId, string $date): bool
{
    $st = $pdo->prepare('DELETE FROM business_hours_holidays WHERE schedule_id = :s AND holiday = :d');
    $st->execute(['s' => $scheduleId, 'd' => $date]);
    return $st->rowCount() === 1;
}

/** "60 minutes from Friday 16:30 lands Monday 09:30" by hd_business_add_minutes(); null when the schedule never opens. $from is local to the schedule's zone. */
function business_preview(PDO $pdo, int $scheduleId, string $from, int $minutes): ?string
{
    $s = find_schedule($pdo, $scheduleId);
    if ($s === null) {
        return null;
    }
    try {
        $start = new DateTimeImmutable($from, new DateTimeZone($s['timezone']));
    } catch (Exception) {
        return null;
    }
    $st = $pdo->prepare('SELECT hd_business_add_minutes(CAST(:f AS timestamptz), :m, :s)');
    $st->execute(['f' => $start->format('c'), 'm' => $minutes, 's' => $scheduleId]);
    $v = $st->fetchColumn();
    if ($v === null || $v === false) {
        return $minutes . ' minutes from ' . $start->format('l H:i') . ' never lands — the schedule has no open hours.';
    }
    $end = (new DateTimeImmutable((string) $v))->setTimezone(new DateTimeZone($s['timezone']));
    return $minutes . ' minutes from ' . $start->format('l H:i') . ' lands ' . $end->format('l H:i') . ($end->format('Y-m-d') === $start->format('Y-m-d') ? '' : ' (' . $end->format('M j') . ')');
}

/** The ticket types, for the conditions select. */
function find_ticket_types(PDO $pdo): array
{
    return $pdo->query('SELECT type_id, key, label FROM mcp_ticket_types WHERE archived_at IS NULL ORDER BY sort_order, label')->fetchAll();
}

/** 480 → "8 h", 90 → "1 h 30 m", 45 → "45 m", 2880 → "2 d". */
function minutes_words(int $m): string
{
    if ($m % 1440 === 0) {
        return ($m / 1440) . ' d';
    }
    if ($m >= 60) {
        return intdiv($m, 60) . ' h' . ($m % 60 ? ' ' . ($m % 60) . ' m' : '');
    }
    return $m . ' m';
}
