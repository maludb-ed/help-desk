<?php
declare(strict_types=1);
/** Action `mailbox_poll_now` (log `mailbox.poll`: handled): settings.manage. The poll run now for one mailbox. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('mailbox') ?? request_integer('mailbox_id') ?? refuse(422, 'Say which mailbox.');
$cur = find_mailbox($pdo, $id) ?? refuse(404, 'Mailbox not found.');
try {
    $n = mail_poll_mailbox($pdo, $cur);
} catch (Throwable $e) {
    refuse(422, 'The poll failed: ' . mb_substr($e->getMessage(), 0, 200));
}
log_activity($pdo, 'mailbox.poll', 'mailbox', $cur['id'], ['after' => ['handled' => $n, 'via' => 'manual']]);
hd_done('Polled ' . $cur['address'] . ': ' . $n . ' message' . ($n === 1 ? '' : 's') . ' handled', $cur['id'], hd_land('/settings/mailboxes/', 'polled'), 'mailboxChanged', ['mailbox_id' => $cur['id'], 'handled' => $n]);
