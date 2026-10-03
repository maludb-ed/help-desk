<?php /** Add or change a mailbox (screens `mailbox-add`, `mailbox-edit`). Data: cur, queues, host */ $id = $cur['id'] ?? null; $screen = $cur === null ? 'mailbox-add' : 'mailbox-edit'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a mailbox' : 'Change ' . $cur['address'], 'crumbs' => [['Home', '/'], ['Mailboxes', '/settings/mailboxes/'], [$cur === null ? 'Add' : $cur['address'], null]], 'back' => back_link() ?? ['/settings/mailboxes/', 'Mailboxes']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/mailboxes/save.php" hx-post="/settings/mailboxes/save.php" hx-target="#flash" id="mailbox-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="mailbox" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="mailbox-form-field-address">Address (the queue's, on MaluMail)</label>
            <input type="email" name="address" id="mailbox-form-field-address" class="form-control btn-touch mb-3" maxlength="200" required value="<?= e($cur['address'] ?? '') ?>" placeholder="support@yourbusiness.com">
            <label class="form-label fs-12 text-muted" for="mailbox-form-field-display_name">From name</label>
            <input type="text" name="display_name" id="mailbox-form-field-display_name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['display_name'] ?? '') ?>" placeholder="Support">
            <label class="form-label fs-12 text-muted" for="mailbox-form-field-queue">Queue</label>
            <select name="queue" id="mailbox-form-field-queue" class="form-select btn-touch mb-3" required><option value="">Choose…</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= ($cur['queue_id'] ?? null) === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="mailbox-form-field-ref">Webhook secret — the environment key holding it</label>
            <div class="input-group mb-1"><input type="text" name="webhook_secret_ref" id="mailbox-form-field-ref" class="form-control btn-touch" maxlength="80" pattern="[A-Z][A-Z0-9_]*" value="<?= e($cur['webhook_secret_ref'] ?? 'MAILBOX_SUPPORT_WEBHOOK_SECRET') ?>" placeholder="MAILBOX_SUPPORT_WEBHOOK_SECRET"><span class="input-group-text" id="mailbox-form-secret-state"><?= ($cur['secret_set'] ?? false) ? '✓ set' : 'not set' ?></span></div>
            <div class="fs-11 text-muted mb-3">The secret itself lives in <code>config/.env</code> under that key and is never shown here.</div>
            <label class="form-label fs-12 text-muted" for="mailbox-form-field-signature">Signature (appended to outgoing replies)</label>
            <textarea name="signature" id="mailbox-form-field-signature" class="form-control mb-3" rows="3" maxlength="1000"><?= e($cur['signature'] ?? '') ?></textarea>
            <input type="hidden" name="poll_enabled" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="mailbox-form-field-poll"><input type="checkbox" class="form-check-input mt-0" name="poll_enabled" value="yes" id="mailbox-form-field-poll" <?= ($cur['poll_enabled'] ?? true) ? 'checked' : '' ?>>Poll every minute for mail the webhook missed</label>
        </div></div>
        <div class="card mb-3" id="mailbox-form-howto"><div class="card-body fs-12">
            <div class="fw-semibold mb-1">In MaluMail</div>
            <ol class="mb-0 ps-3"><li>Create the mailbox for this address.</li><li>Point its new-mail webhook at <code><?= e($host) ?>/channels/malumail/mail</code> and give it a secret.</li><li>Put that secret in <code>config/.env</code> under the key above.</li></ol>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="mailbox-form-save-btn">Save</button>
        <?= hx_link('/settings/mailboxes/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="mailbox-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && $cur['active']): ?>
        <form method="post" action="/settings/mailboxes/archive.php" hx-post="/settings/mailboxes/archive.php" hx-target="#flash" hx-confirm="Stop using <?= e($cur['address']) ?>? Its mail will no longer become tickets."><?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="mailbox-form-archive-btn">Stop using this mailbox</button></form>
    <?php endif; ?>
</div>
