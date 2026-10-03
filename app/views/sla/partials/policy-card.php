<?php /** One policy as a card. Data: p, first, last, here */ $id = (int) $p['policy_id']; ?>
<div class="card<?= $p['active'] ? '' : ' border-dashed text-muted' ?>" id="policy-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><div class="fw-bold" id="policy-card-<?= $id ?>-name"><?= hx_link(with_back('/settings/sla/' . $id . '/edit', $here), e($p['name']), 'text-dark') ?><?= $p['name'] === 'Standard' ? ' <span class="badge bg-soft-secondary text-secondary">applies last</span>' : '' ?></div>
            <div class="fs-12 text-muted" id="policy-card-<?= $id ?>-matches">matches: <?= e($p['matches']) ?></div></div>
        <div class="d-flex gap-1">
            <form method="post" action="/settings/sla/save.php" hx-post="/settings/sla/save.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="policy" value="<?= $id ?>"><input type="hidden" name="move" value="up"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="policy-card-<?= $id ?>-up-btn" aria-label="Move up" <?= $first ? 'disabled' : '' ?>><i class="feather-arrow-up"></i></button></form>
            <form method="post" action="/settings/sla/save.php" hx-post="/settings/sla/save.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="policy" value="<?= $id ?>"><input type="hidden" name="move" value="down"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="policy-card-<?= $id ?>-down-btn" aria-label="Move down" <?= $last ? 'disabled' : '' ?>><i class="feather-arrow-down"></i></button></form>
        </div>
    </div>
    <div class="mt-2"><?= view('sla/partials/targets-grid.php', ['targets' => $p['targets'], 'prefix' => 'policy-' . $id . '-targets']) ?></div>
    <div class="fs-12 text-muted mt-2" id="policy-card-<?= $id ?>-facts"><?= $p['business_hours_id'] === null ? 'Calendar hours' : 'On ' . e($p['schedule_name']) ?> · warns at <?= (int) $p['warning_percent'] ?>%</div>
    <div class="row g-2 mt-1">
        <div class="col-6"><?= hx_link(with_back('/settings/sla/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch w-100', 'id="policy-card-' . $id . '-edit-btn"') ?></div>
        <div class="col-6"><?php if ($p['name'] !== 'Standard'): ?><form method="post" action="/settings/sla/archive.php" hx-post="/settings/sla/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($p['name']) ?>? Tickets keep their targets; new ones match the next policy."><?= csrf_field() ?><input type="hidden" name="policy" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="policy-card-<?= $id ?>-archive-btn">Archive</button></form><?php endif; ?></div>
    </div>
</div></div>
