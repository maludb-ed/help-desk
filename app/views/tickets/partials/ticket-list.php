<?php /** The list region (Pattern B, #ticket-list): cards on a phone, a table from 992 px, paging under. Data: rows, page, more, query, here, tz, base (the URL base) */
$pageUrl = static fn (int $p): string => $base . '?' . http_build_query($query + ['page' => $p]); ?>
<div id="ticket-list" hx-get="<?= e($base . '?' . http_build_query($query + ['page' => $page])) ?>" hx-trigger="ticketChanged from:body" hx-swap="outerHTML" hx-target="this">
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="ticket-list-empty">Nothing in this view.</div></div><?php endif; ?>
    <div class="d-lg-none" id="ticket-list-cards"><?php foreach ($rows as $t): ?><?= view('tickets/partials/ticket-card.php', ['t' => $t, 'here' => $here, 'tz' => $tz]) ?><?php endforeach; ?></div>
    <?php if ($rows !== []): ?>
    <div class="card d-none d-lg-block" id="ticket-list-table-card"><div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ticket-list-table">
        <thead class="thead-light"><tr><th>#</th><th>Subject</th><th>Requester</th><th>Queue</th><th>Assignee</th><th>Status</th><th>Priority</th><th>SLA</th><th>Last activity</th></tr></thead>
        <tbody><?php foreach ($rows as $t): ?><?= view('tickets/partials/ticket-row.php', ['t' => $t, 'here' => $here, 'tz' => $tz]) ?><?php endforeach; ?></tbody>
    </table></div></div></div>
    <?php endif; ?>
    <?php if ($page > 1 || $more): ?>
    <nav class="py-2" id="ticket-list-pagination"><ul class="pagination mb-0">
        <?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page - 1)) ?>" hx-get="<?= e($pageUrl($page - 1)) ?>" hx-target="#ticket-list" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page - 1)) ?>">Newer</a></li><?php endif; ?>
        <?php if ($more): ?><li class="page-item"><a class="page-link" href="<?= e($pageUrl($page + 1)) ?>" hx-get="<?= e($pageUrl($page + 1)) ?>" hx-target="#ticket-list" hx-swap="outerHTML" hx-push-url="<?= e($pageUrl($page + 1)) ?>">Older</a></li><?php endif; ?>
    </ul></nav>
    <?php endif; ?>
</div>
