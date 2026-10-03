<?php /** The rules (screen `rules-list`). Data: groups [[kind, label, rows]], may, notice */ ?>
<?= view('shared/header.php', ['id' => 'rules-list', 'title' => 'Automation', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Automation', null]],
    'action' => $may ? '<div class="d-flex gap-1 flex-wrap justify-content-end">' . hx_link('/settings/automation/new?kind=trigger', '<i class="feather-zap"></i><span class="d-none d-md-inline ms-1">Trigger</span>', 'btn btn-primary btn-touch', 'id="rules-new-trigger" title="New trigger" aria-label="New trigger"') . hx_link('/settings/automation/new?kind=time_based', '<i class="feather-clock"></i><span class="d-none d-md-inline ms-1">Time-based</span>', 'btn btn-light btn-touch', 'id="rules-new-time" title="New time-based rule" aria-label="New time-based rule"') . hx_link('/settings/automation/new?kind=assignment', '<i class="feather-user-check"></i><span class="d-none d-md-inline ms-1">Assignment</span>', 'btn btn-light btn-touch', 'id="rules-new-assignment" title="New assignment rule" aria-label="New assignment rule"') . '</div>' : '']) ?>
<div class="main-content" id="rules-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php foreach ($groups as [$kind, $label, $rows]): ?>
        <h6 class="fw-bold mt-2 mb-2" id="rules-group-<?= e($kind) ?>"><?= e($label) ?></h6>
        <?php if ($rows === []): ?><div class="card mb-3"><div class="card-body text-muted fs-12" id="rules-<?= e($kind) ?>-empty">No <?= e(strtolower($label)) ?> yet.</div></div>
        <?php else: ?><div class="row g-2 mb-3" id="rules-<?= e($kind) ?>"><?php foreach ($rows as $r): ?><?= view('automation/partials/rule-card.php', ['r' => $r, 'may' => $may]) ?><?php endforeach; ?></div><?php endif; ?>
    <?php endforeach; ?>
</div>
