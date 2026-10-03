<?php
declare(strict_types=1);
/** Action `type_save` (by the presence of `type`; log `type.save`): settings.manage; label, description, sort_order. The key is derived on create and never changes. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('type') ?? request_integer('type_id');
$cur = $id === null ? null : (find_type($pdo, $id) ?? refuse(404, 'Type not found.'));
if ($cur !== null && $cur['archived_at'] !== null) {
    refuse(422, 'That type is archived.');
}
$errors = [];
$label = req_has('label') ? (string) req_val('label') : (string) ($cur['label'] ?? '');
if ($label === '' || mb_strlen($label) > 60) {
    $errors['label'] = 'Give the type a label of up to 60 characters.';
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 200)) : ($cur['description'] ?? null);
$order = hd_int('sort_order', $cur['sort_order'] ?? 100, 0, 1000, 'The order', $errors);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['label' => $label, 'description' => $description, 'sort_order' => $order];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_type($pdo, $id, $f);
    $after = array_intersect_key(find_type($pdo, $newId), array_flip(['key', 'label', 'description', 'sort_order']));
    if ($cur === null) {
        log_activity($pdo, 'type.save', 'ticket_type', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(array_intersect_key($cur, array_flip(['key', 'label', 'description', 'sort_order'])), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'type.save', 'ticket_type', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the type ' : 'Saved the type ') . $label, $newId, hd_land(return_path('/settings/vocabulary/?tab=types'), 'type_saved', 'type-row-' . $newId), 'typeChanged', ['type_id' => $newId]);
