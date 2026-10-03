<?php
declare(strict_types=1);

/** The board (slice 4): a view as columns of cards, grouped by one field, lanes by another; every card through the translator, capped. The shape `board_snapshot` (Phase 4) reuses. */
require_once __DIR__ . '/queries.php';

/** The cards of a view for the caller (mcp_tickets through the translator): no closed, solved only within solved_days when the columns are statuses; board order. */
function board_cards(PDO $pdo, array $view, array $extraFilters, int $memberId, int $cap = 500): array
{
    $b = $view['board'];
    $filters = array_merge($view['filters'], $extraFilters);
    [$where, $args] = view_filters_sql($filters, $memberId);
    if ($b['column_field'] === 'status') {
        $where .= " AND t.status_category <> 'closed' AND (t.status_category <> 'solved' OR t.solved_at IS NULL OR t.solved_at >= now() - make_interval(days => :solved_days))";
        $args['solved_days'] = $b['solved_days'];
    }
    $st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE ' . $where . ' ORDER BY t.board_position ASC NULLS LAST, t.last_activity_at DESC, t.ticket_id DESC LIMIT ' . (int) $cap);
    $st->execute($args);
    return array_map('ticket_cast', $st->fetchAll());
}

/** A card's value for a column field or a swimlane. */
function board_value_of(array $t, string $field): string
{
    return match ($field) {
        'status' => (string) $t['status'],
        'priority' => (string) $t['priority'],
        'assignee' => $t['assignee_member_id'] === null ? 'none' : (string) $t['assignee_member_id'],
        'queue' => (string) $t['queue_id'],
        'type' => $t['type_id'] === null ? 'none' : (string) $t['type_id'],
        'organization' => $t['organization_id'] === null ? 'none' : (string) $t['organization_id'],
        default => '',
    };
}

/** The columns a field gives: [{key, label, color}], in order — statuses on the board (closed never), the four priorities, the assignees holding a card plus Unassigned, the queues held, the types. */
function board_column_defs(PDO $pdo, string $field, array $cards): array
{
    switch ($field) {
        case 'status':
            $rows = $pdo->query("SELECT key, label, color FROM mcp_ticket_statuses WHERE archived_at IS NULL AND on_board AND category <> 'closed' ORDER BY sort_order, status_id")->fetchAll();
            return array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label'], 'color' => $s['color']], $rows);
        case 'priority':
            return array_map(static fn (string $p): array => ['key' => $p, 'label' => ucfirst($p), 'color' => PRIORITY_COLORS[$p]], VIEW_PRIORITIES);
        case 'assignee':
            $out = [['key' => 'none', 'label' => 'Unassigned', 'color' => 'secondary']];
            $seen = [];
            foreach ($cards as $t) {
                if ($t['assignee_member_id'] !== null && !isset($seen[$t['assignee_member_id']])) {
                    $seen[$t['assignee_member_id']] = true;
                    $out[] = ['key' => (string) $t['assignee_member_id'], 'label' => $t['assignee_name'] . ($t['assignee_kind'] === 'agent' ? ' (agent)' : ''), 'color' => 'primary'];
                }
            }
            usort($out, static fn (array $a, array $b): int => ($a['key'] === 'none' ? 0 : 1) <=> ($b['key'] === 'none' ? 0 : 1) ?: strcasecmp($a['label'], $b['label']));
            return $out;
        case 'queue':
            return array_map(static fn (array $q): array => ['key' => (string) $q['queue_id'], 'label' => $q['name'], 'color' => 'primary'], find_queues($pdo, ['mine' => !has_right('tickets.view_all')]));
        case 'type':
            return array_map(static fn (array $t): array => ['key' => (string) $t['type_id'], 'label' => $t['name'], 'color' => 'info'], find_types($pdo));
    }
    return [];
}

/** The lanes a swimlane field gives, from the cards present: [{key, label}]. */
function board_lane_defs(string $field, array $cards): array
{
    $out = [];
    foreach ($cards as $t) {
        $k = board_value_of($t, $field);
        if (isset($out[$k])) {
            continue;
        }
        $out[$k] = ['key' => $k, 'label' => match ($field) {
            'priority' => ucfirst($k),
            'queue' => (string) $t['queue_name'],
            'assignee' => $k === 'none' ? 'Unassigned' : (string) $t['assignee_name'],
            'type' => $k === 'none' ? 'No type' : (string) $t['type'],
            'organization' => $k === 'none' ? 'No organization' : (string) $t['organization_name'],
            default => $k,
        }];
    }
    if ($field === 'priority') {
        uksort($out, static fn (string $a, string $b): int => array_search($b, VIEW_PRIORITIES, true) <=> array_search($a, VIEW_PRIORITIES, true));
    } else {
        uasort($out, static fn (array $a, array $b): int => ($a['key'] === 'none' ? 1 : 0) <=> ($b['key'] === 'none' ? 1 : 0) ?: strcasecmp($a['label'], $b['label']));
    }
    return array_values($out);
}

/**
 * The board: ['columns' => [{key, label, color, count, wip, cards}], 'lanes' => null | [{key, label, columns:[…]}], 'cards' => n, 'capped' => bool].
 * A card whose column is not on the board (a status off it) is left out.
 */
