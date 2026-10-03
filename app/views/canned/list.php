<?php /** The canned responses I may use (screen `canned-list`). Data: groups [[key, label, rows]], q, folder, folders, notice */ ?>
<?= view('shared/header.php', ['id' => 'canned-list', 'title' => 'Canned responses', 'crumbs' => [['Home', '/'], ['Canned responses', null]],
    'action' => hx_link('/canned/new', '<i class="feather-plus-circle me-1"></i>New response', 'btn btn-primary btn-touch', 'id="canned-new-btn"')]) ?>
<div class="main-content" id="canned-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/canned/" hx-get="/canned/" hx-target="#canned-list" hx-select="#canned-list" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change from:#canned-filter-folder, keyup changed delay:300ms from:#canned-filter-q" class="card mb-3" id="canned-filters"><div class="card-body d-flex flex-wrap gap-2">
        <input type="search" name="q" id="canned-filter-q" class="form-control btn-touch flex-grow-1" placeholder="Words in the title or the text…" value="<?= e($q) ?>" maxlength="80" aria-label="Search">
        <select name="folder" id="canned-filter-folder" class="form-select btn-touch w-auto" aria-label="Folder"><option value="">Any folder</option><?php foreach ($folders as $f): ?><option value="<?= e($f) ?>" <?= $folder === $f ? 'selected' : '' ?>><?= e($f) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-light btn-touch" id="canned-filter-btn" aria-label="Search"><i class="feather-search"></i></button>
    </div></form>
    <div id="canned-list">
        <?php $any = false; foreach ($groups as [$key, $label, $rows]): if ($rows === []) { continue; } $any = true; ?>
            <h6 class="fw-bold mt-2 mb-2" id="canned-group-<?= e($key) ?>"><?= e($label) ?></h6>
            <div class="row g-2 mb-3"><?php foreach ($rows as $c): ?><?= view('canned/partials/canned-card.php', ['c' => $c]) ?><?php endforeach; ?></div>
        <?php endforeach; ?>
        <?php if (!$any): ?><div class="card"><div class="card-body text-muted" id="canned-empty">No canned responses yet — write one from a reply you keep typing.</div></div><?php endif; ?>
    </div>
</div>
