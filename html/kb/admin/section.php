<?php
declare(strict_types=1);
/** Action `section_save` (log `section.save`): kb.edit; section (to change one), name 1–80, slug, parent (a top-level section, never itself), description ≤ 300, visibility, sort_order 0–1000. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('kb.edit');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('section') ?? request_integer('id');
$cur = $id === null ? null : (find_section($pdo, $id) ?? refuse(404, 'Section not found.'));
$errors = [];
$name = $cur !== null && !req_has('name') ? $cur['name'] : (string) req_val('name');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Name the section (up to 80 characters).';
}
$slug = $cur !== null && !req_has('slug') ? $cur['slug'] : strtolower(trim((string) req_val('slug')));
if ($slug !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug)) {
    $errors['slug'] = 'An address is lower-case letters, digits and dashes.';
}
$parentRaw = req_val('parent');
$parentId = $cur !== null && !req_has('parent') ? $cur['parent_id'] : ($parentRaw === null || $parentRaw === '' ? null : (int) $parentRaw);
if ($parentId !== null) {
    $p = find_section($pdo, $parentId);
    if ($p === null || $p['archived_at'] !== null || $p['parent_id'] !== null || $parentId === $id) {
        $errors['parent'] = 'The parent must be a top-level section.';
    }
}
$description = $cur !== null && !req_has('description') ? (string) $cur['description'] : mb_substr((string) req_val('description'), 0, 300);
$visibility = $cur !== null && !req_has('visibility') ? $cur['visibility'] : (string) req_val('visibility');
if (!in_array($visibility, ['public', 'internal'], true)) {
    $errors['visibility'] = 'Public or internal.';
}
$sort = $cur !== null && !req_has('sort_order') ? $cur['sort_order'] : (int) req_val('sort_order');
if ($sort < 0 || $sort > 1000) {
    $errors['sort_order'] = '0 to 1000.';
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['name' => $name, 'slug' => $slug, 'parent_id' => $parentId, 'description' => $description, 'visibility' => $visibility, 'sort_order' => $sort];
$newId = hd_guard($pdo, static function () use ($pdo, $me, $cur, $f, $id): int {
    $pdo->beginTransaction();
    $newId = save_section($pdo, $id, $f, $me);
    $after = find_section($pdo, $newId);
    $state = static fn (array $s): array => ['name' => $s['name'], 'slug' => $s['slug'], 'parent_id' => $s['parent_id'], 'description' => $s['description'], 'visibility' => $s['visibility'], 'sort_order' => $s['sort_order']];
    $d = $cur === null ? ['before' => [], 'after' => $state($after)] : hd_diff($state($cur), $state($after));
    log_activity($pdo, 'section.save', 'kb_section', $newId, $d);
    $pdo->commit();
    return $newId;
});
hd_done(($cur === null ? 'Added the section ' : 'Saved the section ') . $name, $newId, hd_land(return_path('/kb/admin/'), 'section_saved'), 'articleChanged');
