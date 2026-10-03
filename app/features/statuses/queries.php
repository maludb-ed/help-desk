<?php
declare(strict_types=1);

/**
 * Statuses and the transitions matrix (slice 2). Reads through mcp_ticket_statuses / mcp_status_transitions, writes to the base tables; the
 * database's guard (hd_status_category_guard) has the last word on categories — its P0001 sentence is the refusal.
 */

const STATUS_CATEGORIES = ['new' => 'New', 'open' => 'Open', 'pending' => 'Pending', 'on_hold' => 'On hold', 'solved' => 'Solved', 'closed' => 'Closed'];
const CUSTOM_STATUS_CATEGORIES = ['open' => 'Open', 'pending' => 'Pending', 'on_hold' => 'On hold', 'solved' => 'Solved'];
const STATUS_COLORS = ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'dark'];
const CLOCK_PAUSES_IN = ['pending', 'on_hold', 'solved', 'closed'];
const TRANSITION_REQUIRES = ['resolution_code' => 'a resolution code', 'hold_reason' => 'a hold reason'];
const TRANSITION_MAY_CARRY = ['pending_until' => 'a wake-up time', 'assignee_member_id' => 'an assignee'];
const TRANSITION_RIGHTS = ['tickets.work' => 'Support staff', 'tickets.manage' => 'A lead'];

function status_cast(array $s): array
{
    $s['status_id'] = (int) $s['status_id'];
    $s['sort_order'] = (int) $s['sort_order'];
    foreach (['is_default', 'on_board', 'clock_runs', 'is_system'] as $k) {
        $s[$k] = (bool) $s[$k];
    }
    if (array_key_exists('ticket_count', $s)) {
        $s['ticket_count'] = (int) $s['ticket_count'];
    }
    return $s;
}

/** The statuses in board order, each with the count of tickets holding it. */
function find_statuses(PDO $pdo, bool $includeArchived = false): array
{
    $st = $pdo->prepare('SELECT s.status_id, s.key, s.label, s.category, s.color, s.sort_order, s.is_default, s.on_board, s.clock_runs, s.is_system, s.archived_at,
                                (SELECT count(*) FROM tickets t WHERE t.status_id = s.status_id) AS ticket_count
                           FROM mcp_ticket_statuses s' . ($includeArchived ? '' : ' WHERE s.archived_at IS NULL') . ' ORDER BY s.sort_order, s.status_id');
    $st->execute();
    return array_map('status_cast', $st->fetchAll());
}

function find_status(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS status_id, key, label, category, color, sort_order, is_default, on_board, clock_runs, is_system, archived_at,
                                (SELECT count(*) FROM tickets t WHERE t.status_id = ticket_statuses.id) AS ticket_count FROM ticket_statuses WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : status_cast($r);
}

function status_log_state(array $s): array
{
    return ['key' => $s['key'], 'label' => $s['label'], 'category' => $s['category'], 'color' => $s['color'], 'sort_order' => $s['sort_order'], 'on_board' => $s['on_board'], 'clock_runs' => $s['clock_runs']];
}

/** A key from a label: "Waiting on vendor" → waiting_on_vendor, made unique with a number when taken. */
function derive_key(PDO $pdo, string $table, string $label): string
{
    $k = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? '', '_'));
    if ($k === '' || !ctype_alpha($k[0])) {
        $k = 'k_' . $k;
    }
    $k = substr(rtrim($k, '_'), 0, 36);
    $base = $k;
    for ($i = 2; $i < 100; $i++) {
        $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE key = :k");
        $st->execute(['k' => $k]);
        if ($st->fetchColumn() === false) {
            return $k;
        }
        $k = $base . '_' . $i;
    }
    throw new DomainException('Choose another label.');
}

/** Add ($id null) or change a status. $f: label, category (create), color, sort_order, on_board, clock_runs. The guard refuses a bad category. */
function save_status(PDO $pdo, ?int $id, array $f): int
{
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO ticket_statuses (key, label, category, color, sort_order, on_board, clock_runs) VALUES (:k, :l, :c, :col, :o, :b, :r) RETURNING id');
        $st->execute(['k' => derive_key($pdo, 'ticket_statuses', $f['label']), 'l' => $f['label'], 'c' => $f['category'], 'col' => $f['color'], 'o' => $f['sort_order'], 'b' => $f['on_board'] ? 't' : 'f', 'r' => $f['clock_runs'] ? 't' : 'f']);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE ticket_statuses SET label = :l, category = :c, color = :col, sort_order = :o, on_board = :b, clock_runs = :r WHERE id = :id')
        ->execute(['l' => $f['label'], 'c' => $f['category'], 'col' => $f['color'], 'o' => $f['sort_order'], 'b' => $f['on_board'] ? 't' : 'f', 'r' => $f['clock_runs'] ? 't' : 'f', 'id' => $id]);
    return $id;
}

