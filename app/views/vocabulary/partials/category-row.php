<?php /** One category in the tree. Data: c, here */ $id = (int) $c['category_id']; ?>
<div class="list-group-item d-flex align-items-start gap-2 py-2" id="category-row-<?= $id ?>" style="padding-left: <?= 1 + (int) $c['depth'] * 1.25 ?>rem">
    <div class="min-w-0 flex-grow-1">
        <div class="fw-semibold"><?= $c['depth'] > 0 ? '<span class="text-muted">› </span>' : '' ?><?= hx_link(with_back('/settings/vocabulary/categories/' . $id . '/edit', $here), e($c['name']), 'text-dark', 'id="category-row-' . $id . '-name"') ?>
            <?= $c['requester_visible'] ? ' <i class="feather-eye text-muted" title="Requesters see it"></i>' : ' <i class="feather-eye-off text-muted" title="Support only"></i>' ?></div>
        <div class="fs-12" id="category-row-<?= $id ?>-defaults">
            <?php if ($c['default_queue_id']): ?><span class="badge bg-soft-secondary text-secondary">→ <?= e($c['default_queue_name']) ?></span><?php endif; ?>
            <?php if ($c['default_type_id']): ?><span class="badge bg-soft-secondary text-secondary"><?= e($c['default_type_label']) ?></span><?php endif; ?>
            <?php if ($c['default_priority']): ?><span class="badge bg-soft-warning text-warning"><?= e($c['default_priority']) ?></span><?php endif; ?>
            <?php if ($c['sla_policy_id']): ?><span class="badge bg-soft-primary text-primary"><?= e($c['policy_name']) ?> SLA</span><?php endif; ?>
            <span class="text-muted"><?= (int) $c['ticket_count'] ?> ticket<?= (int) $c['ticket_count'] === 1 ? '' : 's' ?></span>
        </div>
    </div>
    <?= hx_link(with_back('/settings/vocabulary/categories/new?parent=' . $id, $here), '<i class="feather-plus"></i>', 'btn btn-light btn-sm btn-touch', 'id="category-row-' . $id . '-add-child-btn" aria-label="Add child" title="Add child"') ?>
</div>
