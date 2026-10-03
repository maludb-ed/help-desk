<?php /** One saved view as a card. Data: v (with count) */ $id = (int) $v['view_id']; $open = $v['layout'] === 'board' ? '/board?view=' . ($v['system_key'] ?? $id) : '/inbox?view=' . ($v['system_key'] ?? $id);
$scope = $v['is_system'] ? 'Standard' : ($v['scope'] === 'personal' ? 'Mine' : ($v['scope'] === 'queue' ? ($v['queue_name'] ?? 'Queue') : 'Everyone')); ?>
<div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="view-card-<?= $id ?>"><div class="card-body d-flex flex-column gap-2">
    <div class="d-flex align-items-start gap-2">
        <i class="<?= $v['layout'] === 'board' ? 'feather-trello' : 'feather-list' ?> fs-5 text-primary mt-1" title="<?= e(VIEW_LAYOUTS[$v['layout']]) ?>"></i>
        <div class="min-w-0 flex-grow-1"><div class="fw-bold text-truncate"><?= e($v['name']) ?></div><div class="fs-11 text-muted"><?= e($scope) ?> · <?= e(VIEW_LAYOUTS[$v['layout']]) ?><?= $v['filters'] === [] ? '' : ' · ' . e(implode(', ', array_keys($v['filters']))) ?></div></div>
        <span class="badge bg-soft-primary text-primary" id="view-card-<?= $id ?>-count"><?= (int) ($v['count'] ?? 0) ?></span>
    </div>
    <div class="d-flex gap-1 mt-auto">
        <?= hx_link($open, 'Open', 'btn btn-primary btn-touch flex-grow-1', 'id="view-card-' . $id . '-open"') ?>
        <?php if (view_may_change($v)): ?><?= hx_link('/views/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="view-card-' . $id . '-edit"') ?><?php endif; ?>
    </div>
</div></div></div>
