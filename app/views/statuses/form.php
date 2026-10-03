<?php /** Add or change a status (screens `status-add`, `status-edit`). Data: cur, here */ $id = $cur['status_id'] ?? null; $screen = $cur === null ? 'status-add' : 'status-edit'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a status' : 'Change ' . $cur['label'], 'crumbs' => [['Home', '/'], ['Statuses', '/settings/statuses/'], [$cur === null ? 'Add' : $cur['label'], null]], 'back' => back_link() ?? ['/settings/statuses/', 'Statuses']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/statuses/save.php" hx-post="/settings/statuses/save.php" hx-target="#flash" id="status-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="status" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="status-form-field-label">Label</label>
            <input type="text" name="label" id="status-form-field-label" class="form-control btn-touch mb-3" maxlength="60" required value="<?= e($cur['label'] ?? '') ?>" placeholder="Waiting on vendor">
            <?php if ($cur !== null): ?><div class="mb-3"><div class="fs-12 text-muted">Key (fixed)</div><code id="status-form-key"><?= e($cur['key']) ?></code></div><?php endif; ?>
            <label class="form-label fs-12 text-muted">Category</label>
            <?php if ($cur !== null && $cur['is_system']): ?>
                <div class="mb-3" id="status-form-category-ro"><span class="badge bg-soft-secondary text-secondary"><?= e(STATUS_CATEGORIES[$cur['category']]) ?></span> <span class="fs-12 text-muted">A built-in status keeps its category.</span></div>
            <?php else: ?>
                <div class="d-flex flex-wrap gap-2 mb-3" id="status-form-field-category">
                    <?php foreach (CUSTOM_STATUS_CATEGORIES as $k => $l): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="status-form-field-category-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="category" value="<?= e($k) ?>" id="status-form-field-category-<?= e($k) ?>" <?= ($cur['category'] ?? 'open') === $k ? 'checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?>
                </div>
            <?php endif; ?>
            <label class="form-label fs-12 text-muted">Colour</label>
            <div class="d-flex flex-wrap gap-2 mb-3" id="status-form-field-color">
                <?php foreach (STATUS_COLORS as $c): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="status-form-field-color-<?= e($c) ?>"><input type="radio" class="form-check-input mt-0" name="color" value="<?= e($c) ?>" id="status-form-field-color-<?= e($c) ?>" <?= ($cur['color'] ?? 'secondary') === $c ? 'checked' : '' ?>><span class="wd-10 ht-10 bg-<?= e($c) ?> d-inline-block rounded-circle"></span><?= e($c) ?></label><?php endforeach; ?>
            </div>
            <label class="form-label fs-12 text-muted" for="status-form-field-sort">Order on the board</label>
            <input type="number" name="sort_order" id="status-form-field-sort" class="form-control btn-touch mb-3" min="0" max="1000" value="<?= (int) ($cur['sort_order'] ?? 100) ?>">
            <input type="hidden" name="on_board" value="no"><input type="hidden" name="clock_runs" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="status-form-field-board"><input type="checkbox" class="form-check-input mt-0" name="on_board" value="yes" id="status-form-field-board" <?= ($cur['on_board'] ?? true) ? 'checked' : '' ?>>A column on the board</label>
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="status-form-field-clock"><input type="checkbox" class="form-check-input mt-0" name="clock_runs" value="yes" id="status-form-field-clock" <?= ($cur['clock_runs'] ?? true) ? 'checked' : '' ?>>The SLA clock runs in it (pending, on hold and solved usually pause it)</label>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="status-form-save-btn">Save</button>
        <?= hx_link('/settings/statuses/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="status-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && !$cur['is_system'] && $cur['archived_at'] === null): ?>
        <form method="post" action="/settings/statuses/archive.php" hx-post="/settings/statuses/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($cur['label']) ?>? It leaves the forms and the board."><?= csrf_field() ?><input type="hidden" name="status" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="status-form-archive-btn">Archive</button></form>
    <?php endif; ?>
</div>
