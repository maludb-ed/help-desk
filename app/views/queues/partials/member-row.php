<?php /** One member of a queue. Data: m, q, may (members: may change membership), presence (availability/capacity or null), here */
$id = (int) $m['member_id']; $qid = (int) $q['queue_id']; $named = $m['how'] === 'named'; ?>
<div class="list-group-item d-flex align-items-center gap-2 py-2<?= $named ? '' : ' text-muted' ?>" id="queue-member-<?= $id ?>">
    <span class="avatar-text avatar-sm rounded"><i class="<?= $m['member_kind'] === 'agent' ? 'feather-cpu' : 'feather-user' ?>"></i></span>
    <div class="min-w-0 flex-grow-1">
        <div class="fw-semibold text-truncate"><?= e($m['display_name']) ?> <span class="badge bg-soft-<?= $m['member_kind'] === 'agent' ? 'info text-info' : 'secondary text-secondary' ?>"><?= $m['member_kind'] === 'agent' ? 'Agent' : 'Person' ?></span><?= $m['is_lead'] ? ' <span class="badge bg-soft-primary text-primary" id="queue-member-' . $id . '-lead">Lead</span>' : '' ?></div>
        <div class="fs-12"><?= $named ? 'Named' : 'Through ' . e($q['department_name'] ?? 'the department') ?><?= $presence !== null ? ' · ' . e(PRESENCE_STATES[$presence['availability']] ?? $presence['availability']) . ($presence['capacity'] !== null ? ', up to ' . (int) $presence['capacity'] . ' open' : '') : '' ?></div>
    </div>
    <?php if ($named && $may['members']): ?>
        <form method="post" action="/settings/queues/member.php" hx-post="/settings/queues/member.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="queue" value="<?= $qid ?>"><input type="hidden" name="member" value="<?= $id ?>"><input type="hidden" name="is_lead" value="<?= $m['is_lead'] ? 'no' : 'yes' ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="queue-member-<?= $id ?>-lead-btn" title="<?= $m['is_lead'] ? 'Not a lead' : 'Make lead' ?>"><i class="feather-<?= $m['is_lead'] ? 'star' : 'award' ?>"></i></button></form>
        <form method="post" action="/settings/queues/member-remove.php" hx-post="/settings/queues/member-remove.php" hx-target="#flash" hx-confirm="Remove <?= e($m['display_name']) ?> from <?= e($q['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="queue" value="<?= $qid ?>"><input type="hidden" name="member" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="queue-member-<?= $id ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form>
    <?php elseif (!$named): ?>
        <span class="fs-11 text-muted text-end">Leave the department in the OS</span>
    <?php endif; ?>
</div>
