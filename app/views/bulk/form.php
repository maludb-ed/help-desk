<?php /** Change several tickets at once (screen `bulk-update`). Data: targets, statuses, assignees, queues, tz, here */ $n = count($targets); $ids = array_map(static fn (array $t): int => $t['ticket_id'], $targets); ?>
<?= view('shared/header.php', ['id' => 'bulk-update', 'title' => 'Change ' . $n . ' ticket' . ($n === 1 ? '' : 's'), 'crumbs' => [['Home', '/'], ['Inbox', '/inbox'], ['Change selected', null]], 'back' => back_link() ?? ['/inbox', 'Inbox']]) ?>
<div class="main-content" id="bulk-update-content">
    <?php if ($targets === []): ?><div class="card"><div class="card-body text-muted" id="bulk-empty">Nothing selected — tick tickets in the inbox and choose Change selected.</div></div>
    <?php else: ?>
    <div class="card mb-3" id="bulk-targets"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>#</th><th>Subject</th><th>Status</th><th>Assignee</th></tr></thead><tbody>
        <?php foreach ($targets as $t): ?><tr id="bulk-target-<?= (int) $t['ticket_id'] ?>"><td class="text-nowrap"><?= hx_link(with_back('/tickets/' . (int) $t['ticket_id'], $here), e($t['number']), 'fw-semibold') ?></td><td><?= e($t['subject']) ?></td><td><span class="badge bg-<?= e($t['status_color']) ?>"><?= e($t['status_label']) ?></span></td><td><?= e($t['assignee_name'] ?? '—') ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
    <form method="post" action="/bulk/update.php" hx-post="/bulk/update.php" hx-target="#page-content" hx-swap="innerHTML" id="bulk-form">
        <?= csrf_field() ?><?php foreach ($ids as $id): ?><input type="hidden" name="tickets[]" value="<?= $id ?>"><?php endforeach; ?><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="card mb-3"><div class="card-body">
            <div class="row g-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-status">Status</label><select name="status" id="bulk-form-field-status" class="form-select btn-touch"><option value="">Keep</option><?php foreach ($statuses as $s): ?><option value="<?= e($s['key']) ?>"><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-code">Resolution code (when solving or closing)</label><select name="resolution_code" id="bulk-form-field-code" class="form-select btn-touch"><option value="">—</option><?php foreach (RESOLUTION_CODES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-hold">Hold reason (when putting on hold)</label><select name="hold_reason" id="bulk-form-field-hold" class="form-select btn-touch"><option value="">—</option><?php foreach (HOLD_REASONS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-until">Wake up (when pending)</label><input type="datetime-local" name="pending_until" id="bulk-form-field-until" class="form-control btn-touch"></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-assignee">Assignee</label><select name="assignee" id="bulk-form-field-assignee" class="form-select btn-touch"><option value="keep">Keep</option><option value="none">Nobody</option><option value="me">Me</option><?php foreach ($assignees as $m): ?><option value="<?= (int) $m['member_id'] ?>"><?= e($m['display_name']) ?><?= $m['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-queue">Queue</label><select name="queue" id="bulk-form-field-queue" class="form-select btn-touch"><option value="">Keep</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>"><?= e($q['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-priority">Priority</label><select name="priority" id="bulk-form-field-priority" class="form-select btn-touch"><option value="">Keep</option><?php foreach (VIEW_PRIORITIES as $p): ?><option value="<?= e($p) ?>"><?= e(ucfirst($p)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-add-tags">Add tags</label><input type="text" name="add_tags" id="bulk-form-field-add-tags" class="form-control btn-touch" maxlength="200" placeholder="vpn, outage"></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bulk-form-field-remove-tags">Remove tags</label><input type="text" name="remove_tags" id="bulk-form-field-remove-tags" class="form-control btn-touch" maxlength="200"></div>
                <div class="col-12"><label class="form-label fs-12 text-muted" for="bulk-form-field-note">A note added to each</label><textarea name="note" id="bulk-form-field-note" class="form-control" rows="2" maxlength="5000"></textarea></div>
            </div>
            <input type="hidden" name="confirmed" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mt-3 btn-touch" for="bulk-form-field-confirmed"><input type="checkbox" class="form-check-input mt-0" name="confirmed" value="yes" id="bulk-form-field-confirmed">I mean these <?= $n ?> ticket<?= $n === 1 ? '' : 's' ?></label>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="bulk-form-apply-btn">Apply to <?= $n ?> ticket<?= $n === 1 ? '' : 's' ?></button>
        <?= hx_link('/inbox', 'Cancel', 'btn btn-light btn-touch w-100', 'id="bulk-form-cancel-link"') ?>
    </form>
    <?php endif; ?>
</div>
