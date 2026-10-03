<?php /** One board card (the shape board_snapshot reuses). Data: t, field, view, moves (the move table), expand (null | [ticket, to (status row), requires[], label]), query (the board's), tz, here */
$id = (int) $t['ticket_id']; $sla = $t['sla_state'] ?? null; $pc = PRIORITY_COLORS[$t['priority']] ?? 'secondary'; $col = board_value_of($t, $field);
$ex = ($expand !== null && (int) $expand['ticket'] === $id) ? $expand : null; $viewParam = $view['system_key'] ?? (string) $view['view_id']; ?>
<div class="card board-card mb-2 sla-<?= e($sla ?? 'none') ?>" id="board-card-<?= $id ?>" data-ticket-id="<?= $id ?>" data-column="<?= e($col) ?>" data-number="<?= e($t['number']) ?>"><div class="card-body">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><?= hx_link(with_back('/tickets/' . $id, $here), '<span class="text-muted fs-11">' . e($t['number']) . '</span> <span class="fw-semibold fs-12">' . e($t['subject']) . '</span>', 'text-dark', 'id="board-card-' . $id . '-link"') ?></div>
        <span class="badge bg-<?= e($pc) ?> text-nowrap"><?= e($t['priority']) ?></span>
    </div>
    <div class="fs-11 text-muted text-truncate"><?= e($t['requester_name']) ?><?= $t['organization_name'] ? ' <small>· ' . e($t['organization_name']) . '</small>' : '' ?></div>
    <div class="fs-11 d-flex flex-wrap gap-1 align-items-center mt-1">
        <span class="<?= $t['assignee_name'] ? '' : 'text-muted' ?>"><?= $t['assignee_name'] ? e($t['assignee_name']) . ($t['assignee_kind'] === 'agent' ? ' <i class="feather-cpu" title="an agent"></i>' : '') : 'Unassigned' ?></span>
        <?php if ($t['needs_reply']): ?><span class="badge bg-soft-warning text-warning" title="needs a reply"><i class="feather-corner-up-left"></i></span><?php endif; ?>
        <?php if (($w = sla_due_words($t['sla_next_due_at'], $sla)) !== ''): ?><span class="badge bg-soft-<?= $sla === 'breached' ? 'danger text-danger' : ($sla === 'at_risk' ? 'warning text-warning' : 'secondary text-secondary') ?>"><?= e($w) ?></span><?php endif; ?>
        <?php foreach (array_slice($t['tags'], 0, 3) as $g): ?><span class="badge bg-soft-secondary text-secondary"><?= e($g) ?></span><?php endforeach; ?>
        <span class="text-muted ms-auto" title="<?= e(format_ts($t['created_at'], $tz)) ?>"><?= e(age_words($t['created_at'])) ?></span>
    </div>
    <?php if ($ex !== null): ?>
        <form method="post" action="/board/move.php" hx-post="/board/move.php" hx-target="closest .board-card" hx-select=".board-card" hx-swap="outerHTML" class="mt-2 border-top pt-2" id="board-card-<?= $id ?>-confirm">
            <?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="column" value="<?= e($ex['to']['key']) ?>"><input type="hidden" name="view" value="<?= e($viewParam) ?>">
            <div class="fs-12 fw-semibold mb-1"><?= e($ex['label']) ?> → <?= e($ex['to']['label']) ?></div>
            <?php foreach ($ex['requires'] as $need): ?>
                <?php if ($need === 'resolution_code'): ?><select name="resolution_code" class="form-select btn-touch mb-1" id="board-card-<?= $id ?>-field-resolution_code" required><option value="">Resolution code…</option><?php foreach (RESOLUTION_CODES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                <?php elseif ($need === 'hold_reason'): ?><select name="hold_reason" class="form-select btn-touch mb-1" id="board-card-<?= $id ?>-field-hold_reason" required><option value="">Hold reason…</option><?php foreach (HOLD_REASONS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                <?php elseif ($need === 'pending_until'): ?><input type="datetime-local" name="pending_until" class="form-control btn-touch mb-1" id="board-card-<?= $id ?>-field-pending_until" required>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="board-card-<?= $id ?>-confirm-btn">Confirm</button>
                <a href="<?= e('/board?' . http_build_query($query)) ?>" hx-get="<?= e('/board?' . http_build_query($query)) ?>" hx-target="#board-columns" hx-select="#board-columns" hx-swap="outerHTML" class="btn btn-light btn-touch" id="board-card-<?= $id ?>-cancel-btn">Cancel</a>
            </div>
        </form>
    <?php else: ?>
        <?= view('board/partials/move-select.php', ['t' => $t, 'field' => $field, 'moves' => $moves, 'viewParam' => $viewParam]) ?>
    <?php endif; ?>
</div></div>
