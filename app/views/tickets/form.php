<?php /** File or change a ticket (screens `ticket-add`, `ticket-edit`). Data: cur (facts or null), queues, categories, types, fieldDefs, members, onBehalfOf, forCustomer, canOnBehalf, here, prefill */
$id = $cur['ticket_id'] ?? null; $screen = $cur === null ? 'ticket-add' : 'ticket-edit'; $cv = $cur['custom_values'] ?? []; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New request' : 'Change ' . $cur['number'], 'crumbs' => [['Home', '/'], $cur === null ? ['New request', null] : [$cur['number'], '/tickets/' . (int) $id]], 'back' => back_link() ?? ($cur === null ? ['/', 'Home'] : ['/tickets/' . (int) $id, 'the ticket'])]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/tickets/save.php" hx-post="/tickets/save.php" hx-target="#flash" id="ticket-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="ticket" value="<?= (int) $id ?>"><?php endif; ?>
        <?php if ($cur === null): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Who is it for</h5></div><div class="card-body">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="ticket-form-for-me"><input type="radio" class="form-check-input mt-0" name="for" value="me" id="ticket-form-for-me" <?= !$onBehalfOf && !$forCustomer ? 'checked' : '' ?>>Me</label>
            <?php if ($canOnBehalf): ?>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="ticket-form-for-member"><input type="radio" class="form-check-input mt-0" name="for" value="member" id="ticket-form-for-member" <?= $onBehalfOf ? 'checked' : '' ?>>Someone else here</label>
            <select name="on_behalf_of" id="ticket-form-field-on-behalf-of" class="form-select btn-touch mb-2"><option value="">Choose a person…</option><?php foreach ($members as $m): if ($m['member_kind'] !== 'human') { continue; } ?><option value="<?= (int) $m['member_id'] ?>" <?= $onBehalfOf === (int) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?></option><?php endforeach; ?></select>
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-2 btn-touch" for="ticket-form-for-customer"><input type="radio" class="form-check-input mt-0" name="for" value="customer" id="ticket-form-for-customer" <?= $forCustomer ? 'checked' : '' ?>>A customer or outside person</label>
            <div class="row g-2"><div class="col-6"><input type="text" name="requester_name" id="ticket-form-field-requester-name" class="form-control btn-touch" placeholder="Their name" maxlength="120"></div><div class="col-6"><input type="email" name="requester_email" id="ticket-form-field-requester-email" class="form-control btn-touch" placeholder="their@email" maxlength="200"></div></div>
            <?php endif; ?>
        </div></div>
        <?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The request</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="ticket-form-field-subject">Subject</label>
            <input type="text" name="subject" id="ticket-form-field-subject" class="form-control btn-touch mb-3" maxlength="300" required value="<?= e($cur['subject'] ?? ($prefill['subject'] ?? '')) ?>">
            <?php if ($cur === null): ?>
            <label class="form-label fs-12 text-muted" for="ticket-form-field-body">What is going on</label>
            <textarea name="body" id="ticket-form-field-body" class="form-control mb-2" rows="5" required maxlength="20000"><?= e($prefill['body'] ?? '') ?></textarea>
            <?php if ($canOnBehalf): ?><input type="hidden" name="as_note" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="ticket-form-field-as-note"><input type="checkbox" class="form-check-input mt-0" name="as_note" value="yes" id="ticket-form-field-as-note">File the text as an internal note (not shown to the requester)</label><?php endif; ?>
            <?php endif; ?>
            <label class="form-label fs-12 text-muted" for="ticket-form-field-category">Category</label>
            <select name="category" id="ticket-form-field-category" class="form-select btn-touch mb-3"><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>" <?= ($cur['category_id'] ?? $prefill['category'] ?? null) === (int) $c['category_id'] ? 'selected' : '' ?>><?= e(str_repeat('· ', (int) $c['depth']) . $c['name']) ?></option><?php endforeach; ?></select>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="ticket-form-field-priority">Priority</label><select name="priority" id="ticket-form-field-priority" class="form-select btn-touch"><?php foreach (PRIORITY_COLORS as $k => $c): ?><option value="<?= e($k) ?>" <?= ($cur['priority'] ?? 'normal') === $k ? 'selected' : '' ?>><?= e(ucfirst($k)) ?></option><?php endforeach; ?></select></div>
                <?php if ($canOnBehalf || $cur !== null): ?><div class="col-6"><label class="form-label fs-12 text-muted" for="ticket-form-field-type">Type</label><select name="type" id="ticket-form-field-type" class="form-select btn-touch" hx-get="/tickets/fields.php<?= $id !== null ? '?ticket=' . (int) $id : '' ?>" hx-trigger="change" hx-target="#custom-fields" hx-swap="outerHTML"><option value="">—</option><?php foreach ($types as $y): ?><option value="<?= (int) $y['type_id'] ?>" <?= ($cur['type_id'] ?? null) === (int) $y['type_id'] ? 'selected' : '' ?>><?= e($y['label']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
            </div>
            <?php if ($canOnBehalf && $cur === null): ?>
            <label class="form-label fs-12 text-muted" for="ticket-form-field-queue">Queue (empty: routed by the category, then the default)</label>
            <select name="queue" id="ticket-form-field-queue" class="form-select btn-touch mb-3"><option value="">Route it</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= ($prefill['queue'] ?? null) === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="ticket-form-field-tags">Tags (comma-separated)</label>
            <input type="text" name="tags" id="ticket-form-field-tags" class="form-control btn-touch mb-3" maxlength="200">
            <label class="form-label fs-12 text-muted" for="ticket-form-field-cc">CC (emails, comma-separated)</label>
            <input type="text" name="cc" id="ticket-form-field-cc" class="form-control btn-touch mb-3" maxlength="400">
            <?php endif; ?>
            <?php if ($canOnBehalf || $cur !== null): ?><label class="form-label fs-12 text-muted" for="ticket-form-field-due">Due</label><input type="datetime-local" name="due_at" id="ticket-form-field-due" class="form-control btn-touch mb-3" value="<?= !empty($cur['due_at']) ? e(format_ts($cur['due_at'], member_timezone(), 'Y-m-d\TH:i')) : '' ?>"><?php endif; ?>
            <?= view('tickets/partials/custom-fields.php', ['defs' => $fieldDefs, 'values' => $cv]) ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="ticket-form-save-btn"><?= $cur === null ? 'File it' : 'Save' ?></button>
        <?= hx_link($cur === null ? '/' : '/tickets/' . (int) $id, 'Cancel', 'btn btn-light btn-touch w-100', 'id="ticket-form-cancel-link"') ?>
    </form>
</div>
