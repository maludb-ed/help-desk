<?php
declare(strict_types=1);
/** Action `organization_archive` (log `organization.archive`; confirm): tickets.manage. Refused while open tickets exist: "Solve or move its N open tickets first." */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/queries.php';
hd_handler_begin();
require_right('tickets.manage');
$pdo = db();
$id = request_integer('organization') ?? request_integer('organization_id') ?? refuse(422, 'Say which company.');
$o = find_organization($pdo, $id) ?? refuse(404, 'Organization not found.');
if (!$o['active']) {
    refuse(422, $o['name'] . ' is already archived.');
}
if ($o['open_count'] > 0) {
    refuse(422, 'Solve or move its ' . $o['open_count'] . ' open ticket' . ($o['open_count'] === 1 ? '' : 's') . ' first.');
}
hd_guard($pdo, static function () use ($pdo, $id, $o): void {
    $pdo->beginTransaction();
    archive_organization($pdo, $id);
    log_activity($pdo, 'organization.archive', 'organization', $id, ['before' => ['active' => true], 'after' => ['active' => false, 'name' => $o['name']]]);
    $pdo->commit();
});
hd_done('Archived ' . $o['name'], $id, hd_land(return_path('/organizations/'), 'archived'), 'organizationChanged');
