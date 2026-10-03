<?php /** The queues' mailboxes (screen `mailboxes-list`). Data: rows, notice, host */ ?>
<?= view('shared/header.php', ['id' => 'mailboxes-list', 'title' => 'Mailboxes', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Mailboxes', null]],
    'action' => hx_link('/settings/mailboxes/new', '<i class="feather-plus-circle me-1"></i>Add a mailbox', 'btn btn-primary btn-touch', 'id="mailboxes-new-btn"')]) ?>
<div class="main-content" id="mailboxes-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3"><?= hx_link('/settings/mailboxes/inbound', '<i class="feather-alert-circle me-1"></i>Inbound problems', 'btn btn-light btn-touch', 'id="mailboxes-inbound-link"') ?></div>
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-muted" id="mailboxes-empty">No mailbox yet — add the queue's address and point MaluMail's new-mail webhook at <code><?= e($host) ?>/channels/malumail/mail</code>.</div></div>
    <?php else: ?>
    <div class="row g-2" id="mailboxes-cards">
        <?php foreach ($rows as $m): $id = (int) $m['id']; ?>
        <div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="mailbox-card-<?= $id ?>"><div class="card-body d-flex flex-column gap-2">
            <div><div class="fw-bold text-truncate" id="mailbox-card-<?= $id ?>-address"><?= e($m['address']) ?></div><div class="fs-12 text-muted"><?= e($m['display_name']) ?> · <?= e($m['queue_name']) ?> · MaluMail</div></div>
            <div class="d-flex flex-wrap gap-1 fs-11">
                <span class="badge bg-soft-<?= $m['poll_enabled'] ? 'success text-success' : 'secondary text-secondary' ?>">polling <?= $m['poll_enabled'] ? 'on' : 'off' ?></span>
                <span class="badge bg-soft-<?= $m['secret_set'] ? 'success text-success' : 'warning text-warning' ?>" id="mailbox-card-<?= $id ?>-secret">webhook secret <?= $m['secret_set'] ? '✓' : 'not set' ?></span>
                <span class="badge bg-soft-secondary text-secondary">last poll <?= $m['last_polled_at'] ? e(format_ts($m['last_polled_at'], member_timezone(), 'M j, g:i A')) : 'never' ?></span>
                <?php if ($m['failures'] > 0): ?><?= hx_link('/settings/mailboxes/inbound?mailbox=' . $id, (int) $m['failures'] . ' inbound problem' . ($m['failures'] === 1 ? '' : 's'), 'badge bg-danger text-decoration-none', 'id="mailbox-card-' . $id . '-failures"') ?><?php endif; ?>
            </div>
            <div class="d-flex gap-1 mt-auto">
                <form method="post" action="/settings/mailboxes/poll.php" hx-post="/settings/mailboxes/poll.php" hx-target="#flash" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="mailbox" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="mailbox-card-<?= $id ?>-poll"><i class="feather-refresh-cw me-1"></i>Poll now</button></form>
                <?= hx_link('/settings/mailboxes/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch', 'id="mailbox-card-' . $id . '-edit"') ?>
            </div>
        </div></div></div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
