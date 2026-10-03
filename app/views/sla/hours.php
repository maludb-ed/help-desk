<?php /** A schedule's weekly hours and holidays (screen `business-hours-edit`). Data: cur (or null = new), here, preview */ $id = $cur['schedule_id'] ?? null; ?>
<?= view('shared/header.php', ['id' => 'business-hours-edit', 'title' => $cur === null ? 'New schedule' : $cur['name'] . ' — hours', 'crumbs' => [['Home', '/'], ['SLA and hours', '/settings/sla/'], [$cur === null ? 'New schedule' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/sla/', 'SLA and hours']]) ?>
<div class="main-content" id="business-hours-edit-content">
    <form method="post" action="/settings/sla/hours.php" hx-post="/settings/sla/hours.php" hx-target="#flash" id="hours-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="schedule" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The schedule</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="hours-form-field-name">Name</label>
            <input type="text" name="name" id="hours-form-field-name" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($cur['name'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="hours-form-field-timezone">Time zone</label>
            <select name="timezone" id="hours-form-field-timezone" class="form-select btn-touch mb-3"><?php foreach (DateTimeZone::listIdentifiers() as $z): ?><option value="<?= e($z) ?>" <?= ($cur['timezone'] ?? 'UTC') === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select>
            <input type="hidden" name="is_default" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="hours-form-field-default"><input type="checkbox" class="form-check-input mt-0" name="is_default" value="yes" id="hours-form-field-default" <?= ($cur['is_default'] ?? false) ? 'checked' : '' ?>>The default schedule</label>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Weekly hours (blank = closed)</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="hours-form-grid">
            <thead class="thead-light"><tr><th>Day</th><th>Opens</th><th>Closes</th><th>Opens</th><th>Closes</th></tr></thead><tbody>
            <?php for ($d = 1; $d <= 7; $d++): $w = $d % 7; $ivs = $cur['intervals'][$w] ?? []; ?>
                <tr id="hours-form-row-<?= $w ?>"><th class="fw-semibold"><?= WEEKDAYS[$w] ?></th>
                <?php for ($i = 0; $i < 2; $i++): ?>
                    <td><input type="time" name="intervals[<?= $w ?>][<?= $i ?>][opens]" id="hours-<?= $w ?>-<?= $i ?>-opens" class="form-control form-control-sm" value="<?= e($ivs[$i]['opens'] ?? '') ?>"></td>
                    <td><input type="time" name="intervals[<?= $w ?>][<?= $i ?>][closes]" id="hours-<?= $w ?>-<?= $i ?>-closes" class="form-control form-control-sm" value="<?= e($ivs[$i]['closes'] ?? '') ?>"></td>
                <?php endfor; ?></tr>
            <?php endfor; ?>
            </tbody></table></div></div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="hours-form-save-btn">Save hours</button>
        <?= hx_link('/settings/sla/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="hours-form-cancel-link"') ?>
    </form>
    <?php if ($id !== null): ?>
    <div class="card mb-3" id="hours-holidays"><div class="card-header"><h5 class="card-title mb-0">Holidays</h5></div>
        <div class="list-group list-group-flush" id="hours-holiday-list">
            <?php if ($cur['holidays'] === []): ?><div class="list-group-item text-muted" id="hours-holidays-empty">None yet.</div><?php endif; ?>
            <?php foreach ($cur['holidays'] as $h): ?>
                <div class="list-group-item d-flex align-items-center gap-2 py-2" id="hours-holiday-<?= e($h['holiday']) ?>">
                    <div class="min-w-0 flex-grow-1"><?= e(format_date($h['holiday'])) ?><?= $h['name'] ? ' <span class="text-muted">· ' . e($h['name']) . '</span>' : '' ?></div>
                    <form method="post" action="/settings/sla/holiday-remove.php" hx-post="/settings/sla/holiday-remove.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="schedule" value="<?= (int) $id ?>"><input type="hidden" name="holiday" value="<?= e($h['holiday']) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <button type="submit" class="btn btn-light btn-sm btn-touch" id="hours-holiday-<?= e($h['holiday']) ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card-body border-top">
            <form method="post" action="/settings/sla/holiday.php" hx-post="/settings/sla/holiday.php" hx-target="#flash" class="row g-2 align-items-end" id="holiday-form">
                <?= csrf_field() ?><input type="hidden" name="schedule" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <div class="col-5"><label class="form-label fs-12 text-muted" for="holiday-form-field-date">Date</label><input type="date" name="holiday" id="holiday-form-field-date" class="form-control btn-touch" required></div>
                <div class="col-5"><label class="form-label fs-12 text-muted" for="holiday-form-field-name">Name</label><input type="text" name="name" id="holiday-form-field-name" class="form-control btn-touch" maxlength="60" placeholder="New Year"></div>
                <div class="col-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="holiday-form-save-btn" aria-label="Add"><i class="feather-plus"></i></button></div>
            </form>
        </div>
    </div>
    <div class="card" id="hours-preview-card"><div class="card-header"><h5 class="card-title mb-0">Preview</h5></div><div class="card-body">
        <form method="get" action="/settings/sla/hours" hx-get="/settings/sla/hours" hx-target="#hours-preview" hx-swap="innerHTML" hx-trigger="change, keyup changed delay:400ms" class="row g-2 align-items-end" id="hours-preview-form">
            <input type="hidden" name="preview" value="1"><input type="hidden" name="schedule" value="<?= (int) $id ?>">
            <div class="col-7"><label class="form-label fs-12 text-muted" for="hours-preview-field-from">From (<?= e($cur['timezone']) ?>)</label><input type="datetime-local" name="from" id="hours-preview-field-from" class="form-control btn-touch" value="<?= e($preview['from'] ?? '') ?>"></div>
            <div class="col-5"><label class="form-label fs-12 text-muted" for="hours-preview-field-minutes">Minutes</label><input type="number" name="minutes" id="hours-preview-field-minutes" class="form-control btn-touch" min="1" max="525600" value="<?= (int) ($preview['minutes'] ?? 60) ?>"></div>
        </form>
        <div class="fs-12 mt-2 fw-semibold" id="hours-preview"><?= e($preview['sentence'] ?? 'Pick a moment and a number of minutes.') ?></div>
    </div></div>
    <?php endif; ?>
</div>
