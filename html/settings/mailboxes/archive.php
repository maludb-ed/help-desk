<?php
declare(strict_types=1);
/** Action `mailbox_archive` (log `mailbox.archive`; an agent pauses — other): settings.manage. active = false; its mail is no longer read. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('mailbox') ?? request_integer('mailbox_id') ?? refuse(422, 'Say which mailbox.');
$cur = find_mailbox($pdo, $id) ?? refuse(404, 'Mailbox not found.');
if (!$cur['active']) {
    refuse(422, 'That mailbox is not in use already.');
}
hd_guard($pdo, static function () use ($pdo, $cur, $me): void {
    $pdo->beginTransaction();
    archive_mailbox($pdo, $cur['id'], $me);
    log_activity($pdo, 'mailbox.archive', 'mailbox', $cur['id'], ['before' => mailbox_log_state($cur), 'after' => ['active' => false]]);
    $pdo->commit();
});
hd_done('Stopped using the mailbox ' . $cur['address'], $cur['id'], hd_land('/settings/mailboxes/', 'archived'), 'mailboxChanged', ['mailbox_id' => $cur['id']]);
