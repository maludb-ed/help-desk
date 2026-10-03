<?php /** My settings (screen `settings`). Data: tab, prefs, refusal, osChannels, notice, presence (support staff), may (settings) */ $presence = $presence ?? null; $may = $may ?? ['settings' => false]; ?>
<?= view('shared/header.php', ['id' => 'settings', 'title' => 'My settings', 'crumbs' => [['Home', '/'], ['My settings', null]]]) ?>
<div class="main-content" id="settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/settings/?tab=notify', 'How I am told', 'btn btn-touch ' . ($tab === 'notify' ? 'btn-primary' : 'btn-light'), 'id="settings-tab-notify"') ?>
        <?= hx_link('/settings/tokens/', 'Tokens', 'btn btn-touch btn-light', 'id="settings-tab-tokens"') ?>
        <?php if ($may['settings']): ?><?= hx_link('/settings/general', 'Help Desk settings', 'btn btn-touch btn-light', 'id="settings-tab-general"') ?><?php endif; ?>
    </div>
    <?php if ($presence !== null): ?>
    <form method="post" action="/settings/queues/presence.php" hx-post="/settings/queues/presence.php" hx-target="#flash" id="presence-form" class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">My availability</h5></div><div class="card-body">
        <?= csrf_field() ?><input type="hidden" name="return_to" value="/settings/?tab=notify">
        <div class="row g-2 align-items-end">
            <div class="col-6"><label class="form-label fs-12 text-muted" for="presence-form-field-availability">I am</label><select name="availability" id="presence-form-field-availability" class="form-select btn-touch"><?php foreach (PRESENCE_STATES as $k => $l): ?><option value="<?= e($k) ?>" <?= $presence['availability'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label fs-12 text-muted" for="presence-form-field-capacity">Open tickets at most (blank = no limit)</label><input type="number" name="capacity" id="presence-form-field-capacity" class="form-control btn-touch" min="0" max="500" value="<?= $presence['capacity'] === null ? '' : (int) $presence['capacity'] ?>"></div>
        </div>
        <div class="fs-12 text-muted mt-2">Least-loaded assignment skips you when you are away or at capacity.</div>
        <button type="submit" class="btn btn-light btn-touch w-100 mt-2" id="presence-form-save-btn">Save availability</button>
    </div></form>
    <?php endif; ?>
    <?= view('settings/partials/notify.php', ['prefs' => $prefs, 'refusal' => $refusal, 'osChannels' => $osChannels, 'recent' => $recent ?? []]) ?>
</div>
