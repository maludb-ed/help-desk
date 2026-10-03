<?php
declare(strict_types=1);
/** /requesters/new?organization= and /requesters/{id}/edit (screens `requester-add`, `requester-edit`; tickets.view_queue). A member's name and email are the mirror's. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/present.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('requester');
$cur = null;
if ($id !== null) {
    $cur = find_requester($pdo, $id) ?? refuse(404, 'Requester not found.');
    if ($cur['merged_into_id'] !== null) {
        refuse(404, 'Requester not found.');
    }
}
$screen = $cur === null ? 'requester-add' : 'requester-edit';
log_screen_view($pdo, $screen);
$orgs = find_organizations_for_select($pdo);
if (wants_json()) {
    respond_screen(['requester' => $cur === null ? null : present_requester($cur), 'organizations' => $orgs]);
}
render_screen($cur === null ? 'Add a requester' : 'Change ' . $cur['display_name'], view('requesters/form.php', ['cur' => $cur, 'organizations' => $orgs, 'organization' => request_integer('organization'), 'here' => here_url()]),
    ['activeNav' => 'requesters', 'screen' => $screen, 'entity' => 'requester', 'recordId' => $id === null ? '' : (string) $id]);
