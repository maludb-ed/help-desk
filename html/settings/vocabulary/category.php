<?php
declare(strict_types=1);
/** Action `category_save` (by the presence of `category`; log `category.save` with before/after incl. parent_id and the defaults): settings.manage. A cycle and an archived parent → 422. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('category') ?? request_integer('category_id');
$cur = $id === null ? null : (find_category($pdo, $id) ?? refuse(404, 'Category not found.'));
if ($cur !== null && $cur['archived_at'] !== null) {
    refuse(422, 'That category is archived.');
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Give the category a name of up to 80 characters.';
}
$parentField = req_has('parent') ? 'parent' : 'parent_id';
$parent = hd_ref($pdo, $parentField, $cur['parent_id'] ?? null, 'SELECT 1 FROM categories WHERE id = :id', 'parent category', $errors);
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 500)) : ($cur['description'] ?? null);
$queue = hd_ref($pdo, req_has('default_queue') ? 'default_queue' : 'default_queue_id', $cur['default_queue_id'] ?? null, 'SELECT 1 FROM queues WHERE id = :id AND archived_at IS NULL', 'queue', $errors);
$type = hd_ref($pdo, req_has('default_type') ? 'default_type' : 'default_type_id', $cur['default_type_id'] ?? null, 'SELECT 1 FROM ticket_types WHERE id = :id AND archived_at IS NULL', 'type', $errors);
$priority = req_has('default_priority') ? ((string) req_val('default_priority') === '' ? null : (string) req_val('default_priority')) : ($cur['default_priority'] ?? null);
if ($priority !== null && !isset(TICKET_PRIORITIES[$priority])) {
    $errors['default_priority'] = 'The priority is low, normal, high or urgent.';
}
$policy = hd_ref($pdo, req_has('policy') ? 'policy' : 'sla_policy_id', $cur['sla_policy_id'] ?? null, 'SELECT 1 FROM sla_policies WHERE id = :id AND active', 'policy', $errors);
$visible = hd_yes('requester_visible', $cur['requester_visible'] ?? true);
$order = hd_int('sort_order', $cur['sort_order'] ?? 100, 0, 1000, 'The order', $errors);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['name' => $name, 'parent_id' => $parent, 'description' => $description, 'default_queue_id' => $queue, 'default_type_id' => $type, 'default_priority' => $priority, 'sla_policy_id' => $policy, 'requester_visible' => $visible, 'sort_order' => $order];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_category($pdo, $id, $f);
    $after = category_log_state(find_category($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'category.save', 'category', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(category_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'category.save', 'category', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the category ' : 'Saved the category ') . $name, $newId, hd_land(return_path('/settings/vocabulary/?tab=categories'), 'category_saved', 'category-row-' . $newId), 'categoryChanged', ['category_id' => $newId]);
