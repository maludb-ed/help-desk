<?php
declare(strict_types=1);
/** /settings/queues/new and /settings/queues/{id}/edit — add or change a queue (screens `queue-add`, `queue-edit`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/present.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/queries.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('queue');
$cur = null;
if ($id !== null) {
    $cur = queue_state($pdo, $id) ?? refuse(404, 'Queue not found.');
    if ($cur['archived_at'] !== null) {
        refuse(404, 'Queue not found.');
    }
}
$data = ['cur' => $cur, 'departments' => find_live_departments($pdo), 'members' => find_assignable_members($pdo), 'schedules' => find_schedules($pdo), 'policies' => find_sla_policies($pdo), 'here' => here_url()];
$screen = $cur === null ? 'queue-add' : 'queue-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['queue' => $cur === null ? null : queue_log_state($cur) + ['queue_id' => $cur['queue_id']], 'departments' => $data['departments'], 'members' => $data['members'],
        'schedules' => array_map(static fn (array $s): array => ['schedule_id' => $s['schedule_id'], 'name' => $s['name']], $data['schedules']),
        'policies' => array_map(static fn (array $p): array => ['policy_id' => $p['policy_id'], 'name' => $p['name']], $data['policies']), 'assignment_methods' => ASSIGNMENT_METHODS]);
}
render_screen($cur === null ? 'Add a queue' : 'Change ' . $cur['name'], view('queues/form.php', $data), ['activeNav' => 'queues', 'screen' => $screen, 'entity' => 'queue', 'recordId' => $id === null ? '' : (string) $id]);
