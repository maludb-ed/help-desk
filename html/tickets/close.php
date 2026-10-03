<?php
declare(strict_types=1);
/** Action `ticket_close` (log `ticket.close`: after.reason, via; confirm; pauses for an agent — other): tickets.manage; from solved → closed, or from open/pending/on hold with a resolution code. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_right('tickets.manage');
require_work($t);
$closed = status_by_key($pdo, 'closed') ?? refuse(422, 'No closed status.');
$code = (string) req_val('resolution_code');
$note = (string) req_val('note');
$moved = hd_guard($pdo, static function () use ($pdo, $me, $t, $closed, $code, $note): array {
    $pdo->beginTransaction();
    if ($note !== '') {
        $mid = add_message($pdo, $t['ticket_id'], 'internal_note', $note, ['author_member_id' => $me]);
        ticket_log($pdo, $t, 'ticket.note', ['after' => ['message_id' => $mid, 'kind' => 'internal_note', 'excerpt' => excerpt($note), 'attachments' => 0]]);
    }
    $moved = transition_ticket($pdo, $t['ticket_id'], $closed['status_id'], ['resolution_code' => $code], $me, 'screen');
    ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
    ticket_log($pdo, $t, 'ticket.close', ['after' => ['reason' => $moved['after']['resolution_code'] ?? null, 'via' => 'screen', 'from' => $moved['before']['category']]]);
    notify($pdo, ['requester_id' => $t['requester_id']], 'replied', $t['ticket_id'], $t['number'] . ' is closed', 'Your request ' . $t['number'] . ' "' . $t['subject'] . '" was closed. A new request mentions it if anything remains.', null, 'closed:' . $t['ticket_id']);
    $pdo->commit();
    return $moved;
});
ticket_done($t, 'Closed ' . $t['number'], ['status' => 'closed'], 'saved');
