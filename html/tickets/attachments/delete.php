<?php
declare(strict_types=1);
/** Action `attachment_delete` (log `ticket.attachment_delete`; confirm; deletion): tickets.manage. The file leaves storage. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('tickets.manage');
$pdo = db();
$id = request_integer('attachment') ?? request_integer('attachment_id') ?? refuse(422, 'Say which attachment.');
$ticketId = (int) (one_value($pdo, 'SELECT ticket_id FROM attachments WHERE id = :id', ['id' => $id]) ?: 0);
if ($ticketId < 1) {
    refuse(404, 'Attachment not found.');
}
$t = ticket_load($pdo, $ticketId);
require_work($t);
$r = hd_guard($pdo, static function () use ($pdo, $t, $id): array {
    $pdo->beginTransaction();
    $r = delete_attachment($pdo, $id);
    ticket_log($pdo, $t, 'ticket.attachment_delete', ['after' => ['attachment_id' => $id, 'filename' => $r['filename']]]);
    $pdo->commit();
    return $r;
});
ticket_done($t, 'Removed ' . $r['filename'] . ' from ' . $t['number'], ['attachment_id' => $id], 'saved');
