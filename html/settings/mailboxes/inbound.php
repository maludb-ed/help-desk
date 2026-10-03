<?php
declare(strict_types=1);
/** /settings/mailboxes/inbound?status=&mailbox= — the inbound ledger (screen `inbound-list`; settings.manage): what failed, bounced, was ignored or suppressed; never a body. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
require_human();
$pdo = db();
$filters = ['status' => request_string('status') ?: 'problems', 'mailbox' => request_integer('mailbox') ?? 0];
$rows = find_inbound($pdo, $filters, 200);
log_screen_view($pdo, 'inbound-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'inbound' => array_map('present_inbound', $rows)]);
}
render_screen('Inbound mail', view('settings/mailboxes/inbound.php', ['rows' => $rows, 'filters' => $filters, 'mailboxes' => find_mailboxes($pdo), 'tz' => member_timezone(), 'here' => here_url(),
    'notice' => hd_notice($_GET['notice'] ?? null, ['retried' => ['success', 'Processed again.'], 'ignored' => ['success', 'Ignored.']])]), ['activeNav' => 'mailboxes', 'screen' => 'inbound-list', 'entity' => 'inbound_email']);
