<?php /** Add or change an organization (screens `organization-add`, `organization-edit`). Data: cur, policies, here */
$id = $cur['organization_id'] ?? null; $screen = $cur === null ? 'organization-add' : 'organization-edit';
$back = $cur === null ? ['/organizations/', 'Organizations'] : ['/organizations/' . (int) $id, 'the organization']; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a customer company' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Organizations', '/organizations/'], [$cur === null ? 'Add' : $cur['name'], $cur === null ? null : '/organizations/' . (int) $id]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/organizations/save.php" hx-post="/organizations/save.php" hx-target="#flash" id="organization-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="organization" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The company</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="organization-form-field-name">Name</label>
            <input type="text" name="name" id="organization-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="organization-form-field-tier">Support tier (your own words)</label>
            <input type="text" name="support_tier" id="organization-form-field-tier" class="form-control btn-touch mb-3" maxlength="40" value="<?= e($cur['support_tier'] ?? '') ?>" placeholder="Gold">
            <label class="form-label fs-12 text-muted" for="organization-form-field-domains">Email domains (one per line; each claims the people who write from it)</label>
            <textarea name="domains" id="organization-form-field-domains" class="form-control mb-3" rows="3" placeholder="example.com"><?= e(implode("\n", $cur['domains'] ?? [])) ?></textarea>
            <label class="form-label fs-12 text-muted" for="organization-form-field-tags">Tags (comma-separated)</label>
            <input type="text" name="tags" id="organization-form-field-tags" class="form-control btn-touch" maxlength="200" value="<?= e(implode(', ', $cur['tags'] ?? [])) ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Service</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="organization-form-field-policy">SLA policy (an override)</label>
            <select name="policy" id="organization-form-field-policy" class="form-select btn-touch mb-3">
                <option value="">Whichever matches</option>
                <?php foreach ($policies as $p): ?><option value="<?= (int) $p['policy_id'] ?>" <?= ($cur['sla_policy_id'] ?? null) === (int) $p['policy_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="organization-form-field-see">Members see each other's tickets</label>
            <select name="members_see_each_other" id="organization-form-field-see" class="form-select btn-touch mb-3">
                <option value="" <?= ($cur['members_see_each_other'] ?? null) === null ? 'selected' : '' ?>>The business default</option>
                <option value="yes" <?= ($cur['members_see_each_other'] ?? null) === true ? 'selected' : '' ?>>Yes</option>
                <option value="no" <?= ($cur['members_see_each_other'] ?? null) === false ? 'selected' : '' ?>>No</option>
            </select>
            <label class="form-label fs-12 text-muted" for="organization-form-field-notes">Notes (support's; never shown to the customer)</label>
            <textarea name="notes" id="organization-form-field-notes" class="form-control" rows="3" maxlength="4000"><?= e($cur['notes'] ?? '') ?></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="organization-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="organization-form-cancel-link"') ?>
    </form>
</div>
