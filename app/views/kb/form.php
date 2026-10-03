<?php /** Write or change an article (screens `article-add`, `article-edit`). Data: cur, start (title, body, section_id, slug, visibility, tags, from_ticket), sections, preview (html|null), canDelete, here */ $id = $cur['article_id'] ?? null; $screen = $cur === null ? 'article-add' : 'article-edit'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Write an article' : 'Change ' . $cur['title'], 'crumbs' => [['Home', '/'], ['Knowledge base', '/kb/'], ['Articles', '/kb/admin/'], [$cur === null ? 'New' : $cur['title'], null]], 'back' => back_link() ?? ['/kb/admin/', 'Articles']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/kb/admin/save.php" hx-post="/kb/admin/save.php" hx-target="#flash" id="article-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="article" value="<?= (int) $id ?>"><?php endif; ?><?php if (!empty($start['from_ticket'])): ?><input type="hidden" name="from_ticket" value="<?= (int) $start['from_ticket'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="article-form-field-title">Title</label>
            <input type="text" name="title" id="article-form-field-title" class="form-control btn-touch mb-3" maxlength="200" required value="<?= e($start['title']) ?>">
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="article-form-field-slug">Address (/kb/…)</label><input type="text" name="slug" id="article-form-field-slug" class="form-control btn-touch" maxlength="120" pattern="[a-z0-9][a-z0-9\-]*" value="<?= e($start['slug']) ?>" placeholder="derived from the title"></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="article-form-field-section">Section</label><select name="section" id="article-form-field-section" class="form-select btn-touch"><option value="">No section</option><?php foreach ($sections as $s): ?><option value="<?= (int) $s['section_id'] ?>" <?= $start['section_id'] === (int) $s['section_id'] ? 'selected' : '' ?>><?= e(($s['parent_id'] ? '· ' : '') . $s['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <label class="form-label fs-12 text-muted">Who may read it</label>
            <div class="d-flex flex-wrap gap-2 mb-3" id="article-form-field-visibility"><?php foreach (KB_VISIBILITIES as $k => $l): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="article-form-field-visibility-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="visibility" value="<?= e($k) ?>" id="article-form-field-visibility-<?= e($k) ?>" <?= $start['visibility'] === $k ? 'checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?></div>
            <label class="form-label fs-12 text-muted" for="article-form-field-tags">Tags (comma-separated)</label>
            <input type="text" name="tags" id="article-form-field-tags" class="form-control btn-touch mb-3" maxlength="300" value="<?= e(implode(', ', $start['tags'])) ?>">
            <div class="d-flex gap-1 mb-2" id="article-form-tabs">
                <button type="button" class="btn btn-primary btn-touch" id="article-form-tab-write" onclick="document.getElementById('article-form-write').classList.remove('d-none'); document.getElementById('article-preview').classList.add('d-none'); this.classList.add('btn-primary'); this.classList.remove('btn-light'); document.getElementById('article-form-tab-preview').classList.add('btn-light'); document.getElementById('article-form-tab-preview').classList.remove('btn-primary');">Write</button>
                <button type="button" class="btn btn-light btn-touch" id="article-form-tab-preview" hx-get="/kb/admin/<?= $id !== null ? $id . '/edit' : 'new' ?>?preview=1" hx-include="#article-form-field-body" hx-target="#article-preview" hx-select="#article-preview" hx-swap="outerHTML" onclick="document.getElementById('article-form-write').classList.add('d-none'); document.getElementById('article-preview').classList.remove('d-none'); this.classList.add('btn-primary'); this.classList.remove('btn-light'); document.getElementById('article-form-tab-write').classList.add('btn-light'); document.getElementById('article-form-tab-write').classList.remove('btn-primary');">Preview</button>
            </div>
            <div id="article-form-write"><textarea name="body" id="article-form-field-body" class="form-control font-monospace fs-12" rows="18" maxlength="200000" placeholder="# Heading&#10;&#10;Write in Markdown…"><?= e($start['body']) ?></textarea>
                <div class="fs-11 text-muted mt-1">Markdown: # headings, **bold**, *italic*, `code`, ``` fenced code, - lists, 1. lists, > quotes, [links](https://…), --- rules.</div></div>
            <div id="article-preview" class="<?= $preview === null ? 'd-none' : '' ?>"><div class="article-body border rounded p-3 bg-light"><?= $preview ?? '' ?></div></div>
        </div></div>
        <div class="d-flex gap-2 mb-2">
            <button type="submit" name="status" value="draft" class="btn btn-light btn-touch flex-grow-1" id="article-form-save-draft-btn">Save draft</button>
            <button type="submit" name="status" value="published" class="btn btn-primary btn-touch flex-grow-1" id="article-form-publish-btn">Save and publish</button>
        </div>
        <?= hx_link('/kb/admin/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="article-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null): ?>
    <div class="card mb-3" id="article-form-attachments"><div class="card-body">
        <div class="fw-semibold mb-2">Attachments</div>
        <?php foreach (article_attachments(db(), (int) $id) as $f): ?><div class="d-flex align-items-center gap-2 mb-1 fs-12" id="article-form-attachment-<?= (int) $f['attachment_id'] ?>"><i class="feather-paperclip"></i><a href="/kb/attachment.php?id=<?= (int) $f['attachment_id'] ?>"><?= e($f['filename']) ?></a><code class="text-muted">![](/kb/attachment.php?id=<?= (int) $f['attachment_id'] ?>)</code></div><?php endforeach; ?>
        <form method="post" action="/tickets/attachments/save.php" enctype="multipart/form-data" class="d-flex gap-2 align-items-center mt-2" id="article-attach-form"><?= csrf_field() ?><input type="hidden" name="article" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="/kb/admin/<?= (int) $id ?>/edit">
            <input type="file" name="file" id="article-attach-form-field-file" class="form-control btn-touch" required><button type="submit" class="btn btn-light btn-touch" id="article-attach-form-btn"><i class="feather-upload"></i></button></form>
    </div></div>
    <div class="d-flex gap-2">
        <form method="post" action="/kb/admin/archive.php" hx-post="/kb/admin/archive.php" hx-target="#flash" hx-confirm="Archive <?= e($cur['title']) ?>?" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="article" value="<?= (int) $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="article-form-archive-btn">Archive</button></form>
        <?php if ($canDelete): ?><form method="post" action="/kb/admin/delete.php" hx-post="/kb/admin/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($cur['title']) ?> for good?" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="article" value="<?= (int) $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="article-form-delete-btn">Delete</button></form><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
