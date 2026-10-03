<?php
declare(strict_types=1);
/** Action `type_archive` (log `type.archive`; confirm): settings.manage; a custom type with no open tickets. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('type') ?? request_integer('type_id') ?? refuse(422, 'Say which type.');
$t = find_type($pdo, $id) ?? refuse(404, 'Type not found.');
hd_guard($pdo, static function () use ($pdo, $id, $t): void {
    $pdo->beginTransaction();
    archive_type($pdo, $id);
    log_activity($pdo, 'type.archive', 'ticket_type', $id, ['before' => ['archived' => false], 'after' => ['archived' => true, 'label' => $t['label']]]);
    $pdo->commit();
});
hd_done('Archived ' . $t['label'], $id, hd_land(return_path('/settings/vocabulary/?tab=types'), 'type_archived'), 'typeChanged');
