<?php /** Types, categories, tags (screen `vocabulary`; tab). Data: tab, types, tree, tags, may (manage, settings), q, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'vocabulary', 'title' => 'Types, categories, tags', 'crumbs' => [['Home', '/'], ['Types, categories, tags', null]],
    'action' => $tab === 'categories' && $may['settings'] ? hx_link('/settings/vocabulary/categories/new', '<i class="feather-plus me-1"></i>Add category', 'btn btn-primary btn-touch', 'id="vocabulary-add-category-btn"') : '']) ?>
<div class="main-content" id="vocabulary-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="vocabulary-tabs">
        <?php foreach (['types' => 'Types', 'categories' => 'Categories', 'tags' => 'Tags'] as $k => $l): ?><?= hx_link('/settings/vocabulary/?tab=' . $k, e($l), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="vocabulary-tab-' . e($k) . '"') ?><?php endforeach; ?>
    </div>
    <?php if ($tab === 'types'): ?>
        <div class="card" id="types-card"><div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="types-table"><tbody>
            <?php foreach ($types as $t): ?><?= view('vocabulary/partials/type-row.php', ['t' => $t, 'here' => $here]) ?><?php endforeach; ?>
        </tbody></table></div></div>
        <?php if ($may['settings']): ?>
        <div class="card-body border-top">
            <form method="post" action="/settings/vocabulary/type.php" hx-post="/settings/vocabulary/type.php" hx-target="#flash" class="row g-2 align-items-end" id="type-form">
                <?= csrf_field() ?><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="type-form-field-label">New type</label><input type="text" name="label" id="type-form-field-label" class="form-control btn-touch" maxlength="60" required placeholder="Change request"></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="type-form-field-description">Description</label><input type="text" name="description" id="type-form-field-description" class="form-control btn-touch" maxlength="200"></div>
                <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="type-form-save-btn">Add</button></div>
            </form>
        </div>
        <?php endif; ?></div>
        <div class="fs-12 text-muted mt-2">The four built-in types are renamed, never archived. A custom type archives when no open ticket is of it.</div>
    <?php elseif ($tab === 'categories'): ?>
        <div class="card" id="categories-card">
            <div class="list-group list-group-flush" id="categories-tree">
                <?php if ($tree === []): ?><div class="list-group-item text-muted" id="categories-empty">No categories yet. A category routes a request to a queue, a type and a priority — Billing › Refunds, IT › Access.</div><?php endif; ?>
                <?php foreach ($tree as $c): ?><?= view('vocabulary/partials/category-row.php', ['c' => $c, 'here' => $here]) ?><?php endforeach; ?>
            </div>
        </div>
    <?php else: ?>
        <form method="get" action="/settings/vocabulary/" hx-get="/settings/vocabulary/" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="tags-filter"><div class="card-body d-flex gap-2">
            <input type="hidden" name="tab" value="tags">
            <input type="search" name="q" id="tags-filter-q" class="form-control btn-touch" placeholder="Find a tag…" value="<?= e($q) ?>" maxlength="50" aria-label="Search tags">
            <button type="submit" class="btn btn-light btn-touch" id="tags-filter-btn" aria-label="Search"><i class="feather-search"></i></button>
        </div></form>
        <div class="card" id="tags-card"><div class="card-body" id="tags-chips">
            <?php if ($tags === []): ?><div class="text-muted" id="tags-empty">No tags yet. A tag is born on a ticket, or here.</div><?php endif; ?>
            <?php foreach ($tags as $g): ?><?= view('vocabulary/partials/tag-chip.php', ['g' => $g, 'may' => $may, 'here' => $here]) ?><?php endforeach; ?>
        </div>
        <?php if ($may['manage']): ?>
        <div class="card-body border-top">
            <form method="post" action="/settings/vocabulary/tag.php" hx-post="/settings/vocabulary/tag.php" hx-target="#flash" class="input-group" id="tag-form">
                <?= csrf_field() ?><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <input type="text" name="label" id="tag-form-field-label" class="form-control btn-touch" maxlength="60" required placeholder="New tag">
                <button type="submit" class="btn btn-primary btn-touch" id="tag-form-save-btn">Add</button>
            </form>
        </div>
        <?php endif; ?></div>
    <?php endif; ?>
</div>
