<?php /** Organizations (screen `organizations-list`). Data: rows, tiers, filters, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'organizations-list', 'title' => 'Organizations', 'crumbs' => [['Home', '/'], ['Organizations', null]],
    'action' => hx_link('/organizations/new', '<i class="feather-plus me-1"></i>Add', 'btn btn-primary btn-touch', 'id="organizations-list-add-btn"')]) ?>
<div class="main-content" id="organizations-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/organizations/" hx-get="/organizations/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:#organizations-filter-tier" class="card mb-3" id="organizations-filters"><div class="card-body d-flex flex-wrap gap-2">
        <input type="search" name="q" id="organizations-filter-q" class="form-control btn-touch flex-grow-1" placeholder="Name or domain…" value="<?= e($filters['q']) ?>" maxlength="80" aria-label="Search">
        <select name="tier" id="organizations-filter-tier" class="form-select btn-touch w-auto" aria-label="Tier"><option value="">Any tier</option><?php foreach ($tiers as $t): ?><option value="<?= e($t) ?>" <?= $filters['tier'] === $t ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-light btn-touch" id="organizations-filter-btn" aria-label="Search"><i class="feather-search"></i></button>
    </div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="organizations-list-empty">No customer companies yet. Add one, or they appear when a customer writes in.</div></div><?php endif; ?>
    <div class="row g-3" id="organizations-list-cards">
        <?php foreach ($rows as $o): ?><div class="col-12 col-md-6 col-xl-4"><?= view('organizations/partials/organization-card.php', ['o' => $o, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
</div>
