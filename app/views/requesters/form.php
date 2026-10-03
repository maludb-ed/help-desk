<?php /** Add or change a requester (screens `requester-add`, `requester-edit`). Data: cur, organizations, organization (preselect), here. A member's name and email are read-only. */
$id = $cur['requester_id'] ?? null; $screen = $cur === null ? 'requester-add' : 'requester-edit'; $managed = $cur !== null && $cur['kind'] !== 'external';
$back = $cur === null ? ['/requesters/', 'Requesters'] : ['/requesters/' . (int) $id, 'the requester']; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a requester' : 'Change ' . $cur['display_name'], 'crumbs' => [['Home', '/'], ['Requesters', '/requesters/'], [$cur === null ? 'Add' : $cur['display_name'], $cur === null ? null : '/requesters/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/requesters/save.php" hx-post="/requesters/save.php" hx-target="#flash" id="requester-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="requester" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The person</h5></div><div class="card-body">
            <?php if ($managed): ?>
                <div class="fs-12 text-muted mb-3" id="requester-form-managed">Managed by the OS — the name and address are the directory's.</div>
                <div class="mb-3"><div class="fs-12 text-muted">Name</div><div class="fw-semibold" id="requester-form-name-ro"><?= e($cur['display_name']) ?></div></div>
                <div class="mb-3"><div class="fs-12 text-muted">Email</div><div id="requester-form-email-ro"><?= e($cur['email'] ?? '') ?></div></div>
            <?php else: ?>
                <label class="form-label fs-12 text-muted" for="requester-form-field-name">Name</label>
                <input type="text" name="display_name" id="requester-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['display_name'] ?? '') ?>">
                <?php if ($cur === null): ?>
                    <label class="form-label fs-12 text-muted" for="requester-form-field-email">Email</label>
                    <input type="email" name="email" id="requester-form-field-email" class="form-control btn-touch mb-3" maxlength="200" required placeholder="jo@customer.com">
                <?php endif; ?>
                <label class="form-label fs-12 text-muted" for="requester-form-field-phone">Phone</label>
                <input type="text" name="phone" id="requester-form-field-phone" class="form-control btn-touch mb-3" maxlength="40" value="<?= e($cur['phone'] ?? '') ?>">
            <?php endif; ?>
            <label class="form-label fs-12 text-muted" for="requester-form-field-organization">Organization</label>
            <select name="organization" id="requester-form-field-organization" class="form-select btn-touch mb-3">
                <option value="">None</option>
                <?php foreach ($organizations as $o): ?><option value="<?= (int) $o['organization_id'] ?>" <?= ($cur['organization_id'] ?? $organization) === (int) $o['organization_id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach; ?>
            </select>
            <?php if (!$managed): ?>
                <div class="row g-2 mb-3">
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="requester-form-field-locale">Locale</label><input type="text" name="locale" id="requester-form-field-locale" class="form-control btn-touch" maxlength="10" value="<?= e($cur['locale'] ?? '') ?>" placeholder="en"></div>
                    <div class="col-6"><label class="form-label fs-12 text-muted" for="requester-form-field-timezone">Time zone</label><input type="text" name="timezone" id="requester-form-field-timezone" class="form-control btn-touch" maxlength="64" value="<?= e($cur['timezone'] ?? '') ?>" placeholder="America/New_York" list="requester-form-zones"></div>
                    <datalist id="requester-form-zones"><?php foreach (DateTimeZone::listIdentifiers() as $z): ?><option value="<?= e($z) ?>"><?php endforeach; ?></datalist>
                </div>
            <?php endif; ?>
            <label class="form-label fs-12 text-muted" for="requester-form-field-notes">Notes (support's; never shown to them)</label>
            <textarea name="notes" id="requester-form-field-notes" class="form-control" rows="3" maxlength="4000"><?= e($cur['notes'] ?? '') ?></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="requester-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="requester-form-cancel-link"') ?>
    </form>
</div>
