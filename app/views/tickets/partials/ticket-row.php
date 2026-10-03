<?php /** One ticket as a table row (the desktop). Data: t, here, tz, bulk? */ $id = (int) $t['ticket_id']; $sla = $t['sla_state'] ?? null; $bulk = $bulk ?? false; ?>
<tr id="ticket-row-<?= $id ?>" class="sla-<?= e($sla ?? 'none') ?>">
    <?php if ($bulk): ?><td><input type="checkbox" class="form-check-input" name="tickets[]" value="<?= $id ?>" id="ticket-row-<?= $id ?>-pick" aria-label="Select <?= e($t['number']) ?>"></td><?php endif; ?>
    <td class="text-nowrap"><?= hx_link(with_back('/tickets/' . $id, $here), e($t['number']), 'fw-semibold', 'id="ticket-row-' . $id . '-number"') ?></td>
    <td class="min-w-0"><div class="text-truncate" style="max-width: 28rem"><?= hx_link(with_back('/tickets/' . $id, $here), e($t['subject']), 'text-dark') ?><?php if ($t['needs_reply']): ?> <span class="badge bg-soft-warning text-warning"><i class="feather-corner-up-left"></i></span><?php endif; ?></div>
        <div class="fs-11 text-muted"><?php foreach (array_slice($t['tags'], 0, 3) as $g): ?><span class="badge bg-soft-secondary text-secondary me-1"><?= e($g) ?></span><?php endforeach; ?><?= count($t['tags']) > 3 ? '+' . (count($t['tags']) - 3) : '' ?></div></td>
    <td class="fs-12"><?= e($t['requester_name']) ?><?= $t['organization_name'] ? '<br><small class="text-muted">' . e($t['organization_name']) . '</small>' : '' ?></td>
    <td class="fs-12"><?= e($t['queue_name']) ?></td>
    <td class="fs-12"><?= $t['assignee_name'] ? e($t['assignee_name']) . ($t['assignee_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info"><i class="feather-cpu"></i> agent</span>' : '') : '<span class="text-muted">—</span>' ?></td>
    <td><span class="badge bg-<?= e($t['status_color']) ?>"><?= e($t['status_label']) ?></span></td>
    <td><span class="badge bg-soft-<?= e(PRIORITY_COLORS[$t['priority']]) ?> text-<?= e(PRIORITY_COLORS[$t['priority']]) ?>"><?= e($t['priority']) ?></span></td>
    <td class="fs-12 text-nowrap <?= $sla === 'breached' ? 'text-danger' : ($sla === 'at_risk' ? 'text-warning' : 'text-muted') ?>"><?= e(sla_due_words($t['sla_next_due_at'], $sla)) ?></td>
    <td class="fs-12 text-muted text-nowrap"><?= e(format_ts($t['last_activity_at'], $tz, 'M j, g:i A')) ?></td>
</tr>
