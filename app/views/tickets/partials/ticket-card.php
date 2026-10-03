<?php /** One ticket as a card (the phone). Data: t, here, tz, bulk? */ $id = (int) $t['ticket_id']; $sla = $t['sla_state'] ?? null; $bulk = $bulk ?? false; ?>
<div class="card mb-2 ticket-card sla-<?= e($sla ?? 'none') ?>" id="ticket-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <?php if ($bulk): ?><input type="checkbox" class="form-check-input mt-1" name="tickets[]" value="<?= $id ?>" id="ticket-card-<?= $id ?>-pick" aria-label="Select <?= e($t['number']) ?>"><?php endif; ?>
        <div class="min-w-0"><?= hx_link(with_back('/tickets/' . $id, $here), '<span class="text-muted fs-12">' . e($t['number']) . '</span> <span class="fw-bold">' . e($t['subject']) . '</span>', 'text-dark', 'id="ticket-card-' . $id . '-link"') ?></div>
        <span class="badge bg-<?= e($t['status_color']) ?> text-nowrap"><?= e($t['status_label']) ?></span>
    </div>
    <div class="fs-12 text-muted mt-1 text-truncate"><?= e($t['requester_name']) ?><?= $t['organization_name'] ? ' <small>· ' . e($t['organization_name']) . '</small>' : '' ?> · <?= e($t['queue_name']) ?><?= $t['assignee_name'] ? ' · ' . e($t['assignee_name']) . ($t['assignee_kind'] === 'agent' ? ' <i class="feather-cpu"></i>' : '') : ' · nobody' ?></div>
    <div class="fs-12 mt-1 d-flex flex-wrap gap-1 align-items-center">
        <span class="badge bg-soft-<?= e(PRIORITY_COLORS[$t['priority']]) ?> text-<?= e(PRIORITY_COLORS[$t['priority']]) ?>"><?= e($t['priority']) ?></span>
        <?php if ($t['needs_reply']): ?><span class="badge bg-soft-warning text-warning"><i class="feather-corner-up-left"></i> needs reply</span><?php endif; ?>
        <?php if (($w = sla_due_words($t['sla_next_due_at'], $sla)) !== ''): ?><span class="badge bg-soft-<?= $sla === 'breached' ? 'danger text-danger' : ($sla === 'at_risk' ? 'warning text-warning' : 'secondary text-secondary') ?>"><?= e($w) ?></span><?php endif; ?>
        <?php foreach (array_slice($t['tags'], 0, 3) as $g): ?><span class="badge bg-soft-secondary text-secondary"><?= e($g) ?></span><?php endforeach; ?><?= count($t['tags']) > 3 ? '<span class="fs-11 text-muted">+' . (count($t['tags']) - 3) . '</span>' : '' ?>
        <span class="text-muted ms-auto"><?= e(format_ts($t['last_activity_at'], $tz, 'M j, g:i A')) ?></span>
    </div>
</div></div>
