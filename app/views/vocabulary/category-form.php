<?php /** Add or change a category (screens `category-add`, `category-edit`). Data: cur, parent (preselect), tree, queues, types, policies, here */
$id = $cur['category_id'] ?? null; $screen = $cur === null ? 'category-add' : 'category-edit'; $parentSel = $cur['parent_id'] ?? $parent;
$under = $id === null ? [] : $descendants; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a category' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Types, categories, tags', '/settings/vocabulary/?tab=categories'], [$cur === null ? 'Add' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/vocabulary/?tab=categories', 'Categories']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/vocabulary/category.php" hx-post="/settings/vocabulary/category.php" hx-target="#flash" id="category-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="category" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The category</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="category-form-field-name">Name</label>
            <input type="text" name="name" id="category-form-field-name" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Refunds">
            <label class="form-label fs-12 text-muted" for="category-form-field-parent">Under</label>
            <select name="parent" id="category-form-field-parent" class="form-select btn-touch mb-3">
                <option value="">— the top —</option>
                <?php foreach ($tree as $c): if (in_array($c['category_id'], $under, true)) { continue; } ?><option value="<?= (int) $c['category_id'] ?>" <?= $parentSel === (int) $c['category_id'] ? 'selected' : '' ?>><?= e(str_repeat('· ', (int) $c['depth']) . $c['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="category-form-field-description">Description</label>
            <textarea name="description" id="category-form-field-description" class="form-control mb-3" rows="2" maxlength="500"><?= e($cur['description'] ?? '') ?></textarea>
            <input type="hidden" name="requester_visible" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="category-form-field-visible"><input type="checkbox" class="form-check-input mt-0" name="requester_visible" value="yes" id="category-form-field-visible" <?= ($cur['requester_visible'] ?? true) ? 'checked' : '' ?>>Requesters may choose it on the request form</label>
            <label class="form-label fs-12 text-muted" for="category-form-field-sort">Order</label>
            <input type="number" name="sort_order" id="category-form-field-sort" class="form-control btn-touch" min="0" max="1000" value="<?= (int) ($cur['sort_order'] ?? 100) ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Routing defaults (a new ticket in it starts with these)</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="category-form-field-queue">Queue</label>
            <select name="default_queue" id="category-form-field-queue" class="form-select btn-touch mb-3"><option value="">None</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= ($cur['default_queue_id'] ?? null) === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="category-form-field-type">Type</label>
            <select name="default_type" id="category-form-field-type" class="form-select btn-touch mb-3"><option value="">None</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['type_id'] ?>" <?= ($cur['default_type_id'] ?? null) === (int) $t['type_id'] ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="category-form-field-priority">Priority</label>
            <select name="default_priority" id="category-form-field-priority" class="form-select btn-touch mb-3"><option value="">None</option><?php foreach (TICKET_PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($cur['default_priority'] ?? null) === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="category-form-field-policy">SLA policy</label>
            <select name="policy" id="category-form-field-policy" class="form-select btn-touch"><option value="">Whichever matches</option><?php foreach ($policies as $p): ?><option value="<?= (int) $p['policy_id'] ?>" <?= ($cur['sla_policy_id'] ?? null) === (int) $p['policy_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="category-form-save-btn">Save</button>
        <?= hx_link('/settings/vocabulary/?tab=categories', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="category-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && $cur['archived_at'] === null): ?>
        <form method="post" action="/settings/vocabulary/category-archive.php" hx-post="/settings/vocabulary/category-archive.php" hx-target="#flash" hx-confirm="Archive <?= e($cur['name']) ?> and everything under it? Tickets keep it; the forms no longer offer it."><?= csrf_field() ?><input type="hidden" name="category" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="category-form-archive-btn">Archive</button></form>
    <?php endif; ?>
</div>
