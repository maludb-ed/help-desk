<?php /** Add or change a policy (screens `sla-policy-add`, `sla-policy-edit`). Data: cur, organizations, queues, types, schedules, here */
$id = $cur['policy_id'] ?? null; $screen = $cur === null ? 'sla-policy-add' : 'sla-policy-edit'; $c = $cur['conditions'] ?? [];
$sel = static fn (string $key, int $v): string => in_array($v, array_map('intval', (array) ($c[$key] ?? [])), true) ? 'selected' : ''; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a policy' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['SLA and hours', '/settings/sla/'], [$cur === null ? 'Add' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/sla/', 'SLA and hours']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/sla/save.php" hx-post="/settings/sla/save.php" hx-target="#flash" id="policy-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="policy" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The policy</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="policy-form-field-name">Name</label>
            <input type="text" name="name" id="policy-form-field-name" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="policy-form-field-description">Description</label>
            <textarea name="description" id="policy-form-field-description" class="form-control mb-3" rows="2" maxlength="500"><?= e($cur['description'] ?? '') ?></textarea>
            <div class="row g-2">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="policy-form-field-sort">Order (lower first)</label><input type="number" name="sort_order" id="policy-form-field-sort" class="form-control btn-touch" min="0" max="10000" value="<?= (int) ($cur['sort_order'] ?? 100) ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="policy-form-field-warning">Warn at %</label><input type="number" name="warning_percent" id="policy-form-field-warning" class="form-control btn-touch" min="1" max="99" value="<?= (int) ($cur['warning_percent'] ?? 80) ?>"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="policy-form-field-schedule">Hours</label><select name="schedule" id="policy-form-field-schedule" class="form-select btn-touch"><option value="">Calendar</option><?php foreach ($schedules as $s): ?><option value="<?= (int) $s['schedule_id'] ?>" <?= ($cur['business_hours_id'] ?? null) === (int) $s['schedule_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            </div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Matches (empty = any)</h5></div><div class="card-body">
            <input type="hidden" name="organizations[]" value=""><input type="hidden" name="queues[]" value=""><input type="hidden" name="types[]" value=""><input type="hidden" name="priorities[]" value="">
            <label class="form-label fs-12 text-muted" for="policy-form-field-organizations">Organizations</label>
            <select name="organizations[]" id="policy-form-field-organizations" class="form-select mb-3" multiple size="4"><?php foreach ($organizations as $o): ?><option value="<?= (int) $o['organization_id'] ?>" <?= $sel('organization_ids', (int) $o['organization_id']) ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="policy-form-field-queues">Queues</label>
            <select name="queues[]" id="policy-form-field-queues" class="form-select mb-3" multiple size="4"><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= $sel('queue_ids', (int) $q['queue_id']) ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="policy-form-field-types">Types</label>
            <select name="types[]" id="policy-form-field-types" class="form-select mb-3" multiple size="4"><?php foreach ($types as $t): ?><option value="<?= (int) $t['type_id'] ?>" <?= $sel('type_ids', (int) $t['type_id']) ?>><?= e($t['label']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted">Priorities</label>
            <div class="d-flex flex-wrap gap-2 mb-3" id="policy-form-field-priorities">
                <?php foreach (PRIORITIES as $k => $label): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="policy-form-field-priority-<?= e($k) ?>"><input type="checkbox" class="form-check-input mt-0" name="priorities[]" value="<?= e($k) ?>" id="policy-form-field-priority-<?= e($k) ?>" <?= in_array($k, (array) ($c['priorities'] ?? []), true) ? 'checked' : '' ?>><?= e($label) ?></label><?php endforeach; ?>
            </div>
            <label class="form-label fs-12 text-muted" for="policy-form-field-tags">Tags (comma-separated; a ticket carrying any)</label>
            <input type="text" name="tags" id="policy-form-field-tags" class="form-control btn-touch" maxlength="200" value="<?= e(implode(', ', (array) ($c['tags'] ?? []))) ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Targets, in minutes (blank = no target)</h5></div><div class="card-body">
            <?= view('sla/partials/targets-grid.php', ['targets' => $cur['targets'] ?? [], 'editable' => true, 'prefix' => 'targets']) ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="policy-form-save-btn">Save</button>
        <?= hx_link('/settings/sla/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="policy-form-cancel-link"') ?>
    </form>
</div>
