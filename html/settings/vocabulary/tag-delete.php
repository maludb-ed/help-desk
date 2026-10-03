<?php
declare(strict_types=1);
/** Action `tag_delete` (log `tag.delete`: after.tickets_untagged; confirm): settings.manage. Every ticket loses it. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('tag') ?? request_integer('tag_id') ?? refuse(422, 'Say which tag.');
$g = find_tag($pdo, $id) ?? refuse(404, 'Tag not found.');
$n = hd_guard($pdo, static function () use ($pdo, $id, $g): int {
    $pdo->beginTransaction();
    $n = delete_tag($pdo, $id);
    log_activity($pdo, 'tag.delete', 'tag', $id, ['before' => ['label' => $g['label'], 'slug' => $g['slug']], 'after' => ['deleted' => true, 'tickets_untagged' => $n]]);
    $pdo->commit();
    return $n;
});
hd_done('Deleted the tag ' . $g['label'] . ' from ' . $n . ' ticket' . ($n === 1 ? '' : 's'), $id, hd_land(return_path('/settings/vocabulary/?tab=tags'), 'tag_deleted'), 'tagChanged', ['tickets_untagged' => $n]);
