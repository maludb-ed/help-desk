<?php /** One cell of the matrix: a form for the move from → to. Data: from, to, r (row or null), here */ $cid = $from . '-' . $to; ?>
<details class="transition-cell<?= $r === null ? ' text-muted' : '' ?>" id="transition-<?= e($cid) ?>">
    <summary class="btn btn-sm btn-touch w-100 <?= $r === null ? 'btn-light' : 'btn-soft-primary' ?>" id="transition-<?= e($cid) ?>-toggle"><?= $r === null ? '<i class="feather-x"></i>' : e($r['label']) ?></summary>
    <form method="post" action="/settings/statuses/transition.php" hx-post="/settings/statuses/transition.php" hx-target="#flash" class="p-2 border rounded mt-1 text-start bg-body" id="transition-<?= e($cid) ?>-form">
        <?= csrf_field() ?><input type="hidden" name="from_category" value="<?= e($from) ?>"><input type="hidden" name="to_category" value="<?= e($to) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <input type="hidden" name="requires[]" value=""><input type="hidden" name="may_carry[]" value="">
        <label class="form-label fs-11 text-muted mb-0" for="transition-<?= e($cid) ?>-allowed">Allowed</label>
        <select name="allowed" id="transition-<?= e($cid) ?>-allowed" class="form-select form-select-sm mb-1"><option value="yes" <?= $r !== null ? 'selected' : '' ?>>Yes</option><option value="no" <?= $r === null ? 'selected' : '' ?>>No</option></select>
        <label class="form-label fs-11 text-muted mb-0" for="transition-<?= e($cid) ?>-label">Label</label>
        <input type="text" name="label" id="transition-<?= e($cid) ?>-label" class="form-control form-control-sm mb-1" maxlength="40" value="<?= e($r['label'] ?? '') ?>" placeholder="<?= e(STATUS_CATEGORIES[$to]) ?>">
        <div class="fs-11 text-muted">Needs</div>
        <?php foreach (TRANSITION_REQUIRES as $k => $l): ?><label class="d-block fs-12"><input type="checkbox" name="requires[]" value="<?= e($k) ?>" class="form-check-input" <?= in_array($k, $r['requires'] ?? [], true) ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?>
        <div class="fs-11 text-muted">May carry</div>
        <?php foreach (TRANSITION_MAY_CARRY as $k => $l): ?><label class="d-block fs-12"><input type="checkbox" name="may_carry[]" value="<?= e($k) ?>" class="form-check-input" <?= in_array($k, $r['may_carry'] ?? [], true) ? 'checked' : '' ?>> <?= e($l) ?></label><?php endforeach; ?>
        <label class="form-label fs-11 text-muted mb-0 mt-1" for="transition-<?= e($cid) ?>-right">Who</label>
        <select name="needs_right" id="transition-<?= e($cid) ?>-right" class="form-select form-select-sm mb-2"><?php foreach (TRANSITION_RIGHTS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($r['needs_right'] ?? 'tickets.work') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-primary btn-sm btn-touch w-100" id="transition-<?= e($cid) ?>-save-btn">Save</button>
    </form>
</details>
