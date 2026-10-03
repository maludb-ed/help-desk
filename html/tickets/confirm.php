<?php
declare(strict_types=1);
/** Action `ticket_confirm` (log `ticket.confirm`): the requester (or a cc) on a ticket in pending or open after support's reply → solved with code resolved, via requester. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
if (!is_own_ticket($pdo, $t)) {
    refuse(403, 'Only the requester confirms a ticket.');
}
if (!in_array($t['status_category'], ['pending', 'open', 'on_hold', 'new'], true)) {
    refuse(422, $t['number'] . ' is not waiting for your confirmation.');
}
$solved = status_by_key($pdo, 'solved') ?? refuse(422, 'No solved status.');
hd_guard($pdo, static function () use ($pdo, $me, $t, $solved): void {
    $pdo->beginTransaction();
    $moved = transition_ticket($pdo, $t['ticket_id'], $solved['status_id'], ['resolution_code' => 'resolved'], $me, 'requester', false);
    ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
    ticket_log($pdo, $t, 'ticket.confirm', ['after' => ['via' => 'requester']]);
    $pdo->commit();
});
ticket_done($t, 'Thank you — ' . $t['number'] . ' is resolved', ['status' => 'solved'], 'saved');
