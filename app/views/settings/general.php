<?php /** The business's Help Desk settings (screen `general-settings`). Data: s, queues, notice */ ?>
<?= view('shared/header.php', ['id' => 'general-settings', 'title' => 'Help Desk settings', 'crumbs' => [['Home', '/'], ['My settings', '/settings/'], ['Help Desk settings', null]], 'back' => back_link() ?? ['/settings/', 'My settings']]) ?>
<div class="main-content" id="general-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/settings/general.php" hx-post="/settings/general.php" hx-target="#flash" id="settings-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Tickets</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="settings-form-field-prefix">Number prefix (capital letters and digits, up to 8; changing it renumbers nothing)</label>
            <input type="text" name="ticket_prefix" id="settings-form-field-prefix" class="form-control btn-touch mb-3" maxlength="8" pattern="[A-Z][A-Z0-9]{0,7}" required value="<?= e($s['ticket_prefix']) ?>">
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-autoclose">Auto-close after (days solved)</label><input type="number" name="auto_close_days" id="settings-form-field-autoclose" class="form-control btn-touch" min="0" max="365" value="<?= (int) $s['auto_close_days'] ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-reopen">Reopen window (days)</label><input type="number" name="reopen_window_days" id="settings-form-field-reopen" class="form-control btn-touch" min="0" max="365" value="<?= (int) $s['reopen_window_days'] ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="settings-form-field-link">Secure link lives (days after close)</label><input type="number" name="secure_link_days" id="settings-form-field-link" class="form-control btn-touch" min="1" max="3650" value="<?= (int) $s['secure_link_days'] ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="settings-form-field-queue">Default queue (where a ticket lands when nothing routes it)</label>
            <select name="default_queue" id="settings-form-field-queue" class="form-select btn-touch mb-3"><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= $s['default_queue_id'] === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="settings-form-field-timezone">The business's time zone</label>
            <select name="timezone" id="settings-form-field-timezone" class="form-select btn-touch"><?php foreach (DateTimeZone::listIdentifiers() as $z): ?><option value="<?= e($z) ?>" <?= $s['timezone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Doors and visibility</h5></div><div class="card-body">
            <input type="hidden" name="request_form_enabled" value="no"><input type="hidden" name="org_visibility_default" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="settings-form-field-form"><input type="checkbox" class="form-check-input mt-0" name="request_form_enabled" value="yes" id="settings-form-field-form" <?= $s['request_form_enabled'] ? 'checked' : '' ?>>The public request form is open (/request)</label>
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="settings-form-field-orgvis"><input type="checkbox" class="form-check-input mt-0" name="org_visibility_default" value="yes" id="settings-form-field-orgvis" <?= $s['org_visibility_default'] ? 'checked' : '' ?>>An organization's people see each other's tickets, unless the organization says otherwise</label>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="settings-form-save-btn">Save</button>
    </form>
</div>
