<?php /** One ticket (screen `ticket-view`). Data: t, facts, conversation, moves, requester, history, internals, work, own, note, assignees, queues, pending, members, types, categories, fieldDefs, tz, here, notice, settings */
$id = (int) $facts['ticket_id']; $sla = $t['sla_state']; $isNew = $facts['status_category'] === 'new'; ?>
<?= view('shared/header.php', ['id' => 'ticket-view', 'title' => $t['number'] . ' ' . $t['subject'], 'crumbs' => [['Home', '/'], [$internals ? 'Inbox' : 'My tickets', $internals ? '/inbox' : '/my-tickets'], [$t['number'], null]], 'back' => back_link() ?? ($internals ? ['/inbox', 'Inbox'] : ['/my-tickets', 'My tickets']),
    'action' => $work ? hx_link(with_back('/tickets/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="ticket-view-edit-btn"') : '']) ?>
<div class="main-content" id="ticket-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3 sla-<?= e($sla ?? 'none') ?>" id="ticket-head"><div class="card-body py-2">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-<?= e($t['status_color']) ?>" id="ticket-head-status"><?= e($t['status_label']) ?></span>
            <span class="badge bg-soft-<?= e(PRIORITY_COLORS[$t['priority']]) ?> text-<?= e(PRIORITY_COLORS[$t['priority']]) ?>" id="ticket-head-priority"><?= e($t['priority']) ?></span>
            <?php if ($t['needs_reply']): ?><span class="badge bg-soft-warning text-warning"><i class="feather-corner-up-left"></i> needs reply</span><?php endif; ?>
            <?php if ($internals && ($w = sla_due_words($t['sla_next_due_at'], $sla)) !== ''): ?><span class="badge bg-soft-<?= $sla === 'breached' ? 'danger text-danger' : ($sla === 'at_risk' ? 'warning text-warning' : 'secondary text-secondary') ?>" id="ticket-head-sla"><?= e($w) ?></span><?php endif; ?>
            <span class="fs-12 text-muted" id="ticket-head-queue"><?= e($t['queue_name']) ?> › <?= $t['assignee_name'] ? e($t['assignee_name']) . ($t['assignee_kind'] === 'agent' ? ' <i class="feather-cpu"></i>' : '') : ($internals ? 'nobody' : 'the team') ?></span>
            <span class="fs-12 text-muted ms-auto">opened <?= e(format_ts($t['created_at'], $tz, 'M j, g:i A')) ?> · last <?= e(format_ts($t['last_activity_at'], $tz, 'M j, g:i A')) ?></span>
        </div>
        <div class="fs-12 text-muted mt-1" id="ticket-viewers"><?php foreach ($t['viewers'] as $v): ?><span class="me-2"><i class="feather-eye"></i> <?= e($v['display_name']) ?> <?= $v['composing'] ? 'is replying' : 'is viewing' ?></span><?php endforeach; ?></div>
        <?php if (!$internals): ?><div class="fs-12 mt-1" id="ticket-summary"><?= $t['sla_next_due_at'] && in_array($facts['status_category'], OPEN_CATEGORIES, true) ? 'We aim to respond by ' . e(format_ts($t['sla_next_due_at'], $tz, 'D M j, g:i A')) . '.' : '' ?></div><?php endif; ?>
    </div></div>
    <?php if ($work && $isNew): ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="ticket-triage">
        <form method="post" action="/tickets/transition.php" hx-post="/tickets/transition.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="status" value="open"><input type="hidden" name="assignee" value="me"><button type="submit" class="btn btn-primary btn-touch" id="ticket-triage-accept-btn"><i class="feather-check me-1"></i>Accept</button></form>
        <details class="d-inline-block" id="ticket-triage-decline"><summary class="btn btn-light btn-touch">Decline…</summary><form method="post" action="/tickets/transition.php" hx-post="/tickets/transition.php" hx-target="#flash" class="border rounded p-2 mt-1 bg-body"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="status" value="solved"><input type="hidden" name="resolution_code" value="no_action"><textarea name="note" class="form-control form-control-sm mb-1" rows="2" required placeholder="Why (a note)" id="ticket-triage-decline-note"></textarea><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="ticket-triage-decline-btn">Decline</button></form></details>
        <details class="d-inline-block" id="ticket-triage-snooze"><summary class="btn btn-light btn-touch">Snooze…</summary><form method="post" action="/tickets/transition.php" hx-post="/tickets/transition.php" hx-target="#flash" class="border rounded p-2 mt-1 bg-body"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="status" value="pending">
            <select name="pending_until" class="form-select form-select-sm mb-1" id="ticket-triage-snooze-until"><?php $tzo = new DateTimeZone($tz); $now = new DateTimeImmutable('now', $tzo); foreach (['tomorrow 09:00' => 'Tomorrow 9:00', '+3 days 09:00' => 'In 3 days', 'next monday 09:00' => 'Next week'] as $spec => $l): ?><option value="<?= e($now->modify($spec)->format('Y-m-d\TH:i')) ?>"><?= e($l) ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="ticket-triage-snooze-btn">Snooze</button></form></details>
        <?php if (has_right('tickets.manage')): ?><?= hx_link(with_back('/tickets/' . $id . '/merge', $here), 'Duplicate…', 'btn btn-light btn-touch', 'id="ticket-triage-duplicate-btn"') ?><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($own && !$work): ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="ticket-own-actions">
        <?php if (in_array($facts['status_category'], ['pending', 'open', 'on_hold'], true) && $t['last_agent_reply_at'] !== null): ?><form method="post" action="/tickets/confirm.php" hx-post="/tickets/confirm.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><button type="submit" class="btn btn-success btn-touch" id="ticket-confirm-btn"><i class="feather-check me-1"></i>Confirm it is resolved</button></form><?php endif; ?>
        <?php if ($facts['status_category'] === 'solved'): ?><form method="post" action="/tickets/reopen.php" hx-post="/tickets/reopen.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="ticket-reopen-btn"><i class="feather-rotate-ccw me-1"></i>Reopen</button></form><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <?= view('tickets/partials/conversation.php', ['conversation' => $conversation, 'internals' => $internals, 'tz' => $tz, 'here' => $here]) ?>
            <?= view('tickets/partials/composer.php', ['t' => $t, 'facts' => $facts, 'moves' => $moves, 'work' => $work, 'own' => $own, 'note' => $note, 'pending' => $pending, 'members' => $members, 'here' => $here]) ?>
        </div>
        <div class="col-lg-5">
            <?php if ($work): ?><?= view('tickets/partials/fields-panel.php', ['t' => $t, 'facts' => $facts, 'moves' => $moves, 'assignees' => $assignees, 'queues' => $queues, 'types' => $types, 'categories' => $categories, 'fieldDefs' => $fieldDefs, 'tz' => $tz, 'here' => $here]) ?>
            <?php elseif ($internals): ?><div class="card mb-3" id="ticket-fields"><div class="card-body"><?= view('tickets/partials/sla-strip.php', ['t' => $t, 'tz' => $tz]) ?></div></div><?php endif; ?>
            <?= view('tickets/partials/requester-card.php', ['t' => $t, 'facts' => $facts, 'requester' => $requester, 'internals' => $internals, 'work' => $work, 'own' => $own, 'here' => $here]) ?>
            <?php if ($internals): ?><?= view('tickets/partials/links.php', ['t' => $t, 'facts' => $facts, 'work' => $work, 'here' => $here]) ?><?php endif; ?>
            <?php if (has_right('tickets.manage') && $facts['status_category'] !== 'closed'): ?>
            <div class="d-flex gap-1 mb-3" id="ticket-manage">
                <details class="flex-grow-1"><summary class="btn btn-light btn-touch w-100" id="ticket-close-btn">Close…</summary><form method="post" action="/tickets/close.php" hx-post="/tickets/close.php" hx-target="#flash" class="border rounded p-2 mt-1 bg-body"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><select name="resolution_code" class="form-select form-select-sm mb-1" id="ticket-close-code"><option value="">Keep its code</option><?php foreach (RESOLUTION_CODES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select><button type="submit" class="btn btn-dark btn-sm btn-touch w-100" id="ticket-close-save-btn">Close now</button></form></details>
                <form method="post" action="/tickets/spam.php" hx-post="/tickets/spam.php" hx-target="#flash" hx-confirm="Mark <?= e($t['number']) ?> as spam? It is solved as spam and tagged."><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch" id="ticket-spam-btn"><i class="feather-slash"></i></button></form>
                <?php if (is_helpdesk_admin()): ?><form method="post" action="/tickets/delete.php" hx-post="/tickets/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($t['number']) ?> for good, with its messages and files?"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch text-danger" id="ticket-delete-btn"><i class="feather-trash-2"></i></button></form><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="mt-3"><?= view('tickets/partials/timeline.php', ['history' => $history, 'internals' => $internals, 'tz' => $tz]) ?></div>
</div>
<script>
    // Who is on this ticket: every 30 s, with "composing" when a composer holds text (no log; the view forgets a row after 2 minutes).
    (function () {
        var id = <?= $id ?>, csrf = document.querySelector('#csrf-token-meta');
        function ping() {
            var composing = Array.prototype.some.call(document.querySelectorAll('#ticket-composer textarea'), function (t) { return t.value.trim() !== ''; });
            var body = new URLSearchParams({ ticket: id, composing: composing ? 'yes' : 'no', csrf_token: csrf ? csrf.content : '' });
            fetch('/tickets/viewing.php', { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf ? csrf.content : '' } }).catch(function () {});
        }
        ping();
        var timer = setInterval(function () { if (!document.getElementById('ticket-view-content')) { clearInterval(timer); return; } ping(); }, 30000);
    })();
</script>