function board_columns(PDO $pdo, array $view, array $extraFilters, int $memberId): array
{
    $b = $view['board'];
    $cards = board_cards($pdo, $view, $extraFilters, $memberId, 501);
    $capped = count($cards) > 500;
    $cards = array_slice($cards, 0, 500);
    $defs = board_column_defs($pdo, $b['column_field'], $cards);
    $group = static function (array $cards) use ($defs, $b): array {
        $cols = [];
        foreach ($defs as $d) {
            $cols[$d['key']] = $d + ['count' => 0, 'wip' => $b['wip'][$d['key']] ?? null, 'cards' => []];
        }
        foreach ($cards as $t) {
            $k = board_value_of($t, $b['column_field']);
            if (isset($cols[$k])) {
                $cols[$k]['cards'][] = $t;
                $cols[$k]['count']++;
            }
        }
        return array_values($cols);
    };
    $out = ['column_field' => $b['column_field'], 'swimlane' => $b['swimlane'], 'cards' => count($cards), 'capped' => $capped, 'columns' => $group($cards), 'lanes' => null];
    if ($b['swimlane'] !== null) {
        $lanes = [];
        foreach (board_lane_defs($b['swimlane'], $cards) as $lane) {
            $lanes[] = $lane + ['columns' => $group(array_values(array_filter($cards, static fn (array $t): bool => board_value_of($t, $b['swimlane']) === $lane['key'])))];
        }
        $out['lanes'] = $lanes;
    }
    return $out;
}

/** Rank a card within its column: the others at or after the rank move down one. */
function reorder_card(PDO $pdo, int $ticketId, int $position, string $field = 'status'): void
{
    $same = match ($field) {
        'priority' => 'priority = (SELECT priority FROM tickets WHERE id = :t)',
        'assignee' => 'assignee_member_id IS NOT DISTINCT FROM (SELECT assignee_member_id FROM tickets WHERE id = :t)',
        'queue' => 'queue_id = (SELECT queue_id FROM tickets WHERE id = :t)',
        'type' => 'type_id IS NOT DISTINCT FROM (SELECT type_id FROM tickets WHERE id = :t)',
        default => 'status_id = (SELECT status_id FROM tickets WHERE id = :t)',
    };
    $pdo->prepare("UPDATE tickets SET board_position = board_position + 1 WHERE id <> :t AND board_position >= :p AND $same")->execute(['t' => $ticketId, 'p' => $position]);
    $pdo->prepare('UPDATE tickets SET board_position = :p WHERE id = :t')->execute(['t' => $ticketId, 'p' => $position]);
}

/** The move table for the cards: from_status_id → [{status_id, key, label, category, color, move_label, requires, needs_right}] by hd_transition() over every pair of live statuses (one call a pair, once a page); plus 'columns' for the other fields. */
function board_move_table(PDO $pdo, string $field, array $columns): array
{
    $out = ['columns' => array_map(static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label']], $columns)];
    if ($field !== 'status') {
        return $out;
    }
    $statuses = $pdo->query('SELECT status_id, key, label, category, color FROM mcp_ticket_statuses WHERE archived_at IS NULL ORDER BY sort_order, status_id')->fetchAll();
    $st = $pdo->prepare('SELECT * FROM hd_transition(:a, :b)');
    foreach ($statuses as $a) {
        $out[(int) $a['status_id']] = [];
        foreach ($statuses as $b) {
            if ($a['status_id'] === $b['status_id']) {
                continue;
            }
            $st->execute(['a' => (int) $a['status_id'], 'b' => (int) $b['status_id']]);
            $r = $st->fetch();
            if ($r === false) {
                continue;
            }
            $out[(int) $a['status_id']][] = ['status_id' => (int) $b['status_id'], 'key' => $b['key'], 'label' => $b['label'], 'category' => $b['category'], 'color' => $b['color'], 'move_label' => $r['label'],
                                            'requires' => pg_text_array((string) $r['requires']), 'needs_right' => $r['needs_right']];
        }
    }
    return $out;
}

/** The moves hd_transition() allows from a ticket's status, for the caller: [{status_id, key, label, move_label, requires, may}]. */
function allowed_moves(PDO $pdo, int $ticketId): array
{
    return ticket_allowed_moves($pdo, $ticketId);
}

/** A column key of a field resolved to what the writer needs: ['status' => row] | ['priority' => p] | ['assignee' => ?id] | ['queue' => id] | ['type' => ?id]; null when it is no column. */
function board_resolve_column(PDO $pdo, string $field, string $key): ?array
{
    switch ($field) {
        case 'status':
            $s = filter_var($key, FILTER_VALIDATE_INT) !== false ? status_by_id($pdo, (int) $key) : status_by_key($pdo, $key);
            return $s === null ? null : ['status' => $s];
        case 'priority':
            return in_array($key, VIEW_PRIORITIES, true) ? ['priority' => $key] : null;
        case 'assignee':
            if ($key === 'none') {
                return ['assignee' => null];
            }
            return filter_var($key, FILTER_VALIDATE_INT) !== false ? ['assignee' => (int) $key] : null;
        case 'queue':
            return filter_var($key, FILTER_VALIDATE_INT) !== false ? ['queue' => (int) $key] : null;
        case 'type':
            if ($key === 'none') {
                return ['type' => null];
            }
            return filter_var($key, FILTER_VALIDATE_INT) !== false ? ['type' => (int) $key] : null;
    }
    return null;
}
