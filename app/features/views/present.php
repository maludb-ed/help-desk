<?php
declare(strict_types=1);

/** The JSON shapes of a view and a board card (the board's JSON and, in Phase 4, the `board_snapshot` tool share them). */

function present_view(array $v): array
{
    return ['view_id' => $v['view_id'], 'name' => $v['name'], 'scope' => $v['scope'], 'owner_member_id' => $v['owner_member_id'], 'queue_id' => $v['queue_id'], 'queue_name' => $v['queue_name'] ?? null,
            'filters' => (object) $v['filters'], 'sort_by' => $v['sort_by'], 'sort_dir' => $v['sort_dir'], 'columns' => array_values($v['columns']), 'layout' => $v['layout'],
            'board' => ['column_field' => $v['board']['column_field'], 'swimlane' => $v['board']['swimlane'], 'wip' => (object) $v['board']['wip'], 'solved_days' => $v['board']['solved_days']],
            'is_system' => $v['is_system'], 'system_key' => $v['system_key'], 'may_change' => view_may_change($v)] + (isset($v['count']) ? ['count' => $v['count']] : []);
}

/** A board card: the brief ticket plus its column and rank. */
function present_board_card(array $t, string $field): array
{
    return present_ticket_brief($t) + ['column' => board_value_of($t, $field), 'board_position' => $t['board_position'], 'age_words' => age_words($t['created_at'])];
}

function present_board(array $board): array
{
    $col = static fn (array $c): array => ['key' => $c['key'], 'label' => $c['label'], 'color' => $c['color'], 'count' => $c['count'], 'wip' => $c['wip'], 'cards' => array_map(static fn (array $t): array => present_board_card($t, $board['column_field']), $c['cards'])];
    return ['column_field' => $board['column_field'], 'swimlane' => $board['swimlane'], 'cards' => $board['cards'], 'capped' => $board['capped'], 'columns' => array_map($col, $board['columns']),
            'lanes' => $board['lanes'] === null ? null : array_map(static fn (array $l): array => ['key' => $l['key'], 'label' => $l['label'], 'columns' => array_map($col, $l['columns'])], $board['lanes'])];
}

/** "3d", "5h", "20m" since a time. */
function age_words(?string $ts): string
{
    if ($ts === null) {
        return '';
    }
    $s = max(0, time() - strtotime($ts));
    if ($s < 3600) {
        return max(1, intdiv($s, 60)) . 'm';
    }
    if ($s < 86400) {
        return intdiv($s, 3600) . 'h';
    }
    return intdiv($s, 86400) . 'd';
}
