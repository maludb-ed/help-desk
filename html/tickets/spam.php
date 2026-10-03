<?php
declare(strict_types=1);
/** Action `ticket_spam` (log `ticket.spam`; confirm; deletion category for an agent): tickets.manage; solved with code spam and tagged spam (D: mark only in v1). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_right('tickets.manage');
require_work($t);
$solved = status_by_key($pdo, 'solved') ?? refuse(422, 'No solved status.');
hd_guard($pdo, static function () use ($pdo, $me, $t, $solved): void {
    $pdo->beginTransaction();
    if ($t['status_category'] !== 'solved' && $t['status_category'] !== 'closed') {
        $moved = transition_ticket($pdo, $t['ticket_id'], $solved['status_id'], ['resolution_code' => 'spam'], $me, 'screen');
        ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
    } else {
        $pdo->prepare("UPDATE tickets SET resolution_code = 'spam' WHERE id = :id")->execute(['id' => $t['ticket_id']]);
    }
    set_ticket_tags($pdo, $t['ticket_id'], ['spam'], []);
    ticket_log($pdo, $t, 'ticket.spam', ['after' => ['reason' => 'spam', 'via' => 'screen']]);
    $pdo->commit();
});
ticket_done($t, 'Marked ' . $t['number'] . ' as spam', ['status' => 'solved', 'resolution_code' => 'spam'], 'saved');
