<?php
declare(strict_types=1);
/** Action `tag_save` (by the presence of `tag`; log `tag.save`): tickets.manage (a lead). label, slug (derived when blank; unique). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
hd_handler_begin();
require_right('tickets.manage');
$pdo = db();
$id = request_integer('tag') ?? request_integer('tag_id');
$cur = $id === null ? null : (find_tag($pdo, $id) ?? refuse(404, 'Tag not found.'));
$label = req_has('label') ? (string) req_val('label') : (string) ($cur['label'] ?? '');
if ($label === '' || mb_strlen($label) > 60) {
    hd_refuse_fields(['label' => 'Give the tag a label of up to 60 characters.']);
}
$slug = req_has('slug') ? (string) req_val('slug') : ($cur['slug'] ?? null);
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $label, $slug): int {
    $pdo->beginTransaction();
    $newId = save_tag($pdo, $id, $label, $slug === '' ? null : $slug);
    $after = array_intersect_key(find_tag($pdo, $newId), array_flip(['label', 'slug']));
    if ($cur === null) {
        log_activity($pdo, 'tag.save', 'tag', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(array_intersect_key($cur, array_flip(['label', 'slug'])), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'tag.save', 'tag', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the tag ' : 'Renamed the tag to ') . $label, $newId, hd_land(return_path('/settings/vocabulary/?tab=tags'), 'tag_saved', 'tag-chip-' . $newId), 'tagChanged', ['tag_id' => $newId]);
