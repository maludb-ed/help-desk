<?php /** The custom fields (screen `fields-list`). Data: groups (applies_to => rows), typeLabels, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'fields-list', 'title' => 'Custom fields', 'crumbs' => [['Home', '/'], ['Custom fields', null]]]) ?>
<div class="main-content" id="fields-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php foreach (FIELD_APPLIES as $a => $label): ?>
        <div class="card mb-3" id="fields-group-<?= e($a) ?>">
            <div class="card-header"><h5 class="card-title mb-0"><?= e($label) ?></h5><?= hx_link('/settings/fields/new?applies_to=' . $a, '<i class="feather-plus me-1"></i>Add field', 'btn btn-light btn-sm btn-touch', 'id="fields-group-' . e($a) . '-add-btn"') ?></div>
            <div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="fields-table-<?= e($a) ?>">
                <thead class="thead-light"><tr><th>Field</th><th>Type</th><th class="d-none d-md-table-cell">Shown for</th><th class="d-none d-md-table-cell text-center">On solve</th><th class="d-none d-md-table-cell text-center">Requesters</th></tr></thead>
                <tbody>
                <?php if (($groups[$a] ?? []) === []): ?><tr><td colspan="5" class="text-center text-muted py-3" id="fields-group-<?= e($a) ?>-empty">No custom field for <?= e(strtolower($label)) ?> yet.</td></tr><?php endif; ?>
                <?php foreach ($groups[$a] ?? [] as $f): ?><?= view('fields/partials/field-row.php', ['f' => $f, 'typeLabels' => $typeLabels, 'here' => $here]) ?><?php endforeach; ?>
                </tbody>
            </table></div></div>
        </div>
    <?php endforeach; ?>
</div>
