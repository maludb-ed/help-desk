<?php
declare(strict_types=1);
/** Action `category_archive` (log `category.archive`; confirm): settings.manage. Everything under it goes too; tickets keep their category_id. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('category') ?? request_integer('category_id') ?? refuse(422, 'Say which category.');
$c = find_category($pdo, $id) ?? refuse(404, 'Category not found.');
$ids = hd_guard($pdo, static function () use ($pdo, $id, $c): array {
    $pdo->beginTransaction();
    $ids = archive_category($pdo, $id);
    log_activity($pdo, 'category.archive', 'category', $id, ['before' => ['archived' => false], 'after' => ['archived' => true, 'name' => $c['name'], 'with_children' => count($ids) - 1]]);
    $pdo->commit();
    return $ids;
});
hd_done('Archived ' . $c['name'] . (count($ids) > 1 ? ' and ' . (count($ids) - 1) . ' under it' : ''), $id, hd_land(return_path('/settings/vocabulary/?tab=categories'), 'category_archived'), 'categoryChanged', ['archived_ids' => $ids]);
