<?php
declare(strict_types=1);
/** Action `attachment_add` (log `ticket.attach`: filename, byte_size, mime_type): work, or the ticket's requester; file (multipart), message (attach to an existing message of this ticket). */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
if (!can_work_ticket($t['ticket_id']) && !is_own_ticket($pdo, $t)) {
    refuse(403, 'You are not in this ticket\'s queue.');
}
$messageId = request_integer('message') ?? request_integer('message_id');
if ($messageId !== null && (int) (one_value($pdo, 'SELECT ticket_id FROM messages WHERE id = :m', ['m' => $messageId]) ?: 0) !== $t['ticket_id']) {
    refuse(404, 'Message not found.');
}
$pendingCount = (int) one_value($pdo, 'SELECT count(*) FROM attachments WHERE ticket_id = :t AND message_id IS NULL AND uploaded_by_member_id = :m', ['t' => $t['ticket_id'], 'm' => $me]);
if ($pendingCount >= ATTACHMENT_MAX_FILES) {
    refuse(422, 'At most ' . ATTACHMENT_MAX_FILES . ' files per message.');
}
$file = $_FILES['file'] ?? null;
if (!is_array($file)) {
    hd_refuse_fields(['file' => 'Choose a file.']);
}
$id = hd_guard($pdo, static function () use ($pdo, $me, $t, $messageId, $file): int {
    $pdo->beginTransaction();
    $id = store_attachment($pdo, $t['ticket_id'], $messageId, $file, $me);
    $a = $pdo->prepare('SELECT filename, byte_size, mime_type FROM attachments WHERE id = :id'); $a->execute(['id' => $id]); $row = $a->fetch();
    ticket_log($pdo, $t, 'ticket.attach', ['after' => ['attachment_id' => $id, 'message_id' => $messageId, 'filename' => $row['filename'], 'byte_size' => (int) $row['byte_size'], 'mime_type' => $row['mime_type']]]);
    $pdo->commit();
    return $id;
});
ticket_done($t, 'Attached ' . $file['name'] . ' to ' . $t['number'], ['attachment_id' => $id, 'record_id' => $id], 'attached');
