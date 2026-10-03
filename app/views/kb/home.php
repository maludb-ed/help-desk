<?php /** The knowledge base (screen `kb-home`). Data: sections (top level with children), q, results, filter, askUrl, canEdit, public (bool: the bare layout) */ ?>
<?php if (!$public): ?><?= view('shared/header.php', ['id' => 'kb-home', 'title' => 'Knowledge base', 'crumbs' => [['Home', '/'], ['Knowledge base', null]], 'action' => $canEdit ? hx_link('/kb/admin/', '<i class="feather-edit-3 me-1"></i>Manage', 'btn btn-light btn-touch', 'id="kb-admin-link"') : '']) ?><?php endif; ?>
<div class="<?= $public ? '' : 'main-content' ?>" id="kb-home-content">
    <?php if ($public): ?><h4 class="mb-3">Knowledge base</h4><?php endif; ?>
    <form method="get" action="/kb/" hx-get="/kb/" hx-target="#kb-results" hx-select="#kb-results" hx-swap="outerHTML" hx-trigger="submit, input changed delay:300ms from:#kb-search" hx-push-url="true" class="card mb-3" id="kb-search-form"><div class="card-body d-flex gap-2">
        <input type="search" name="q" id="kb-search" class="form-control btn-touch" placeholder="Search for an answer…" value="<?= e($q) ?>" maxlength="120" aria-label="Search">
        <button type="submit" class="btn btn-light btn-touch" id="kb-search-btn" aria-label="Search"><i class="feather-search"></i></button>
    </div></form>
    <?= view('kb/partials/search-results.php', ['q' => $q, 'rows' => $results, 'filter' => $filter, 'askUrl' => $askUrl]) ?>
    <?php if ($q === ''): ?>
        <?php if ($sections === []): ?><div class="card mt-3"><div class="card-body text-muted" id="kb-empty">No articles yet.<?= $canEdit ? ' ' . hx_link('/kb/admin/new', 'Write the first one', 'btn btn-primary btn-touch ms-2', 'id="kb-write-first"') : '' ?></div></div>
        <?php else: ?><div class="row g-2 mt-1" id="kb-sections"><?php foreach ($sections as $s): ?><?= view('kb/partials/section-card.php', ['s' => $s, 'children' => $s['children'], 'filter' => $filter]) ?><?php endforeach; ?></div><?php endif; ?>
    <?php endif; ?>
</div>
