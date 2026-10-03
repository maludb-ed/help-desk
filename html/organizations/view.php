<?php
declare(strict_types=1);
/** /organizations/{id} — one organization (screen `organization-view`): domains (add/remove inline), people, tickets (the count until slice 3), SLA, settings. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/present.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/present.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('organization') ?? refuse(404, 'Organization not found.');
$o = find_organization($pdo, $id) ?? refuse(404, 'Organization not found.');
if (!$o['active']) {
    refuse(404, 'Organization not found.');
}
$people = find_organization_requesters($pdo, $id);
log_screen_view($pdo, 'organization-view');
if (wants_json()) {
    respond_screen(['organization' => present_organization($o), 'people' => array_map('present_requester_brief', $people), 'may' => ['archive' => has_right('tickets.manage')]]);
}
$notices = ['saved' => ['success', 'Saved the company.'], 'domains' => ['success', 'Saved the domains.']];
render_screen($o['name'], view('organizations/view.php', ['o' => $o, 'people' => $people, 'may' => ['manage' => has_right('tickets.manage')], 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'organizations', 'screen' => 'organization-view', 'entity' => 'organization', 'recordId' => (string) $id]);
