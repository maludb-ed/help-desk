<?php
declare(strict_types=1);
/** Action `status_archive` (log `status.archive`; confirm): settings.manage. A custom status nobody holds; a built-in is renamed, never archived. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('status') ?? request_integer('status_id') ?? refuse(422, 'Say which status.');
$s = find_status($pdo, $id) ?? refuse(404, 'Status not found.');
hd_guard($pdo, static function () use ($pdo, $id, $s): void {
    $pdo->beginTransaction();
    archive_status($pdo, $id);
    log_activity($pdo, 'status.archive', 'ticket_status', $id, ['before' => ['archived' => false], 'after' => ['archived' => true, 'label' => $s['label']]]);
    $pdo->commit();
});
hd_done('Archived ' . $s['label'], $id, hd_land(return_path('/settings/statuses/'), 'archived'), 'statusChanged');
