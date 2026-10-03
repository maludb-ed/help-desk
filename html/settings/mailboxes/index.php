<?php
declare(strict_types=1);
/** /settings/mailboxes/ — the queues' mailboxes (screen `mailboxes-list`; settings.manage): address, queue, polling, the last poll, inbound problems in 7 days. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
require_human();
$pdo = db();
$rows = find_mailboxes($pdo);
log_screen_view($pdo, 'mailboxes-list');
if (wants_json()) {
    respond_screen(['mailboxes' => array_map('present_mailbox', $rows), 'webhook_url' => app_url('/channels/malumail/mail')]);
}
render_screen('Mailboxes', view('settings/mailboxes/list.php', ['rows' => $rows, 'host' => rtrim(app_url(''), '/'), 'notice' => hd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'archived' => ['success', 'The mailbox is no longer used.'], 'polled' => ['success', 'Polled.']])]),
    ['activeNav' => 'mailboxes', 'screen' => 'mailboxes-list', 'entity' => 'mailbox']);
