<?php /** One report (screen `reports`). Data: report, title, queues, queue, period, groupBy, columns, rows (cells), totals, page, more, unknownQueue, rowCount, csvUrl, allowsGroup */ $qs = $queue; ?>
<?= view('shared/header.php', ['id' => 'reports', 'title' => $title, 'crumbs' => [['Home', '/'], ['Reports', '/reports/'], [$title, null]], 'back' => ['/reports/' . ($qs !== null ? '?queue=' . $qs : ''), 'Reports'],
    'action' => $unknownQueue ? '' : '<a href="' . e($csvUrl) . '" class="btn btn-light btn-touch" id="report-csv-link" download><i class="feather-download me-1"></i>Download CSV</a>']) ?>
<div class="main-content" id="reports-content">
    <div class="d-flex flex-wrap gap-1 mb-3" id="report-tabs"><?php foreach (REPORTS as $k => $l): ?><?= hx_link('/reports/' . $k . '?' . http_build_query(array_filter(['queue' => $qs, 'from' => $period['from'], 'to' => $period['to'], 'group_by' => $groupBy])), e($l), 'btn btn-sm btn-touch ' . ($k === $report ? 'btn-primary' : 'btn-light'), 'id="report-tab-' . $k . '"') ?><?php endforeach; ?></div>
    <form method="get" action="/reports/<?= e($report) ?>" class="card mb-3" id="report-filter" hx-get="/reports/<?= e($report) ?>" hx-target="#page-content" hx-push-url="true" hx-trigger="change"><div class="card-body d-flex flex-wrap align-items-end gap-2">
        <div><label class="form-label fs-12 text-muted mb-0" for="report-filter-queue">Queue</label><select name="queue" id="report-filter-queue" class="form-select btn-touch"><option value="">All my queues</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= $qs === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select></div>
        <?php if ($report !== 'aging'): ?>
        <div><label class="form-label fs-12 text-muted mb-0" for="report-filter-from">From</label><input type="date" name="from" id="report-filter-from" class="form-control btn-touch" value="<?= e($period['from']) ?>"></div>
        <div><label class="form-label fs-12 text-muted mb-0" for="report-filter-to">To</label><input type="date" name="to" id="report-filter-to" class="form-control btn-touch" value="<?= e($period['to']) ?>"></div>
        <?php endif; ?>
        <?php if ($allowsGroup): ?><div><label class="form-label fs-12 text-muted mb-0" for="report-filter-group">Group by</label><select name="group_by" id="report-filter-group" class="form-select btn-touch"><?php foreach (REPORT_GROUPS as $k => $l): ?><option value="<?= e($k) ?>" <?= $groupBy === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="fs-12 text-muted ms-auto" id="report-period-words"><?= $report === 'aging' ? 'Open tickets now' : e($period['from'] . ' → ' . $period['to'] . ' · ' . $period['days'] . ' day' . ($period['days'] === 1 ? '' : 's')) ?></div>
    </div></form>
    <?php if ($unknownQueue): ?><div class="card"><div class="card-body text-muted" id="report-unknown">Nothing to report for that queue.</div></div>
    <?php else: ?>
    <div class="card" id="report-card"><?= view('reports/partials/table.php', ['columns' => array_values($columns), 'rows' => $rows, 'totals' => $totals, 'id' => 'report-table']) ?></div>
    <div class="d-flex align-items-center gap-2 py-2 fs-12 text-muted" id="report-footer"><span id="report-row-count"><?= (int) $rowCount ?> row<?= $rowCount === 1 ? '' : 's' ?></span>
        <?php if ($page > 1 || $more): ?><nav class="ms-auto"><ul class="pagination pagination-sm mb-0"><?php if ($page > 1): ?><li class="page-item"><?= hx_link('/reports/' . $report . '?' . http_build_query(array_filter(['queue' => $qs, 'from' => $period['from'], 'to' => $period['to'], 'group_by' => $groupBy, 'page' => $page - 1])), 'Previous', 'page-link') ?></li><?php endif; ?><?php if ($more): ?><li class="page-item"><?= hx_link('/reports/' . $report . '?' . http_build_query(array_filter(['queue' => $qs, 'from' => $period['from'], 'to' => $period['to'], 'group_by' => $groupBy, 'page' => $page + 1])), 'Next', 'page-link') ?></li><?php endif; ?></ul></nav><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
