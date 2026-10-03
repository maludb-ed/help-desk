<?php
declare(strict_types=1);
/** /settings/sla/ — the policies in match order and the schedules (screen `sla-list`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$policies = find_sla_policies($pdo);
$schedules = find_schedules($pdo);
log_screen_view($pdo, 'sla-list');
if (wants_json()) {
    respond_screen(['policies' => array_map('present_policy', $policies), 'schedules' => array_map('present_schedule', $schedules)]);
}
$notices = ['saved' => ['success', 'Saved the policy.'], 'moved' => ['success', 'Moved.'], 'archived' => ['success', 'Archived the policy.'], 'hours' => ['success', 'Saved the schedule.']];
render_screen('SLA and hours', view('sla/list.php', ['policies' => $policies, 'schedules' => $schedules, 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'sla', 'screen' => 'sla-list', 'entity' => 'sla_policy']);
