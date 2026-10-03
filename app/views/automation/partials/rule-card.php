<?php /** One rule as a card. Data: r, may (bool: settings.manage) */ $id = (int) $r['rule_id']; ?>
<div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0 <?= $r['active'] ? '' : 'opacity-75' ?>" id="rule-card-<?= $id ?>"><div class="card-body d-flex flex-column gap-2">
    <div class="d-flex align-items-start gap-2">
        <div class="min-w-0 flex-grow-1"><div class="fw-bold" id="rule-card-<?= $id ?>-name"><?= e($r['name']) ?><?= $r['built_in'] ? ' <span class="badge bg-soft-secondary text-secondary">Built in</span>' : '' ?></div>
            <div class="fs-11 text-muted" id="rule-card-<?= $id ?>-words"><?= e(rule_words($r)) ?></div></div>
        <?php if ($may): ?>
        <form method="post" action="/settings/automation/toggle.php" hx-post="/settings/automation/toggle.php" hx-target="#flash" class="mb-0"><?= csrf_field() ?><input type="hidden" name="rule" value="<?= $id ?>"><input type="hidden" name="active" value="<?= $r['active'] ? 'no' : 'yes' ?>">
            <button type="submit" class="btn btn-touch <?= $r['active'] ? 'btn-success' : 'btn-light' ?>" id="rule-card-<?= $id ?>-toggle" title="<?= $r['active'] ? 'On — click to turn off' : 'Off — click to turn on' ?>"><i class="feather-power"></i></button></form>
        <?php else: ?><span class="badge bg-soft-<?= $r['active'] ? 'success text-success' : 'secondary text-secondary' ?>"><?= $r['active'] ? 'on' : 'off' ?></span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted" id="rule-card-<?= $id ?>-runs">Runs: <?= (int) $r['run_count'] ?> · last <?= e(ago_words($r['last_run_at'])) ?></div>
    <div class="d-flex gap-1 mt-auto flex-wrap">
        <?= hx_link('/settings/automation/' . $id . '/runs', 'Runs', 'btn btn-light btn-touch', 'id="rule-card-' . $id . '-runs-link"') ?>
        <?php if ($may): ?><?= hx_link('/settings/automation/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="rule-card-' . $id . '-edit"') ?>
        <?php if ($r['kind'] === 'time_based'): ?><form method="post" action="/settings/automation/run.php" hx-post="/settings/automation/run.php" hx-target="#flash" hx-confirm="Run <?= e($r['name']) ?> now on every matching ticket?" class="mb-0"><?= csrf_field() ?><input type="hidden" name="rule" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="rule-card-<?= $id ?>-run">Run now</button></form><?php endif; ?>
        <?php endif; ?>
    </div>
</div></div></div>
