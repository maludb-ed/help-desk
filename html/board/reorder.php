<?php
declare(strict_types=1);
/** Action `board_reorder` (log `board.reorder`: after.position): work. The card's rank within its column (the others at or after it move down one); 204 for HTMX, {ok} in JSON mode. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$pos = (string) req_val('position');
if ($pos === '' || filter_var($pos, FILTER_VALIDATE_INT) === false || (int) $pos < 0) {
    hd_refuse_fields(['position' => 'The position is a rank from 0.']);
}
$position = (int) $pos;
$view = view_from_param($pdo, (string) req_val('view')) ?? find_view_by_key($pdo, 'team_board');
$field = $view !== null && $view['layout'] === 'board' ? $view['board']['column_field'] : 'status';
hd_guard($pdo, static function () use ($pdo, $t, $position, $field): void {
    $pdo->beginTransaction();
    reorder_card($pdo, $t['ticket_id'], $position, $field);
    ticket_log($pdo, $t, 'board.reorder', ['after' => ['position' => $position]]);
    $pdo->commit();
});
if (is_htmx_request() && !wants_json()) {
    emit_action_status(true, ['did' => 'Ranked ' . $t['number'], 'record_id' => $t['ticket_id']]);
    http_response_code(204);
    exit;
}
hd_done('Ranked ' . $t['number'] . ' at ' . $position, $t['ticket_id'], '/board?view=' . rawurlencode($view['system_key'] ?? (string) ($view['view_id'] ?? 'team_board')), '', ['ticket_id' => $t['ticket_id'], 'position' => $position]);
