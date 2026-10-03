<?php /** Add or change a queue (screens `queue-add`, `queue-edit`). Data: cur, departments, members, schedules, policies, here */
$id = $cur['queue_id'] ?? null; $screen = $cur === null ? 'queue-add' : 'queue-edit'; $isGeneral = ($cur['key'] ?? '') === 'general';
$back = $cur === null ? ['/settings/queues/', 'Queues'] : ['/settings/queues/' . (int) $id, 'the queue']; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a queue' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Queues', '/settings/queues/'], [$cur === null ? 'Add' : $cur['name'], $cur === null ? null : '/settings/queues/' . (int) $id], $cur === null ? ['', null] : ['Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/queues/save.php" hx-post="/settings/queues/save.php" hx-target="#flash" id="queue-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="queue" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The queue</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="queue-form-field-name">Name</label>
            <input type="text" name="name" id="queue-form-field-name" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($cur['name'] ?? '') ?>" placeholder="IT help">
            <label class="form-label fs-12 text-muted" for="queue-form-field-key">Key<?= $cur !== null ? ' (fixed)' : ' (left blank: made from the name)' ?></label>
            <input type="text" name="key" id="queue-form-field-key" class="form-control btn-touch mb-3" maxlength="40" pattern="[a-z][a-z0-9_]*" value="<?= e($cur['key'] ?? '') ?>" <?= $cur !== null ? 'readonly' : '' ?> placeholder="it_help">
            <label class="form-label fs-12 text-muted" for="queue-form-field-description">Description</label>
            <textarea name="description" id="queue-form-field-description" class="form-control mb-3" rows="2" maxlength="500"><?= e($cur['description'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted" for="queue-form-field-sort">Order in the list</label>
            <input type="number" name="sort_order" id="queue-form-field-sort" class="form-control btn-touch" min="0" max="1000" value="<?= (int) ($cur['sort_order'] ?? 100) ?>">
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Who works it</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="queue-form-field-department">Department</label>
            <select name="department" id="queue-form-field-department" class="form-select btn-touch mb-2">
                <option value="">None</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($cur['department_id'] ?? null) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
            </select>
            <input type="hidden" name="include_department" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="queue-form-field-include"><input type="checkbox" class="form-check-input mt-0" name="include_department" value="yes" id="queue-form-field-include" <?= ($cur['include_department'] ?? true) ? 'checked' : '' ?>>The department's members are in the queue</label>
            <div class="fs-12 text-muted mb-3">With a department linked, its live members who hold a Help Desk role work the queue without being named. Leads are always named.</div>
            <label class="form-label fs-12 text-muted">Assignment</label>
            <?php foreach (ASSIGNMENT_METHODS as $k => $label): ?>
                <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="queue-form-field-method-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="assignment_method" value="<?= e($k) ?>" id="queue-form-field-method-<?= e($k) ?>" <?= ($cur['assignment_method'] ?? 'manual') === $k ? 'checked' : '' ?>><?= e($label) ?></label>
            <?php endforeach; ?>
            <label class="form-label fs-12 text-muted mt-2" for="queue-form-field-assignee">Default assignee (a person or an agent; required for "Default assignee")</label>
            <select name="default_assignee" id="queue-form-field-assignee" class="form-select btn-touch">
                <option value="">Nobody</option>
                <?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= ($cur['default_assignee_member_id'] ?? null) === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?> (<?= $m['member_kind'] === 'agent' ? 'agent' : 'person' ?>)</option><?php endforeach; ?>
            </select>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Service and mail</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="queue-form-field-schedule">Business hours</label>
            <select name="schedule" id="queue-form-field-schedule" class="form-select btn-touch mb-3">
                <option value="">Calendar hours</option>
                <?php foreach ($schedules as $s): ?><option value="<?= (int) $s['schedule_id'] ?>" <?= ($cur['business_hours_id'] ?? null) === (int) $s['schedule_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="queue-form-field-policy">SLA policy</label>
            <select name="policy" id="queue-form-field-policy" class="form-select btn-touch mb-3">
                <option value="">Whichever matches</option>
                <?php foreach ($policies as $p): ?><option value="<?= (int) $p['policy_id'] ?>" <?= ($cur['sla_policy_id'] ?? null) === (int) $p['policy_id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="queue-form-field-email">Email address (its mailbox comes with slice 7)</label>
            <input type="email" name="email_address" id="queue-form-field-email" class="form-control btn-touch" maxlength="200" value="<?= e($cur['email_address'] ?? '') ?>" placeholder="it-help@example.com">
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="queue-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="queue-form-cancel-link"') ?>
    </form>
</div>
