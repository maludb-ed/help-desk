<?php /** Requesters (screen `requesters-list`). Data: rows, organizations, filters, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'requesters-list', 'title' => 'Requesters', 'crumbs' => [['Home', '/'], ['Requesters', null]],
    'action' => hx_link('/requesters/new', '<i class="feather-plus me-1"></i>Add', 'btn btn-primary btn-touch', 'id="requesters-list-add-btn"')]) ?>
<div class="main-content" id="requesters-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/requesters/" hx-get="/requesters/" hx-target="#page-content" hx-push-url="true" hx-trigger="submit, change from:#requesters-filter-kind, change from:#requesters-filter-organization" class="card mb-3" id="requesters-filters"><div class="card-body d-flex flex-wrap gap-2">
        <input type="search" name="q" id="requesters-filter-q" class="form-control btn-touch flex-grow-1" placeholder="Name or email…" value="<?= e($filters['q']) ?>" maxlength="80" aria-label="Search">
        <select name="kind" id="requesters-filter-kind" class="form-select btn-touch w-auto" aria-label="Kind"><option value="">Anyone</option><?php foreach (REQUESTER_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <div style="min-width: 14rem"><?= picker_field(['id' => 'requesters-filter-organization', 'name' => 'organization', 'source' => 'organization', 'value' => $filters['organization'], 'placeholder' => 'Any company', 'search' => 'Search organizations…']) ?></div>
        <button type="submit" class="btn btn-light btn-touch" id="requesters-filter-btn" aria-label="Search"><i class="feather-search"></i></button>
    </div></form>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="requesters-list-empty">Nobody has asked for anything yet.</div></div><?php endif; ?>
    <div class="row g-3" id="requesters-list-cards">
        <?php foreach ($rows as $r): ?><div class="col-12 col-md-6 col-xl-4"><?= view('requesters/partials/requester-card.php', ['r' => $r, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
</div>