/** Archive a custom status nobody holds; the refusals are sentences. */
function archive_status(PDO $pdo, int $id): void
{
    $s = find_status($pdo, $id) ?? throw new DomainException('Not found.');
    if ($s['is_system']) {
        throw new DomainException('A built-in status is renamed, never archived.');
    }
    if ($s['archived_at'] !== null) {
        throw new DomainException($s['label'] . ' is already archived.');
    }
    if ($s['ticket_count'] > 0) {
        throw new DomainException($s['ticket_count'] . ' ticket' . ($s['ticket_count'] === 1 ? ' is' : 's are') . ' in ' . $s['label'] . ' — move ' . ($s['ticket_count'] === 1 ? 'it' : 'them') . ' first.');
    }
    $pdo->prepare('UPDATE ticket_statuses SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
}

/** Swap a status's sort_order with its neighbour (direction -1 = up, 1 = down) among the live ones. Answers [before, after]. */
function reorder_status(PDO $pdo, int $id, int $direction): array
{
    $rows = $pdo->query('SELECT id, sort_order FROM ticket_statuses WHERE archived_at IS NULL ORDER BY sort_order, id')->fetchAll();
    if (count(array_unique(array_column($rows, 'sort_order'))) !== count($rows)) {
        foreach ($rows as $i => $r) {
            $rows[$i]['sort_order'] = ($i + 1) * 10;
            $pdo->prepare('UPDATE ticket_statuses SET sort_order = :o WHERE id = :id')->execute(['o' => ($i + 1) * 10, 'id' => $r['id']]);
        }
    }
    $idx = array_search($id, array_map('intval', array_column($rows, 'id')), true);
    if ($idx === false) {
        throw new DomainException('Not found.');
    }
    $other = $idx + ($direction < 0 ? -1 : 1);
    $a = (int) $rows[$idx]['sort_order'];
    if (!isset($rows[$other])) {
        return [$a, $a];
    }
    $b = (int) $rows[$other]['sort_order'];
    $pdo->prepare('UPDATE ticket_statuses SET sort_order = :o WHERE id = :id')->execute(['o' => $b, 'id' => $id]);
    $pdo->prepare('UPDATE ticket_statuses SET sort_order = :o WHERE id = :id')->execute(['o' => $a, 'id' => $rows[$other]['id']]);
    return [$a, $b];
}

// ---- the matrix ------------------------------------------------------------------------------------------------------
/** The 6 × 6 as [from][to] => row|null (requires and may_carry as lists). */
function find_transitions(PDO $pdo): array
{
    $grid = [];
    foreach (array_keys(STATUS_CATEGORIES) as $f) {
        foreach (array_keys(STATUS_CATEGORIES) as $t) {
            $grid[$f][$t] = null;
        }
    }
    foreach ($pdo->query('SELECT from_category, to_category, requires, may_carry, needs_right, label FROM mcp_status_transitions')->fetchAll() as $r) {
        $r['requires'] = pg_text_array((string) $r['requires']);
        $r['may_carry'] = pg_text_array((string) $r['may_carry']);
        $grid[$r['from_category']][$r['to_category']] = $r;
    }
    return $grid;
}

/** The one row of a cell, or null. */
function find_transition(PDO $pdo, string $from, string $to): ?array
{
    $st = $pdo->prepare('SELECT from_category, to_category, requires, may_carry, needs_right, label FROM status_transitions WHERE from_category = :f AND to_category = :t');
    $st->execute(['f' => $from, 't' => $to]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['requires'] = pg_text_array((string) $r['requires']);
    $r['may_carry'] = pg_text_array((string) $r['may_carry']);
    return $r;
}

/** Save a cell: $cell = ['requires' => [], 'may_carry' => [], 'needs_right' => …, 'label' => …], or null to delete the row (the move is then refused). */
function save_transition(PDO $pdo, string $from, string $to, ?array $cell): void
{
    if ($cell === null) {
        $pdo->prepare('DELETE FROM status_transitions WHERE from_category = :f AND to_category = :t')->execute(['f' => $from, 't' => $to]);
        return;
    }
    $pdo->prepare('INSERT INTO status_transitions (from_category, to_category, requires, may_carry, needs_right, label) VALUES (:f, :t, CAST(:r AS text[]), CAST(:m AS text[]), :n, :l)
                   ON CONFLICT (from_category, to_category) DO UPDATE SET requires = EXCLUDED.requires, may_carry = EXCLUDED.may_carry, needs_right = EXCLUDED.needs_right, label = EXCLUDED.label')
        ->execute(['f' => $from, 't' => $to, 'r' => pg_array_literal($cell['requires']), 'm' => pg_array_literal($cell['may_carry']), 'n' => $cell['needs_right'], 'l' => $cell['label']]);
}

/** A cell in words: "Solve — needs a resolution code; may carry an assignee; support staff". */
function transition_words(array $r): string
{
    $parts = [];
    if ($r['requires'] !== []) {
        $parts[] = 'needs ' . implode(' and ', array_map(static fn (string $k): string => TRANSITION_REQUIRES[$k] ?? $k, $r['requires']));
    }
    if ($r['may_carry'] !== []) {
        $parts[] = 'may carry ' . implode(' and ', array_map(static fn (string $k): string => TRANSITION_MAY_CARRY[$k] ?? $k, $r['may_carry']));
    }
    $parts[] = strtolower(TRANSITION_RIGHTS[$r['needs_right']] ?? $r['needs_right']);
    return $r['label'] . ' — ' . implode('; ', $parts);
}
