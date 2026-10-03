<?php /** The fields panel (queue holders who may work it). Data: t, facts, moves, assignees, types, categories, fieldDefs, tz, here */ $id = (int) $facts['ticket_id']; $post = static fn (string $f): string => 'method="post" action="/tickets/' . $f . '" hx-post="/tickets/' . $f . '" hx-target="#flash"'; $hidden = csrf_field() . '<input type="hidden" name="ticket" value="' . $id . '"><input type="hidden" name="return_to" value="' . e($here) . '">'; ?>
<div class="card mb-3" id="ticket-fields"><div class="card-header"><h5 class="card-title mb-0">Fields</h5></div><div class="card-body">
    <div class="fs-12 text-muted">Status</div>
    <div class="mb-3" id="ticket-fields-status"><span class="badge bg-<?= e($t['status_color']) ?> me-2"><?= e($t['status_label']) ?></span>
        <?php if ($facts['status_category'] === 'closed'): ?><span class="fs-12 text-muted" id="ticket-fields-closed"><?= e($facts['number']) ?> cannot move from Closed.</span>
        <?php else: ?>
        <div class="d-flex flex-wrap gap-1 mt-2" id="ticket-moves">
            <?php foreach ($moves as $m): if (!$m['may']) { continue; } $needs = $m['requires'] !== [] || in_array('pending_until', $m['may_carry'], true) || $m['category'] === 'on_hold'; ?>
                <?php if (!$needs): ?>
                    <form <?= $post('transition.php') ?> class="d-inline"><?= $hidden ?><input type="hidden" name="status" value="<?= e($m['key']) ?>"><button type="submit" class="btn btn-sm btn-touch btn-light" id="ticket-move-<?= e($m['key']) ?>-btn"><?= e($m['move_label']) ?></button></form>
                <?php else: ?>
                    <details class="d-inline-block" id="ticket-move-<?= e($m['key']) ?>"><summary class="btn btn-sm btn-touch btn-light" id="ticket-move-<?= e($m['key']) ?>-btn"><?= e($m['move_label']) ?>…</summary>
                        <form <?= $post('transition.php') ?> class="border rounded p-2 mt-1 bg-body" style="min-width: 16rem"><?= $hidden ?><input type="hidden" name="status" value="<?= e($m['key']) ?>">
                            <?php if (in_array('resolution_code', $m['requires'], true)): ?><label class="form-label fs-11 text-muted mb-0" for="ticket-move-<?= e($m['key']) ?>-code">Resolution</label><select name="resolution_code" id="ticket-move-<?= e($m['key']) ?>-code" class="form-select form-select-sm mb-1" required><?php foreach (RESOLUTION_CODES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
                            <?php if (in_array('hold_reason', $m['requires'], true) || $m['category'] === 'on_hold'): ?><label class="form-label fs-11 text-muted mb-0" for="ticket-move-<?= e($m['key']) ?>-reason">Reason</label><select name="hold_reason" id="ticket-move-<?= e($m['key']) ?>-reason" class="form-select form-select-sm mb-1" required><?php foreach (HOLD_REASONS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
                            <?php if (in_array('pending_until', $m['may_carry'], true)): ?><label class="form-label fs-11 text-muted mb-0" for="ticket-move-<?= e($m['key']) ?>-until">Until</label><input type="datetime-local" name="pending_until" id="ticket-move-<?= e($m['key']) ?>-until" class="form-control form-control-sm mb-1"><?php endif; ?>
                            <button type="submit" class="btn btn-primary btn-sm btn-touch w-100" id="ticket-move-<?= e($m['key']) ?>-save-btn"><?= e($m['move_label']) ?></button>
                        </form></details>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <form <?= $post('assign.php') ?> id="ticket-assign-form" class="mb-3"><?= $hidden ?>
        <label class="form-label fs-12 text-muted mb-0" for="ticket-assign-form-field-queue">Queue</label>
        <select name="queue" id="ticket-assign-form-field-queue" class="form-select btn-touch mb-1"><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= $q['queue_id'] === $facts['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
        <label class="form-label fs-12 text-muted mb-0" for="ticket-assign-form-field-assignee">Assignee</label>
        <div class="input-group"><select name="assignee" id="ticket-assign-form-field-assignee" class="form-select btn-touch"><option value="none" <?= $facts['assignee_member_id'] === null ? 'selected' : '' ?>>Nobody</option><option value="me">Me</option><?php foreach ($assignees as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= $facts['assignee_member_id'] === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?><?= $m['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select>
            <button type="submit" class="btn btn-light btn-touch" id="ticket-assign-form-save-btn"><i class="feather-check"></i></button></div>
    </form>
    <?php if ($t['assignee_kind'] === 'agent'): ?>
    <form <?= $post('dispatch.php') ?> class="mb-3"><?= $hidden ?><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="ticket-dispatch-btn"><i class="feather-cpu me-1"></i>Hand it to <?= e($t['assignee_name']) ?> again</button></form>
    <?php endif; ?>
    <form <?= $post('fields.php') ?> id="ticket-fields-form" class="mb-3"><?= $hidden ?>
        <div class="row g-2">
            <div class="col-6"><label class="form-label fs-12 text-muted mb-0" for="ticket-fields-form-field-priority">Priority</label><select name="priority" id="ticket-fields-form-field-priority" class="form-select btn-touch"><?php foreach (PRIORITY_COLORS as $k => $c): ?><option value="<?= e($k) ?>" <?= $facts['priority'] === $k ? 'selected' : '' ?>><?= e(ucfirst($k)) ?></option><?php endforeach; ?></select></div>
            <div class="col-6"><label class="form-label fs-12 text-muted mb-0" for="ticket-fields-form-field-type">Type</label><select name="type" id="ticket-fields-form-field-type" class="form-select btn-touch"><option value="">—</option><?php foreach ($types as $y): ?><option value="<?= (int) $y['type_id'] ?>" <?= $facts['type_id'] === (int) $y['type_id'] ? 'selected' : '' ?>><?= e($y['label']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label fs-12 text-muted mb-0" for="ticket-fields-form-field-category">Category</label><select name="category" id="ticket-fields-form-field-category" class="form-select btn-touch"><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>" <?= $facts['category_id'] === (int) $c['category_id'] ? 'selected' : '' ?>><?= e(str_repeat('· ', (int) $c['depth']) . $c['name']) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><label class="form-label fs-12 text-muted mb-0" for="ticket-fields-form-field-due">Due</label><input type="datetime-local" name="due_at" id="ticket-fields-form-field-due" class="form-control btn-touch" value="<?= $facts['due_at'] ? e(format_ts($facts['due_at'], $tz, 'Y-m-d\TH:i')) : '' ?>"></div>
            <?php foreach ($fieldDefs as $f): ?><div class="col-12"><?= view('fields/partials/field-input.php', ['f' => $f, 'value' => $facts['custom_values'][$f['key']] ?? null, 'prefix' => 'custom', 'idp' => 'ticket-custom']) ?></div><?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-light btn-touch w-100 mt-2" id="ticket-fields-form-save-btn">Save fields</button>
    </form>
    <div class="fs-12 text-muted">Tags</div>
    <div class="d-flex flex-wrap gap-1 mb-1" id="ticket-tags">
        <?php foreach ($t['tags'] as $g): ?><form <?= $post('untag.php') ?> class="d-inline"><?= $hidden ?><input type="hidden" name="tag" value="<?= e($g) ?>"><button type="submit" class="badge bg-soft-secondary text-secondary border-0" id="ticket-tag-<?= e($g) ?>-remove-btn"><?= e($g) ?> <i class="feather-x"></i></button></form><?php endforeach; ?>
    </div>
    <form <?= $post('tags.php') ?> class="input-group mb-3" id="ticket-tags-form"><?= $hidden ?><input type="text" name="tags" id="ticket-tags-form-field-tags" class="form-control btn-touch" placeholder="add tags, comma-separated" maxlength="200"><button type="submit" class="btn btn-light btn-touch" id="ticket-tags-form-add-btn"><i class="feather-plus"></i></button></form>
    <div class="fs-12 text-muted">SLA</div>
    <?= view('tickets/partials/sla-strip.php', ['t' => $t, 'tz' => $tz]) ?>
    <div class="fs-12 text-muted mt-3">Time logged: <span id="ticket-time-total"><?= (int) $t['time_total'] ?> min</span></div>
    <?php foreach ($t['time'] as $e): ?><div class="d-flex justify-content-between fs-12 py-1 border-bottom" id="time-entry-<?= (int) $e['entry_id'] ?>"><span><?= e($e['display_name']) ?> · <?= (int) $e['minutes'] ?> min<?= $e['billable'] ? ' · billable' : '' ?> · <?= e(format_date($e['worked_on'])) ?></span>
        <?php if ($e['member_id'] === (int) current_member_id() || has_right('tickets.manage')): ?><form method="post" action="/tickets/time-delete.php" hx-post="/tickets/time-delete.php" hx-target="#flash" hx-confirm="Remove this time entry?"><?= csrf_field() ?><input type="hidden" name="entry" value="<?= (int) $e['entry_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-link btn-sm p-0 text-danger" id="time-entry-<?= (int) $e['entry_id'] ?>-delete-btn" aria-label="Remove"><i class="feather-x"></i></button></form><?php endif; ?></div><?php endforeach; ?>
    <form <?= $post('time.php') ?> class="row g-1 mt-1 mb-3" id="ticket-time-form"><?= $hidden ?>
        <div class="col-4"><input type="number" name="minutes" id="ticket-time-form-field-minutes" class="form-control form-control-sm" min="1" max="1440" placeholder="min" required aria-label="Minutes"></div>
        <div class="col-5"><input type="text" name="note" id="ticket-time-form-field-note" class="form-control form-control-sm" placeholder="what" maxlength="120" aria-label="Note"></div>
        <div class="col-3"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="ticket-time-form-log-btn">Log</button></div>
        <div class="col-12"><label class="fs-11 text-muted"><input type="checkbox" name="billable" value="yes" class="form-check-input"> billable</label></div>
    </form>
    <div class="fs-12 text-muted">Secure link</div>
    <div class="fs-12 mb-1" id="ticket-secure-link"><?= $t['secure_link']['live'] ? 'Live' . ($t['secure_link']['expires_at'] ? ', expires ' . e(format_ts($t['secure_link']['expires_at'], $tz, 'M j, Y')) : '') . ($t['secure_link']['last_used_at'] ? ', last used ' . e(format_ts($t['secure_link']['last_used_at'], $tz, 'M j, g:i A')) : ', never used') : 'None' ?><?= $t['secure_link']['rotated'] > 0 ? ' · rotated ' . (int) $t['secure_link']['rotated'] . '×' : '' ?></div>
    <form <?= $post('secure-link.php') ?> hx-confirm="Rotate the secure link? The old one stops working; the requester is sent the new one."><?= $hidden ?><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="ticket-secure-link-rotate-btn"><i class="feather-refresh-cw me-1"></i>Rotate</button></form>
</div></div>
