<?php /** Write or change a canned response (screens `canned-add`, `canned-edit`). Data: cur, statuses, assignees, queues (held, where I may share), folders, canGlobal, here */
$id = $cur['canned_id'] ?? null; $screen = $cur === null ? 'canned-add' : 'canned-edit'; $se = $cur['side_effects'] ?? []; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New canned response' : 'Change ' . $cur['title'], 'crumbs' => [['Home', '/'], ['Canned responses', '/canned/'], [$cur === null ? 'New' : $cur['title'], null]], 'back' => back_link() ?? ['/canned/', 'Canned responses']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/canned/save.php" hx-post="/canned/save.php" hx-target="#flash" id="canned-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="canned" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="row g-3">
        <div class="col-12 col-lg-7">
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="canned-form-field-title">Title</label>
            <input type="text" name="title" id="canned-form-field-title" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['title'] ?? '') ?>" placeholder="Password reset steps">
            <label class="form-label fs-12 text-muted" for="canned-form-field-folder">Folder</label>
            <input type="text" name="folder" id="canned-form-field-folder" class="form-control btn-touch mb-3" maxlength="60" list="canned-folders" value="<?= e($cur['folder'] ?? '') ?>" placeholder="Accounts"><datalist id="canned-folders"><?php foreach ($folders as $f): ?><option value="<?= e($f) ?>"><?php endforeach; ?></datalist>
            <label class="form-label fs-12 text-muted">Who may use it</label>
            <div class="d-flex flex-wrap gap-2 mb-3" id="canned-form-field-scope">
                <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="canned-form-field-scope-personal"><input type="radio" class="form-check-input mt-0" name="scope" value="personal" id="canned-form-field-scope-personal" <?= ($cur['scope'] ?? 'personal') === 'personal' ? 'checked' : '' ?>>Me</label>
                <?php if ($queues !== []): ?>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="canned-form-field-scope-queue"><input type="radio" class="form-check-input mt-0" name="scope" value="queue" id="canned-form-field-scope-queue" <?= ($cur['scope'] ?? '') === 'queue' ? 'checked' : '' ?>>A queue</label>
                    <select name="queue" id="canned-form-field-queue" class="form-select btn-touch w-auto" aria-label="Queue"><option value="">Which queue…</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= ($cur['queue_id'] ?? null) === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
                <?php endif; ?>
                <?php if ($canGlobal): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="canned-form-field-scope-global"><input type="radio" class="form-check-input mt-0" name="scope" value="global" id="canned-form-field-scope-global" <?= ($cur['scope'] ?? '') === 'global' ? 'checked' : '' ?>>Everyone</label><?php endif; ?>
            </div>
            <label class="form-label fs-12 text-muted" for="canned-form-field-body">The text</label>
            <textarea name="body" id="canned-form-field-body" class="form-control composer-body mb-2" rows="8" required maxlength="20000" placeholder="Hi {{requester.first_name}}, …"
                      hx-post="/canned/apply.php" hx-vals='{"preview": "1"}' hx-trigger="keyup changed delay:500ms, load" hx-target="#canned-preview" hx-swap="outerHTML" hx-include="#canned-form"><?= e($cur['body_text'] ?? '') ?></textarea>
            <div class="fs-11 text-muted mb-1">Placeholders (click to insert):</div>
            <div class="d-flex flex-wrap gap-1 mb-3" id="canned-form-placeholders"><?php foreach (canned_placeholders() as $k => $what): ?><button type="button" class="btn btn-light btn-sm canned-placeholder" data-placeholder="{{<?= e($k) ?>}}" title="<?= e($what) ?>" id="canned-form-placeholder-<?= e(str_replace('.', '-', $k)) ?>">{{<?= e($k) ?>}}</button><?php endforeach; ?></div>
        </div></div>
        <div class="card mb-3" id="canned-form-effects"><div class="card-header"><h5 class="mb-0">Side-effects when used</h5></div><div class="card-body">
            <div class="row g-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="canned-form-field-se-status">Set status</label><select name="side_effects[status]" id="canned-form-field-se-status" class="form-select btn-touch"><option value="">Leave it</option><?php foreach ($statuses as $s): ?><option value="<?= e($s['key']) ?>" <?= ($se['status'] ?? '') === $s['key'] ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="canned-form-field-se-priority">Set priority</label><select name="side_effects[priority]" id="canned-form-field-se-priority" class="form-select btn-touch"><option value="">Leave it</option><?php foreach (VIEW_PRIORITIES as $p): ?><option value="<?= e($p) ?>" <?= ($se['priority'] ?? '') === $p ? 'selected' : '' ?>><?= e(ucfirst($p)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="canned-form-field-se-assign">Assign</label><select name="side_effects[assign]" id="canned-form-field-se-assign" class="form-select btn-touch"><option value="">Leave it</option><option value="me" <?= ($se['assign'] ?? '') === 'me' ? 'selected' : '' ?>>Me (whoever uses it)</option><option value="nobody" <?= ($se['assign'] ?? '') === 'nobody' ? 'selected' : '' ?>>Nobody</option><?php foreach ($assignees as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= (string) ($se['assign'] ?? '') === (string) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?><?= $m['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="canned-form-field-se-tags">Add tags</label><input type="text" name="side_effects[add_tags]" id="canned-form-field-se-tags" class="form-control btn-touch" maxlength="200" value="<?= e(implode(', ', $se['add_tags'] ?? [])) ?>" placeholder="billing, password"></div>
            </div>
        </div></div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="card mb-3" id="canned-form-preview-card"><div class="card-header"><h5 class="mb-0">Preview</h5><div class="fs-11 text-muted">Against a sample ticket; nothing is saved.</div></div><div class="card-body"><div id="canned-preview"><div class="text-muted fs-12">Type to see it.</div></div></div></div>
        </div>
        </div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="canned-form-save-btn">Save</button>
        <?= hx_link('/canned/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="canned-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && canned_may_change($cur)): ?>
        <form method="post" action="/canned/delete.php" hx-post="/canned/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($cur['title']) ?>?"><?= csrf_field() ?><input type="hidden" name="canned" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="canned-form-delete-btn">Delete</button></form>
    <?php endif; ?>
    <script>
    // A placeholder button inserts at the caret of the text (an enhancement; the placeholders can be typed).
    (function () {
        var ta = document.getElementById('canned-form-field-body');
        document.querySelectorAll('#canned-form-placeholders .canned-placeholder').forEach(function (b) {
            b.addEventListener('click', function () {
                var s = ta.selectionStart || 0, e = ta.selectionEnd || s, v = ta.value;
                ta.value = v.slice(0, s) + b.dataset.placeholder + v.slice(e);
                ta.focus(); ta.selectionStart = ta.selectionEnd = s + b.dataset.placeholder.length;
                if (window.htmx) { htmx.trigger(ta, 'keyup'); }
            });
        });
    })();
    </script>
</div>
