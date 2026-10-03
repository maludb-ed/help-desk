<?php
declare(strict_types=1);
/** /settings/sla/new and /settings/sla/{id}/edit (screens `sla-policy-add`, `sla-policy-edit`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/present.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/requesters/queries.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('policy');
$cur = $id === null ? null : (find_sla_policy($pdo, $id) ?? refuse(404, 'Policy not found.'));
if ($cur !== null && !$cur['active']) {
    refuse(404, 'Policy not found.');
}
$screen = $cur === null ? 'sla-policy-add' : 'sla-policy-edit';
$data = ['cur' => $cur, 'organizations' => find_organizations_for_select($pdo), 'queues' => find_queues($pdo), 'types' => find_ticket_types($pdo), 'schedules' => find_schedules($pdo), 'here' => here_url()];
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['policy' => $cur === null ? null : present_policy($cur), 'organizations' => $data['organizations'], 'queues' => array_map(static fn (array $q): array => ['queue_id' => $q['queue_id'], 'name' => $q['name']], $data['queues']),
        'types' => $data['types'], 'schedules' => array_map(static fn (array $s): array => ['schedule_id' => $s['schedule_id'], 'name' => $s['name']], $data['schedules']), 'priorities' => array_keys(PRIORITIES), 'metrics' => array_keys(SLA_METRICS)]);
}
render_screen($cur === null ? 'Add a policy' : 'Change ' . $cur['name'], view('sla/form.php', $data), ['activeNav' => 'sla', 'screen' => $screen, 'entity' => 'sla_policy', 'recordId' => $id === null ? '' : (string) $id]);
