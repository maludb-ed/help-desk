<?php /** One type as a ledger row with its inline edit form. Data: t, here */ $id = (int) $t['type_id']; ?>
<tr id="type-row-<?= $id ?>">
    <td>
        <form method="post" action="/settings/vocabulary/type.php" hx-post="/settings/vocabulary/type.php" hx-target="#flash" class="row g-1 align-items-center" id="type-row-<?= $id ?>-form">
            <?= csrf_field() ?><input type="hidden" name="type" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <div class="col-12 col-md-4"><input type="text" name="label" id="type-row-<?= $id ?>-label" class="form-control form-control-sm" maxlength="60" required value="<?= e($t['label']) ?>" aria-label="Label"></div>
            <div class="col-12 col-md-6"><input type="text" name="description" id="type-row-<?= $id ?>-description" class="form-control form-control-sm" maxlength="200" value="<?= e($t['description'] ?? '') ?>" placeholder="Description" aria-label="Description"></div>
            <div class="col-6 col-md-1"><input type="number" name="sort_order" id="type-row-<?= $id ?>-sort" class="form-control form-control-sm" min="0" max="1000" value="<?= (int) $t['sort_order'] ?>" aria-label="Order"></div>
            <div class="col-6 col-md-1 text-end"><button type="submit" class="btn btn-light btn-sm btn-touch" id="type-row-<?= $id ?>-save-btn" aria-label="Save"><i class="feather-check"></i></button></div>
        </form>
        <div class="fs-11 text-muted mt-1"><code><?= e($t['key']) ?></code><?= $t['is_system'] ? ' <i class="feather-lock" title="Built-in"></i>' : '' ?> · <span id="type-row-<?= $id ?>-count"><?= (int) $t['ticket_count'] ?> ticket<?= (int) $t['ticket_count'] === 1 ? '' : 's' ?>, <?= (int) $t['open_count'] ?> open</span></div>
    </td>
    <td class="text-end align-top">
        <?php if (!$t['is_system']): ?><form method="post" action="/settings/vocabulary/type-archive.php" hx-post="/settings/vocabulary/type-archive.php" hx-target="#flash" hx-confirm="Archive <?= e($t['label']) ?>? It leaves the forms."><?= csrf_field() ?><input type="hidden" name="type" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="type-row-<?= $id ?>-archive-btn" aria-label="Archive"><i class="feather-archive"></i></button></form><?php endif; ?>
    </td>
</tr>
