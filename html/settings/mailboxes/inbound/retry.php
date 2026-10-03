<?php
declare(strict_types=1);
/** Action `inbound_retry` (log `mail.inbound_retry`): settings.manage. A failed message processed again through mail_inbound_uid(). */
require_once dirname(__DIR__, 4) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('inbound') ?? request_integer('inbound_id') ?? refuse(422, 'Say which message.');
$row = find_inbound_one($pdo, $id) ?? refuse(404, 'Message not found.');
if ($row['status'] !== 'failed') {
    refuse(422, 'Only a failed message is retried.');
}
$mailbox = find_mailbox($pdo, $row['mailbox_id']) ?? refuse(422, 'Its mailbox is gone.');
[$status, $ticketId, $mid] = mail_inbound_uid($pdo, $mailbox, (int) $row['provider_uid']);
log_activity($pdo, 'mail.inbound_retry', 'inbound_email', $id, ['ticket_id' => $ticketId, 'after' => ['outcome' => $status, 'ticket_id' => $ticketId]]);
if ($status === 'failed') {
    refuse(422, 'Still failing: ' . mb_substr((string) (find_inbound_one($pdo, $id)['error'] ?? ''), 0, 200));
}
hd_done('Processed the message again: ' . $status, $id, hd_land('/settings/mailboxes/inbound', 'retried'), 'inboundChanged', ['inbound_id' => $id, 'outcome' => $status, 'ticket_id' => $ticketId]);
