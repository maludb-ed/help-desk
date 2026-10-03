<?php /** One tag as a chip with its inline rename and delete. Data: g, may (manage, settings), here */ $id = (int) $g['tag_id']; ?>
<details class="d-inline-block me-2 mb-2 align-top" id="tag-chip-<?= $id ?>">
    <summary class="btn btn-light btn-sm btn-touch" id="tag-chip-<?= $id ?>-toggle"><i class="feather-tag me-1"></i><?= e($g['label']) ?> <span class="badge bg-soft-secondary text-secondary ms-1" id="tag-chip-<?= $id ?>-usage"><?= (int) $g['usage'] ?></span></summary>
    <div class="border rounded p-2 mt-1 bg-body" style="min-width: 16rem">
        <?php if ($may['manage']): ?>
        <form method="post" action="/settings/vocabulary/tag.php" hx-post="/settings/vocabulary/tag.php" hx-target="#flash" id="tag-chip-<?= $id ?>-form">
            <?= csrf_field() ?><input type="hidden" name="tag" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <label class="form-label fs-11 text-muted mb-0" for="tag-chip-<?= $id ?>-label">Label</label><input type="text" name="label" id="tag-chip-<?= $id ?>-label" class="form-control form-control-sm mb-1" maxlength="60" required value="<?= e($g['label']) ?>">
            <label class="form-label fs-11 text-muted mb-0" for="tag-chip-<?= $id ?>-slug">Slug</label><input type="text" name="slug" id="tag-chip-<?= $id ?>-slug" class="form-control form-control-sm mb-2" maxlength="50" pattern="[a-z0-9][a-z0-9_\-]*" value="<?= e($g['slug']) ?>">
            <button type="submit" class="btn btn-primary btn-sm btn-touch w-100" id="tag-chip-<?= $id ?>-save-btn">Rename</button>
        </form>
        <?php else: ?><div class="fs-12 text-muted mb-2"><code><?= e($g['slug']) ?></code></div><?php endif; ?>
        <?php if ($may['settings']): ?>
        <form method="post" action="/settings/vocabulary/tag-delete.php" hx-post="/settings/vocabulary/tag-delete.php" hx-target="#flash" class="mt-1" hx-confirm="Delete <?= e($g['label']) ?>? Removes it from <?= (int) $g['usage'] ?> ticket<?= (int) $g['usage'] === 1 ? '' : 's' ?>.">
            <?= csrf_field() ?><input type="hidden" name="tag" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch w-100 text-danger" id="tag-chip-<?= $id ?>-delete-btn">Delete</button>
        </form>
        <?php endif; ?>
    </div>
</details>
