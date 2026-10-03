<?php
declare(strict_types=1);
/** /settings/mailboxes/new and /settings/mailboxes/{id}/edit (screens `mailbox-add`, `mailbox-edit`; settings.manage): the form never shows a secret's value, only whether its key is set. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
require_human();
$pdo = db();
$id = request_integer('id');
$cur = $id === null ? null : (find_mailbox($pdo, $id) ?? refuse(404, 'Mailbox not found.'));
log_screen_view($pdo, $cur === null ? 'mailbox-add' : 'mailbox-edit');
if (wants_json()) {
    respond_screen(['mailbox' => $cur === null ? null : present_mailbox($cur), 'queues' => array_map(static fn (array $q): array => ['queue_id' => $q['queue_id'], 'name' => $q['name']], find_queues($pdo)), 'webhook_url' => app_url('/channels/malumail/mail')]);
}
render_screen($cur === null ? 'Add a mailbox' : 'Change ' . $cur['address'], view('settings/mailboxes/form.php', ['cur' => $cur, 'queues' => find_queues($pdo), 'host' => rtrim(app_url(''), '/')]),
    ['activeNav' => 'mailboxes', 'screen' => $cur === null ? 'mailbox-add' : 'mailbox-edit', 'entity' => 'mailbox', 'recordId' => (string) ($id ?? '')]);
