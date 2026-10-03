<?php /** The views I may open (screen `views-list`). Data: standard, mine, shared, notice, canSave */ ?>
<?= view('shared/header.php', ['id' => 'views-list', 'title' => 'Views', 'crumbs' => [['Home', '/'], ['Views', null]],
    'action' => $canSave ? hx_link('/views/new', '<i class="feather-plus-circle me-1"></i>New view', 'btn btn-primary btn-touch', 'id="views-new-btn"') : '']) ?>
<div class="main-content" id="views-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php foreach ([['standard', 'Standard', $standard], ['mine', 'Mine', $mine], ['shared', 'Shared', $shared]] as [$key, $label, $rows]): ?>
        <h6 class="fw-bold mt-2 mb-2" id="views-group-<?= e($key) ?>"><?= e($label) ?></h6>
        <?php if ($rows === []): ?>
            <div class="card mb-3"><div class="card-body text-muted fs-12" id="views-<?= e($key) ?>-empty"><?= $key === 'mine' ? 'Save any filtered list as a view — from the inbox, Save as view.' : 'Nothing here yet.' ?></div></div>
        <?php else: ?>
            <div class="row g-2 mb-3" id="views-<?= e($key) ?>"><?php foreach ($rows as $v): ?><?= view('views/partials/view-card.php', ['v' => $v]) ?><?php endforeach; ?></div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
