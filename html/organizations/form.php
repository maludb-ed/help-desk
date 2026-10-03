<?php
declare(strict_types=1);
/** /organizations/new and /organizations/{id}/edit (screens `organization-add`, `organization-edit`; tickets.view_queue). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/sla/queries.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('organization');
$cur = null;
if ($id !== null) {
    $cur = organization_state($pdo, $id) ?? refuse(404, 'Organization not found.');
    if (!$cur['active'] || find_organization($pdo, $id) === null) {
        refuse(404, 'Organization not found.');
    }
    $cur['organization_id'] = $id;
}
$screen = $cur === null ? 'organization-add' : 'organization-edit';
$policies = find_sla_policies($pdo);
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['organization' => $cur, 'policies' => array_map(static fn (array $p): array => ['policy_id' => $p['policy_id'], 'name' => $p['name']], $policies)]);
}
render_screen($cur === null ? 'Add a customer company' : 'Change ' . $cur['name'], view('organizations/form.php', ['cur' => $cur, 'policies' => $policies, 'here' => here_url()]),
    ['activeNav' => 'organizations', 'screen' => $screen, 'entity' => 'organization', 'recordId' => $id === null ? '' : (string) $id]);
