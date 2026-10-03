<?php /** One queue as a card. Data: q, may (manage), here */ $id = (int) $q['queue_id']; ?>
<div class="card h-100" id="queue-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0" id="queue-card-<?= $id ?>-name"><?= hx_link(with_back('/settings/queues/' . $id, $here), e($q['name']), 'text-dark') ?></div>
        <?php if ($q['is_default']): ?><span class="badge bg-soft-primary text-primary" id="queue-card-<?= $id ?>-default">Default</span><?php endif; ?>
    </div>
    <div class="mt-1" id="queue-card-<?= $id ?>-department">
        <?php if ($q['department_id'] !== null): ?><span class="badge bg-soft-secondary text-secondary"><i class="feather-briefcase me-1"></i><?= e($q['department_name']) ?><?= $q['include_department'] ? ' · members join' : '' ?></span>
        <?php else: ?><span class="fs-12 text-muted">No department</span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-1" id="queue-card-<?= $id ?>-members"><?= (int) $q['member_count'] ?> member<?= (int) $q['member_count'] === 1 ? '' : 's' ?><?= $q['lead_names'] !== null ? ' · lead: ' . e($q['lead_names']) : '' ?></div>
    <div class="fs-12 mt-1" id="queue-card-<?= $id ?>-facts"><span class="fw-semibold"><?= (int) $q['open_count'] ?> open</span> <span class="text-muted">· <?= e(queue_method_words($q)) ?></span></div>
    <?php if ($q['email_address'] !== null && $q['email_address'] !== ''): ?><div class="fs-12 text-muted mt-1" id="queue-card-<?= $id ?>-email"><i class="feather-mail me-1"></i><?= e($q['email_address']) ?></div><?php endif; ?>
    <div class="row g-2 mt-2">
        <div class="col-<?= $may['manage'] ? '6' : '12' ?>"><?= hx_link(with_back('/settings/queues/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="queue-card-' . $id . '-open-btn"') ?></div>
        <?php if ($may['manage']): ?><div class="col-6"><?= hx_link(with_back('/settings/queues/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch w-100', 'id="queue-card-' . $id . '-edit-btn"') ?></div><?php endif; ?>
    </div>
</div></div>
