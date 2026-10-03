<?php
declare(strict_types=1);
/** Action `sla_policy_archive` (log `sla_policy.archive`; confirm): settings.manage. Standard cannot be archived: "Standard applies when nothing else matches." */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('policy') ?? request_integer('policy_id') ?? refuse(422, 'Say which policy.');
$p = policy_state($pdo, $id) ?? refuse(404, 'Policy not found.');
if ($p['name'] === 'Standard') {
    refuse(422, 'Standard applies when nothing else matches.');
}
if (!$p['active']) {
    refuse(422, $p['name'] . ' is already archived.');
}
hd_guard($pdo, static function () use ($pdo, $id, $p): void {
    $pdo->beginTransaction();
    archive_sla_policy($pdo, $id);
    log_activity($pdo, 'sla_policy.archive', 'sla_policy', $id, ['before' => ['active' => true], 'after' => ['active' => false, 'name' => $p['name']]]);
    $pdo->commit();
});
hd_done('Archived ' . $p['name'], $id, hd_land(return_path('/settings/sla/'), 'archived'), 'policyChanged');
