<?php
declare(strict_types=1);
/** Action `time_entry_delete` (log `ticket.time_delete`; confirm): one's own entry, or tickets.manage. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$entryId = request_integer('entry') ?? request_integer('entry_id') ?? refuse(422, 'Say which entry.');
$row = $pdo->prepare('SELECT ticket_id, member_id, minutes FROM time_entries WHERE id = :id'); $row->execute(['id' => $entryId]); $e = $row->fetch();
if ($e === false) {
    refuse(404, 'Entry not found.');
}
$t = ticket_load($pdo, (int) $e['ticket_id']);
if ((int) $e['member_id'] !== $me && !has_right('tickets.manage')) {
    refuse(403, 'You may remove your own time entries only.');
}
hd_guard($pdo, static function () use ($pdo, $t, $entryId, $e, $me): void {
    $pdo->beginTransaction();
    delete_time_entry($pdo, $entryId, $me);
    ticket_log($pdo, $t, 'ticket.time_delete', ['after' => ['entry_id' => $entryId, 'minutes' => (int) $e['minutes'], 'member_id' => (int) $e['member_id']]]);
    $pdo->commit();
});
ticket_done($t, 'Removed the time entry', ['entry_id' => $entryId], 'saved');
