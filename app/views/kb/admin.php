<?php /** The editors' desk (screen `kb-admin`). Data: rows, status, sectionId, sections, allSections, notice, canDelete, page, more, tz */ ?>
<?= view('shared/header.php', ['id' => 'kb-admin', 'title' => 'Articles', 'crumbs' => [['Home', '/'], ['Knowledge base', '/kb/'], ['Articles', null]], 'action' => hx_link('/kb/admin/new', '<i class="feather-plus-circle me-1"></i>New article', 'btn btn-primary btn-touch', 'id="kb-admin-new"')]) ?>
<div class="main-content" id="kb-admin-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
    <div class="col-12 col-lg-8">
        <div class="d-flex flex-wrap gap-1 mb-3" id="kb-admin-tabs"><?php foreach (['published' => 'Published', 'draft' => 'Drafts', 'archived' => 'Archived'] as $k => $l): ?><?= hx_link('/kb/admin/?status=' . $k . ($sectionId !== null ? '&section=' . $sectionId : ''), e($l), 'btn btn-touch ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="kb-admin-tab-' . $k . '"') ?><?php endforeach; ?>
            <select class="form-select btn-touch w-auto ms-auto" id="kb-admin-section-filter" aria-label="Section" hx-get="/kb/admin/?status=<?= e($status) ?>" hx-target="#page-content" hx-push-url="true" hx-trigger="change" name="section"><option value="">Any section</option><option value="0" <?= $sectionId === 0 ? 'selected' : '' ?>>No section</option><?php foreach ($allSections as $s): ?><option value="<?= (int) $s['section_id'] ?>" <?= $sectionId === (int) $s['section_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="card" id="kb-admin-table"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12"><thead class="thead-light"><tr><th>Title</th><th>Section</th><th>Visibility</th><th>Views</th><th>👍 / 👎</th><th>Updated</th><th>By</th></tr></thead><tbody>
            <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="kb-admin-empty">Nothing here.</td></tr><?php endif; ?>
            <?php foreach ($rows as $a): $id = (int) $a['article_id']; ?><tr id="kb-admin-row-<?= $id ?>"><td><?= hx_link('/kb/admin/' . $id . '/edit', e($a['title']), 'fw-semibold', 'id="kb-admin-row-' . $id . '-edit"') ?></td><td><?= e($a['section_name'] ?? '—') ?></td><td><?= e(ucfirst($a['visibility'])) ?></td><td><?= (int) $a['view_count'] ?></td><td><?= (int) $a['helpful_count'] ?> / <?= (int) $a['unhelpful_count'] ?></td><td class="text-nowrap text-muted"><?= e(format_ts($a['updated_at'], $tz, 'M j, Y')) ?></td><td><?= e($a['author_name'] ?? '—') ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
        <?php if ($page > 1 || $more): ?><nav class="py-2"><ul class="pagination mb-0"><?php if ($page > 1): ?><li class="page-item"><?= hx_link('/kb/admin/?status=' . $status . '&page=' . ($page - 1), 'Newer', 'page-link') ?></li><?php endif; ?><?php if ($more): ?><li class="page-item"><?= hx_link('/kb/admin/?status=' . $status . '&page=' . ($page + 1), 'Older', 'page-link') ?></li><?php endif; ?></ul></nav><?php endif; ?>
    </div>
    <div class="col-12 col-lg-4">
        <div class="card" id="kb-admin-sections"><div class="card-header"><h5 class="mb-0">Sections</h5></div><div class="card-body">
            <?php foreach ($allSections as $s): $sid = (int) $s['section_id']; ?>
            <details class="mb-2" id="kb-admin-section-<?= $sid ?>"><summary class="btn-touch d-flex align-items-center gap-2"><span class="fw-semibold"><?= e(($s['parent_id'] ? '· ' : '') . $s['name']) ?></span><span class="badge bg-soft-<?= $s['visibility'] === 'public' ? 'success text-success' : 'secondary text-secondary' ?>"><?= e($s['visibility']) ?></span><span class="text-muted fs-12 ms-auto"><?= (int) $s['article_count'] ?></span></summary>
                <form method="post" action="/kb/admin/section.php" hx-post="/kb/admin/section.php" hx-target="#flash" class="mt-2" id="kb-admin-section-<?= $sid ?>-form"><?= csrf_field() ?><input type="hidden" name="section" value="<?= $sid ?>">
                    <input type="text" name="name" class="form-control btn-touch mb-1" value="<?= e($s['name']) ?>" maxlength="80" required aria-label="Name">
                    <input type="text" name="description" class="form-control btn-touch mb-1" value="<?= e($s['description'] ?? '') ?>" maxlength="300" placeholder="Description" aria-label="Description">
                    <div class="d-flex gap-1 mb-1"><select name="visibility" class="form-select btn-touch" aria-label="Visibility"><?php foreach (['public' => 'Public', 'internal' => 'Internal'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $s['visibility'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                        <select name="parent" class="form-select btn-touch" aria-label="Parent"><option value="">Top level</option><?php foreach ($allSections as $p): if ((int) $p['section_id'] === $sid || $p['parent_id'] !== null) { continue; } ?><option value="<?= (int) $p['section_id'] ?>" <?= $s['parent_id'] === (int) $p['section_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
                        <input type="number" name="sort_order" class="form-control btn-touch" value="<?= (int) $s['sort_order'] ?>" min="0" max="1000" aria-label="Order" style="max-width: 6rem"></div>
                    <div class="d-flex gap-1"><button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="kb-admin-section-<?= $sid ?>-save">Save</button>
                        <button type="submit" formaction="/kb/admin/section-archive.php" hx-post="/kb/admin/section-archive.php" hx-target="#flash" hx-confirm="Archive <?= e($s['name']) ?>? Its articles keep their rows under No section." class="btn btn-light btn-touch" id="kb-admin-section-<?= $sid ?>-archive">Archive</button></div>
                </form>
            </details>
            <?php endforeach; ?>
            <form method="post" action="/kb/admin/section.php" hx-post="/kb/admin/section.php" hx-target="#flash" class="mt-3 border-top pt-3" id="kb-admin-section-new"><?= csrf_field() ?>
                <label class="form-label fs-12 text-muted" for="kb-admin-section-new-name">Add a section</label>
                <input type="text" name="name" id="kb-admin-section-new-name" class="form-control btn-touch mb-1" maxlength="80" required placeholder="Getting started">
                <div class="d-flex gap-1"><select name="visibility" class="form-select btn-touch" aria-label="Visibility"><option value="public">Public</option><option value="internal">Internal</option></select><button type="submit" class="btn btn-light btn-touch" id="kb-admin-section-new-btn">Add</button></div>
            </form>
        </div></div>
    </div>
    </div>
</div>
