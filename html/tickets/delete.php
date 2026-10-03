<?php
declare(strict_types=1);
/** Action `ticket_delete` (log `ticket.delete` before the delete: after.number, after.subject; confirm; deletion): the admin. The files go, the row cascades. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_admin();
$pdo = db();
$t = ticket_from_request($pdo);
hd_guard($pdo, static function () use ($pdo, $t): void {
    $pdo->beginTransaction();
    ticket_log($pdo, $t, 'ticket.delete', ['after' => ['number' => $t['number'], 'subject' => $t['subject'], 'reason' => 'deleted by the admin', 'via' => 'screen']]);
    remove_ticket_files($t['ticket_id']);
    $pdo->prepare('DELETE FROM tickets WHERE id = :id')->execute(['id' => $t['ticket_id']]);
    $pdo->commit();
});
hd_done('Deleted ' . $t['number'], $t['ticket_id'], hd_land('/inbox', 'deleted'), 'ticketChanged', ['number' => $t['number']]);
