<?php
declare(strict_types=1);
/**
 * Action `board_move_card` (log `board.move`: before.column, after.column, after.position, after.view_id — beside the ticket.transition / ticket.set_fields / ticket.assign row the change writes): work.
 * The column's field is the view's: a status column is slice 3's transition_ticket() via 'board' (a required field comes back as the card expanded for HTMX, 422 by field for JSON, the board's
 * ?expand= for a plain form; a forbidden move is hd_transition()'s sentence); priority and type are set_ticket_fields(); assignee and queue are assign_ticket() — never a second write path.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$view = view_from_param($pdo, (string) req_val('view')) ?? find_view_by_key($pdo, 'team_board') ?? refuse(404, 'View not found.');
$b = $view['layout'] === 'board' ? $view['board'] : board_settings([]);
$field = $b['column_field'];
$viewParam = $view['system_key'] ?? (string) $view['view_id'];
$land = '/board?view=' . rawurlencode($viewParam);
$column = (string) req_val('column');
if ($column === '') {
    hd_refuse_fields(['column' => 'Say which column.']);
}
$target = board_resolve_column($pdo, $field, $column) ?? hd_refuse_fields(['column' => "'" . $column . "' is not a column of this board."]);
$pos = (string) req_val('position');
$position = $pos !== '' && filter_var($pos, FILTER_VALIDATE_INT) !== false && (int) $pos >= 0 ? (int) $pos : null;
$rowOf = static function () use ($pdo, $t): array {
    $st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE t.ticket_id = :t');
    $st->execute(['t' => $t['ticket_id']]);
    return ticket_cast($st->fetch());
};
$before = board_value_of($rowOf(), $field);
$boardLog = static function (string $after) use ($pdo, $t, $before, $position, $view): void {
    ticket_log($pdo, $t, 'board.move', ['before' => ['column' => $before], 'after' => ['column' => $after, 'position' => $position, 'view_id' => $view['view_id']]]);
};
$statusKey = $t['status'];
$did = '';
switch ($field) {
    case 'status':
        $to = $target['status'];
        $st = $pdo->prepare('SELECT * FROM hd_transition(:a, :b)');
        $st->execute(['a' => $t['status_id'], 'b' => $to['status_id']]);
        $rule = $st->fetch();
        $carry = ['resolution_code' => (string) req_val('resolution_code'), 'hold_reason' => (string) req_val('hold_reason'), 'pending_until' => (string) req_val('pending_until')];
        $missing = $rule === false ? [] : array_values(array_filter(pg_text_array((string) $rule['requires']), static fn (string $k): bool => ($carry[$k] ?? '') === ''));
        if ($missing !== [] && (int) $to['status_id'] !== $t['status_id'] && has_right($rule['needs_right'])) {
            $sentences = ['resolution_code' => ($to['category'] === 'closed' ? 'Closing' : 'Solving') . ' needs a resolution code.', 'hold_reason' => 'Putting a ticket on hold needs a reason.', 'pending_until' => 'Say when to wake it up.'];
            $fields = array_combine($missing, array_map(static fn (string $k): string => $sentences[$k] ?? ('Give ' . $k . '.'), $missing));
            if (wants_json()) {
                hd_refuse_fields($fields);
            }
            if (is_htmx_request()) {                                                                  // the card, expanded for the field
                emit_action_status(false, ['errors' => array_values($fields), 'fields' => $fields]);
                $query = ['view' => $viewParam];
                echo view('board/partials/card.php', ['t' => $rowOf(), 'field' => $field, 'view' => $view, 'moves' => board_move_table($pdo, 'status', []), 'query' => $query, 'tz' => member_timezone(), 'here' => $land,
                    'expand' => ['ticket' => $t['ticket_id'], 'to' => $to, 'requires' => $missing, 'label' => (string) $rule['label']]]);
                exit;
            }
            header('Location: ' . $land . '&expand=' . $t['ticket_id'] . '&to=' . rawurlencode($to['key']), true, 302);
            exit;
        }
        $moved = hd_guard($pdo, static function () use ($pdo, $me, $t, $to, $carry, $position, $boardLog): array {
            $pdo->beginTransaction();
            $moved = transition_ticket($pdo, $t['ticket_id'], $to['status_id'], $carry, $me, 'board');
            ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
            if ($position !== null) {
                reorder_card($pdo, $t['ticket_id'], $position, 'status');
            }
            $boardLog($to['key']);
            if (in_array($to['category'], ['solved', 'closed'], true)) {
                notify($pdo, ['requester_id' => $t['requester_id']], 'replied', $t['ticket_id'], $t['number'] . ' is ' . strtolower($to['label']), 'Your request ' . $t['number'] . ' "' . $t['subject'] . '" was marked ' . strtolower($to['label']) . '. If it is not, reopen it or reply. ' . requester_link_words($t, null), null, 'solved:' . $t['ticket_id'] . ':' . time());
            }
            $pdo->commit();
            return $moved;
        });
        $statusKey = $to['key'];
        $did = $t['number'] . ': ' . $moved['label'] . ' — now ' . $to['label'];
        break;
    case 'priority':
    case 'type':
        $fields = $field === 'priority' ? ['priority' => $target['priority']] : ['type_id' => $target['type']];
        hd_guard($pdo, static function () use ($pdo, $me, $t, $fields, $position, $field, $column, $boardLog): void {
            $pdo->beginTransaction();
            $r = set_ticket_fields($pdo, $t['ticket_id'], $fields, $me);
            if ($r['after'] !== []) {
                ticket_log($pdo, $t, 'ticket.set_fields', ['before' => $r['before'], 'after' => $r['after']]);
            }
            if ($position !== null) {
                reorder_card($pdo, $t['ticket_id'], $position, $field);
            }
            $boardLog($column);
            $pdo->commit();
        });
        $did = $t['number'] . ': ' . $field . ' set to ' . $column;
        break;
    case 'assignee':
    case 'queue':
        $newQueue = $field === 'queue' ? $target['queue'] : null;
        if ($newQueue !== null && !member_in_queue($pdo, $me, $newQueue) && !has_right('tickets.manage')) {
            refuse(403, 'You do not work that queue.');
        }
        $r = hd_guard($pdo, static function () use ($pdo, $me, $t, $field, $target, $newQueue, $position, $column, $boardLog): array {
            $pdo->beginTransaction();
            $r = $field === 'assignee' ? assign_ticket($pdo, $t['ticket_id'], $target['assignee'], null, $me, 'board') : assign_ticket($pdo, $t['ticket_id'], null, $newQueue, $me, 'board', false);
            if ($r['changed']) {
                ticket_log($pdo, $t, 'ticket.assign', ['before' => $r['before'], 'after' => $r['after']]);
                if ($r['after']['assignee_member_id'] !== null && $r['after']['assignee_member_id'] !== $me && $r['after']['assignee_kind'] !== 'agent' && $r['after']['assignee_member_id'] !== $r['before']['assignee_member_id']) {
                    notify($pdo, ['member_id' => $r['after']['assignee_member_id']], 'assigned', $t['ticket_id'], $t['number'] . ' is yours: ' . $t['subject'], $t['number'] . ' "' . $t['subject'] . '" was assigned to you. See it here: ' . app_url('/tickets/' . $t['ticket_id']), null, 'assigned:' . $t['ticket_id'] . ':' . $r['after']['assignee_member_id'] . ':' . time());
                }
            }
            if ($position !== null) {
                reorder_card($pdo, $t['ticket_id'], $position, $field);
            }
            $boardLog($column);
            $pdo->commit();
            return $r;
        });
        if ($r['changed'] && $r['after']['assignee_kind'] === 'agent' && $r['after']['assignee_member_id'] !== $r['before']['assignee_member_id']) {
            dispatch_to_agent($pdo, $t['ticket_id'], $r['after']['assignee_member_id'], 'assigned', is_action_authed() && current_agent_run_id() !== null ? null : $me);
        }
        $did = $t['number'] . ': ' . ($field === 'assignee' ? 'assigned to ' . ($r['after']['assignee_name'] ?? 'nobody') : 'moved to ' . $r['after']['queue_name']);
        break;
}
if (is_htmx_request() && !wants_json()) {
    emit_action_status(true, ['did' => $did, 'record_id' => $t['ticket_id'], 'refresh' => 'ticketChanged']);
    hx_trigger('ticketChanged');
    $fresh = $rowOf();
    $boardNow = board_columns($pdo, $view, [], $me);
    echo view('board/partials/card.php', ['t' => $fresh, 'field' => $field, 'view' => $view, 'moves' => board_move_table($pdo, $field, $boardNow['columns']), 'expand' => null, 'query' => ['view' => $viewParam], 'tz' => member_timezone(), 'here' => $land]);
    foreach ($boardNow['columns'] as $c) {
        if (in_array($c['key'], [$before, board_value_of($fresh, $field)], true)) {
            $over = $c['wip'] !== null && $c['count'] > $c['wip'];
            echo '<span class="fs-11 ms-auto ' . ($over ? 'board-wip-over' : 'text-muted') . '" id="board-column-' . e($c['key']) . '-count" hx-swap-oob="true">' . (int) $c['count'] . ($c['wip'] !== null ? ' / ' . (int) $c['wip'] : '') . '</span>';
        }
    }
    exit;
}
hd_done($did, $t['ticket_id'], hd_land($land, 'moved'), 'ticketChanged', ['ticket_id' => $t['ticket_id'], 'number' => $t['number'], 'status' => $statusKey, 'column' => board_value_of($rowOf(), $field)]);
