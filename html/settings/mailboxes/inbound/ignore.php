<?php
declare(strict_types=1);
/** Action `inbound_ignore` (log `mail.inbound_ignore`): settings.manage. The row ignored with a reason; nothing else changes. */
require_once dirname(__DIR__, 4) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('inbound') ?? request_integer('inbound_id') ?? refuse(422, 'Say which message.');
$row = find_inbound_one($pdo, $id) ?? refuse(404, 'Message not found.');
if (in_array($row['status'], ['processed', 'ignored'], true)) {
    refuse(422, 'That message is ' . $row['status'] . ' already.');
}
$reason = mb_substr(trim((string) (req_val('reason') ?? ($_SERVER['HTTP_HX_PROMPT'] ?? ''))), 0, 200);
$pdo->beginTransaction();
$pdo->prepare("UPDATE inbound_email SET status = 'ignored', error = :e, processed_at = now() WHERE id = :id")->execute(['e' => $reason !== '' ? $reason : 'ignored', 'id' => $id]);
log_activity($pdo, 'mail.inbound_ignore', 'inbound_email', $id, ['before' => ['status' => $row['status']], 'after' => ['status' => 'ignored', 'reason' => $reason]]);
$pdo->commit();
hd_done('Ignored the message', $id, hd_land('/settings/mailboxes/inbound', 'ignored'), 'inboundChanged', ['inbound_id' => $id]);
