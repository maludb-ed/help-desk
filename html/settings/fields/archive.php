<?php
declare(strict_types=1);
/** Action `field_archive` (log `field.archive`; confirm): settings.manage. Records keep their values; the forms and the validator drop the field. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/fields/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('field') ?? request_integer('field_id') ?? refuse(422, 'Say which field.');
$f = find_field($pdo, $id) ?? refuse(404, 'Field not found.');
hd_guard($pdo, static function () use ($pdo, $id, $f): void {
    $pdo->beginTransaction();
    archive_field($pdo, $id);
    log_activity($pdo, 'field.archive', 'custom_field', $id, ['before' => ['archived' => false], 'after' => ['archived' => true, 'label' => $f['label'], 'applies_to' => $f['applies_to']]]);
    $pdo->commit();
});
hd_done('Archived ' . $f['label'], $id, hd_land(return_path('/settings/fields/'), 'archived'), 'fieldChanged');
