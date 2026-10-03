<?php /** One requester as a card. Data: r, here, compact? */ $id = (int) $r['requester_id']; $kind = $r['kind']; $compact = $compact ?? false;
$chip = ['member' => 'bg-soft-secondary text-secondary', 'external' => 'bg-soft-primary text-primary', 'agent' => 'bg-soft-info text-info'][$kind] ?? 'bg-soft-secondary text-secondary'; ?>
<div class="card h-100" id="requester-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0 text-truncate" id="requester-card-<?= $id ?>-name"><?= hx_link(with_back('/requesters/' . $id, $here), e($r['display_name']), 'text-dark') ?></div>
        <span class="badge <?= $chip ?>" id="requester-card-<?= $id ?>-kind"><?= e(REQUESTER_KINDS[$kind] ?? $kind) ?></span>
    </div>
    <?php if (!$compact && !empty($r['organization_name'])): ?><div class="fs-12 mt-1" id="requester-card-<?= $id ?>-organization"><?= hx_link(with_back('/organizations/' . (int) $r['organization_id'], $here), e($r['organization_name'])) ?></div><?php endif; ?>
    <?php if (!empty($r['email']) || !empty($r['phone'])): ?><div class="fs-12 text-muted mt-1 text-truncate" id="requester-card-<?= $id ?>-contact"><?= e($r['email'] ?? '') ?><?= !empty($r['email']) && !empty($r['phone']) ? ' · ' : '' ?><?= e($r['phone'] ?? '') ?></div><?php endif; ?>
    <div class="fs-12 mt-1" id="requester-card-<?= $id ?>-facts"><span class="fw-semibold"><?= (int) $r['ticket_count'] ?> ticket<?= (int) $r['ticket_count'] === 1 ? '' : 's' ?></span><?= !empty($r['last_seen_at']) ? ' <span class="text-muted">· seen ' . e(format_ts($r['last_seen_at'], member_timezone(), 'M j')) . '</span>' : '' ?></div>
    <?php if (!$compact): ?><div class="mt-2"><?= hx_link(with_back('/requesters/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="requester-card-' . $id . '-open-btn"') ?></div><?php endif; ?>
</div></div>
