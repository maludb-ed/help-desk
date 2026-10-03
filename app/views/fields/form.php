<?php /** Add or change a field (screens `field-add`, `field-edit`). Data: cur, appliesTo, types, here */ $id = $cur['field_id'] ?? null; $screen = $cur === null ? 'field-add' : 'field-edit'; $a = $cur['applies_to'] ?? $appliesTo; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a field for ' . strtolower(FIELD_APPLIES[$a]) : 'Change ' . $cur['label'], 'crumbs' => [['Home', '/'], ['Custom fields', '/settings/fields/'], [$cur === null ? 'Add' : $cur['label'], null]], 'back' => back_link() ?? ['/settings/fields/', 'Custom fields']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/fields/save.php" hx-post="/settings/fields/save.php" hx-target="#flash" id="field-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="field" value="<?= (int) $id ?>"><?php endif; ?><input type="hidden" name="applies_to" value="<?= e($a) ?>">
        <div class="card mb-3"><div class="card-body">
            <div class="mb-3"><span class="badge bg-soft-secondary text-secondary" id="field-form-applies">For <?= e(strtolower(FIELD_APPLIES[$a])) ?></span> <span class="fs-12 text-muted">(fixed)</span></div>
            <label class="form-label fs-12 text-muted" for="field-form-field-label">Label</label>
            <input type="text" name="label" id="field-form-field-label" class="form-control btn-touch mb-3" maxlength="60" required value="<?= e($cur['label'] ?? '') ?>" placeholder="Asset tag">
            <label class="form-label fs-12 text-muted" for="field-form-field-key">Key<?= $cur !== null ? ' (fixed)' : ' (left blank: made from the label)' ?></label>
            <input type="text" name="key" id="field-form-field-key" class="form-control btn-touch mb-3" maxlength="40" pattern="[a-z][a-z0-9_]*" value="<?= e($cur['key'] ?? '') ?>" <?= $cur !== null ? 'readonly' : '' ?> placeholder="asset_tag">
            <label class="form-label fs-12 text-muted" for="field-form-field-type">Type</label>
            <select name="field_type" id="field-form-field-type" class="form-select btn-touch mb-3"><?php foreach (FIELD_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($cur['field_type'] ?? 'text') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="field-form-field-options">Options (one per line; for select and multi select)</label>
            <textarea name="options" id="field-form-field-options" class="form-control mb-3" rows="4"><?= e(implode("\n", $cur['options'] ?? [])) ?></textarea>
            <?php if ($a === 'ticket'): ?>
                <label class="form-label fs-12 text-muted">Shown for these types only (none = every type)</label>
                <input type="hidden" name="types[]" value="">
                <div class="d-flex flex-wrap gap-2 mb-3" id="field-form-field-types">
                    <?php foreach ($types as $t): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="field-form-field-type-<?= (int) $t['type_id'] ?>"><input type="checkbox" class="form-check-input mt-0" name="types[]" value="<?= (int) $t['type_id'] ?>" id="field-form-field-type-<?= (int) $t['type_id'] ?>" <?= in_array((int) $t['type_id'], $cur['type_ids'] ?? [], true) ? 'checked' : '' ?>><?= e($t['label']) ?></label><?php endforeach; ?>
                </div>
                <input type="hidden" name="required_on_solve" value="no">
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="field-form-field-required"><input type="checkbox" class="form-check-input mt-0" name="required_on_solve" value="yes" id="field-form-field-required" <?= ($cur['required_on_solve'] ?? false) ? 'checked' : '' ?>>Needed before a ticket can be solved</label>
            <?php endif; ?>
            <input type="hidden" name="requester_visible" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="field-form-field-visible"><input type="checkbox" class="form-check-input mt-0" name="requester_visible" value="yes" id="field-form-field-visible" <?= ($cur['requester_visible'] ?? false) ? 'checked' : '' ?>>Requesters see and fill it</label>
            <label class="form-label fs-12 text-muted" for="field-form-field-sort">Order</label>
            <input type="number" name="sort_order" id="field-form-field-sort" class="form-control btn-touch" min="0" max="1000" value="<?= (int) ($cur['sort_order'] ?? 100) ?>">
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="field-form-save-btn">Save</button>
        <?= hx_link('/settings/fields/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="field-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && $cur['archived_at'] === null): ?>
        <form method="post" action="/settings/fields/archive.php" hx-post="/settings/fields/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($cur['label']) ?>? Records keep their values; the forms no longer show it."><?= csrf_field() ?><input type="hidden" name="field" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="field-form-archive-btn">Archive</button></form>
    <?php endif; ?>
</div>
