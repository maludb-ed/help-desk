<?php /** The inbound ledger (screen `inbound-list`): never the body. Data: rows, filters, mailboxes, tz, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'inbound-list', 'title' => 'Inbound mail', 'crumbs' => [['Home', '/'], ['Mailboxes', '/settings/mailboxes/'], ['Inbound', null]], 'back' => ['/settings/mailboxes/', 'Mailboxes']]) ?>
<div class="main-content" id="inbound-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/settings/mailboxes/inbound" hx-get="/settings/mailboxes/inbound" hx-target="#inbound-table" hx-select="#inbound-table" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change" class="card mb-3" id="inbound-filters"><div class="card-body d-flex flex-wrap gap-2">
        <select name="status" id="inbound-filter-status" class="form-select btn-touch w-auto" aria-label="Status"><option value="problems" <?= $filters['status'] === 'problems' ? 'selected' : '' ?>>Problems</option><?php foreach (INBOUND_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select name="mailbox" id="inbound-filter-mailbox" class="form-select btn-touch w-auto" aria-label="Mailbox"><option value="">Any mailbox</option><?php foreach ($mailboxes as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $filters['mailbox'] === (int) $m['id'] ? 'selected' : '' ?>><?= e($m['address']) ?></option><?php endforeach; ?></select>
    </div></form>
    <div class="card" id="inbound-table"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12">
        <thead class="thead-light"><tr><th>Received</th><th>From</th><th>Subject</th><th>Mailbox</th><th>Status</th><th>Error</th><th>Ticket</th><th></th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?><tr><td colspan="8" class="text-center text-muted py-4" id="inbound-empty">Nothing for this filter.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $id = (int) $r['id']; $color = ['failed' => 'danger', 'bounce' => 'dark', 'auto_reply' => 'secondary', 'ignored' => 'light text-muted', 'suppressed' => 'warning', 'received' => 'info', 'processed' => 'success'][$r['status']] ?? 'secondary'; ?>
            <tr id="inbound-row-<?= $id ?>"><td class="text-nowrap text-muted"><?= e(format_ts($r['received_at'], $tz, 'M j, g:i A')) ?></td><td><?= e($r['from_address'] ?? '—') ?></td><td class="text-truncate" style="max-width: 18rem"><?= e($r['subject'] ?? '') ?></td><td><?= e($r['mailbox_address']) ?></td>
                <td><span class="badge bg-<?= e($color) ?>"><?= e(INBOUND_STATUSES[$r['status']] ?? $r['status']) ?></span></td><td class="text-muted" style="max-width: 16rem"><?= e(mb_substr((string) $r['error'], 0, 120)) ?></td>
                <td><?= $r['ticket_id'] !== null ? hx_link(with_back('/tickets/' . $r['ticket_id'], $here), e($r['ticket_number'] ?? '#' . $r['ticket_id']), 'fw-semibold') : '—' ?></td>
                <td class="text-nowrap"><?php if ($r['status'] === 'failed'): ?><form method="post" action="/settings/mailboxes/inbound/retry.php" hx-post="/settings/mailboxes/inbound/retry.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="inbound" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm" id="inbound-row-<?= $id ?>-retry">Retry</button></form><?php endif; ?>
                    <?php if (!in_array($r['status'], ['processed', 'ignored'], true)): ?><form method="post" action="/settings/mailboxes/inbound/ignore.php" hx-post="/settings/mailboxes/inbound/ignore.php" hx-target="#flash" hx-prompt="Why ignore it?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="inbound" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-sm" id="inbound-row-<?= $id ?>-ignore">Ignore</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div></div>
</div>
