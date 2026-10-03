<?php /** The ratings received (screen `ratings-list`): a ledger, bad ones first. Data: rows, filters, queues, cs, percent, tz, here, query */ ?>
<?= view('shared/header.php', ['id' => 'ratings-list', 'title' => 'Ratings', 'crumbs' => [['Home', '/'], ['Reports', '/reports/'], ['Ratings', null]],
    'action' => hx_link('/ratings/?' . http_build_query($query + ['format' => 'csv']), '<i class="feather-download me-1"></i>CSV', 'btn btn-light btn-touch', 'id="ratings-csv-link" hx-boost="false"')]) ?>
<div class="main-content" id="ratings-list-content">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <span class="badge bg-<?= $percent === null ? 'secondary' : ($percent >= 80 ? 'success' : ($percent >= 60 ? 'warning' : 'danger')) ?> fs-12" id="ratings-csat"><?= $percent === null ? 'No ratings yet' : 'CSAT ' . $percent . '%' ?></span>
        <span class="fs-12 text-muted"><?= count($rows) ?> rating<?= count($rows) === 1 ? '' : 's' ?></span>
    </div>
    <form method="get" action="/ratings/" hx-get="/ratings/" hx-target="#ratings-table" hx-select="#ratings-table" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change" class="card mb-3" id="ratings-filters"><div class="card-body d-flex flex-wrap gap-2">
        <select name="score" id="ratings-filter-score" class="form-select btn-touch w-auto" aria-label="Score"><?php foreach (['all' => 'All', 'bad' => 'Bad', 'good' => 'Good'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['score'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select name="queue" id="ratings-filter-queue" class="form-select btn-touch w-auto" aria-label="Queue"><option value="">Any queue</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= (int) $filters['queue'] === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
        <select name="period" id="ratings-filter-period" class="form-select btn-touch w-auto" aria-label="Period"><?php foreach ([0 => 'All time', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $k => $l): ?><option value="<?= $k ?>" <?= (int) $filters['period'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    </div></form>
    <div class="card" id="ratings-table"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12">
        <thead class="thead-light"><tr><th>When</th><th>Ticket</th><th>Queue</th><th>Score</th><th>Reason</th><th>Comment</th><th>Assigned</th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?><tr><td colspan="7" class="text-center text-muted py-4" id="ratings-empty">No ratings for this filter.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr id="rating-row-<?= (int) $r['rating_id'] ?>"><td class="text-nowrap text-muted"><?= e(format_ts($r['responded_at'], $tz, 'M j, Y')) ?></td><td class="text-nowrap"><?= hx_link(with_back('/tickets/' . (int) $r['ticket_id'], $here), e($r['number']), 'fw-semibold') ?><div class="text-muted text-truncate" style="max-width: 16rem"><?= e($r['subject']) ?></div></td>
                <td><?= e($r['queue_name']) ?></td><td><span class="badge bg-<?= e(score_color($r['score'])) ?>"><?= e(score_words($r['score'], $cs['scale'])) ?></span></td><td><?= e($r['bad_reason'] ?? '') ?></td>
                <td title="<?= e($r['comment'] ?? '') ?>"><?= e(mb_substr((string) $r['comment'], 0, 200)) ?><?= mb_strlen((string) $r['comment']) > 200 ? '…' : '' ?></td><td><?= e($r['assignee_name'] ?? '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div></div></div>
</div>
