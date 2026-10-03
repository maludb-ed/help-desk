<?php /** One custom field as an input (the renderer slice 3 reuses). Data: f (definition), value, prefix (field name prefix, default "custom"), idp (id prefix) */
$prefix = $prefix ?? 'custom'; $idp = ($idp ?? 'custom') . '-' . $f['key']; $name = $prefix . '[' . $f['key'] . ']'; $value = $value ?? null; ?>
<div class="mb-3" id="<?= e($idp) ?>-group">
    <?php if ($f['field_type'] === 'checkbox'): ?>
        <input type="hidden" name="<?= e($name) ?>" value="no">
        <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="<?= e($idp) ?>"><input type="checkbox" class="form-check-input mt-0" name="<?= e($name) ?>" value="yes" id="<?= e($idp) ?>" <?= $value ? 'checked' : '' ?>><?= e($f['label']) ?></label>
    <?php else: ?>
        <label class="form-label fs-12 text-muted" for="<?= e($idp) ?>"><?= e($f['label']) ?><?= $f['required_on_solve'] ? ' <span class="text-muted">(needed to solve)</span>' : '' ?></label>
        <?php if ($f['field_type'] === 'select'): ?>
            <select name="<?= e($name) ?>" id="<?= e($idp) ?>" class="form-select btn-touch"><option value="">—</option><?php foreach ($f['options'] as $o): ?><option value="<?= e($o) ?>" <?= $value === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
        <?php elseif ($f['field_type'] === 'multi_select'): ?>
            <input type="hidden" name="<?= e($name) ?>[]" value="">
            <?php foreach ($f['options'] as $i => $o): ?><label class="d-flex align-items-center gap-2 border rounded px-3 mb-1 btn-touch" for="<?= e($idp) ?>-<?= $i ?>"><input type="checkbox" class="form-check-input mt-0" name="<?= e($name) ?>[]" value="<?= e($o) ?>" id="<?= e($idp) ?>-<?= $i ?>" <?= in_array($o, (array) $value, true) ? 'checked' : '' ?>><?= e($o) ?></label><?php endforeach; ?>
        <?php elseif ($f['field_type'] === 'date'): ?>
            <input type="date" name="<?= e($name) ?>" id="<?= e($idp) ?>" class="form-control btn-touch" value="<?= e((string) $value) ?>">
        <?php elseif ($f['field_type'] === 'number'): ?>
            <input type="number" step="any" name="<?= e($name) ?>" id="<?= e($idp) ?>" class="form-control btn-touch" value="<?= e((string) $value) ?>">
        <?php else: ?>
            <input type="text" name="<?= e($name) ?>" id="<?= e($idp) ?>" class="form-control btn-touch" maxlength="2000" value="<?= e((string) $value) ?>">
        <?php endif; ?>
    <?php endif; ?>
</div>
