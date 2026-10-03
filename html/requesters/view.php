<?php
declare(strict_types=1);
/** /requesters/{id} — one requester (screen `requester-view`): details, organization, addresses (add/remove), notes, tickets (the count until slice 3), Merge into… for tickets.manage. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/present.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('requester') ?? refuse(404, 'Requester not found.');
$r = find_requester($pdo, $id) ?? refuse(404, 'Requester not found.');
log_screen_view($pdo, 'requester-view');
if (wants_json()) {
    respond_screen(['requester' => present_requester($r), 'may' => ['merge' => has_right('tickets.manage')]]);
}
$notices = ['saved' => ['success', 'Saved.'], 'email_added' => ['success', 'Added the address.'], 'email_removed' => ['success', 'Removed the address.'], 'merged' => ['success', 'Merged — everything is here now.']];
render_screen($r['display_name'], view('requesters/view.php', ['r' => $r, 'organizations' => find_organizations_for_select($pdo), 'may' => ['manage' => has_right('tickets.manage')], 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'requesters', 'screen' => 'requester-view', 'entity' => 'requester', 'recordId' => (string) $id]);
