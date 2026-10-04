<?php /** The queues now (screen `queue-dashboard`). Data: queues, queue (?int), queueName, counts, byPriority, aging, workload, unknownQueue (bool), here */ $qs = $queue; $L = static fn (array $f): string => inbox_link($qs, $f); ?>
<?= view('shared/header.php', ['id' => 'queue-dashboard', 'title' => 'Reports', 'crumbs' => [['Home', '/'], ['Reports', null]],
    'action' => '<div class="d-flex flex-wrap gap-1 justify-content-end" id="queue-dashboard-reports">' . implode('', array_map(static fn (string $k, string $l): string => hx_link('/reports/' . $k . ($qs !== null ? '?queue=' . $qs : ''), e($l), 'btn btn-light btn-sm btn-touch', 'id="report-link-' . $k . '"'), array_keys(REPORTS), REPORTS)) . '</div>']) ?>
<div class="main-content" id="queue-dashboard-content" hx-get="/reports/<?= $qs !== null ? '?queue=' . $qs : '' ?>" hx-trigger="every 60s" hx-select="#queue-dashboard-content" hx-swap="outerHTML" hx-target="this" hx-disinherit="*">
    <form method="get" action="/reports/" class="d-flex flex-wrap align-items-center gap-2 mb-3" id="queue-dashboard-filter" hx-get="/reports/" hx-target="#page-content" hx-push-url="true" hx-trigger="change">
        <label class="fs-12 text-muted mb-0" for="queue-dashboard-queue">Queue</label>
        <select name="queue" id="queue-dashboard-queue" class="form-select btn-touch w-auto"><option value="">All my queues</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= $qs === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
        <span class="fs-11 text-muted ms-auto" id="queue-dashboard-refreshed">Refreshes every minute · <?= e((new DateTimeImmutable('now', new DateTimeZone(member_timezone())))->format('g:i A')) ?></span>
    </form>
    <?php if ($unknownQueue): ?><div class="card"><div class="card-body text-muted" id="queue-dashboard-unknown">Nothing to report for that queue.</div></div>
    <?php else: ?>
    <?php if ($counts['open'] === 0): ?><div class="alert alert-light border mb-3" id="queue-dashboard-empty">Nothing open<?= $queueName !== null ? ' in ' . e($queueName) : '' ?>.</div><?php endif; ?>
    <div class="row g-2 mb-3" id="queue-dashboard-cards">
        <?php $chips = ''; foreach ($counts['by_category'] as $c => $n) { if ($n > 0) { $chips .= '<span class="badge bg-soft-secondary text-secondary me-1">' . e(STATUS_CATEGORIES[$c] ?? $c) . ' ' . $n . '</span>'; } } ?>
        <?= view('reports/partials/count-card.php', ['id' => 'dash-open', 'label' => 'Open', 'n' => $counts['open'], 'href' => $L(['status' => implode(',', REPORT_OPEN_CATEGORIES)]), 'icon' => 'feather-inbox', 'sub' => $chips]) ?>
        <?= view('reports/partials/count-card.php', ['id' => 'dash-needs-reply', 'label' => 'Needs reply', 'n' => $counts['needs_reply'], 'href' => $L(['needs_reply' => 1]), 'icon' => 'feather-message-circle', 'tone' => $counts['needs_reply'] > 0 ? 'warning' : 'secondary']) ?>
        <?= view('reports/partials/count-card.php', ['id' => 'dash-unassigned', 'label' => 'Unassigned', 'n' => $counts['unassigned'], 'href' => $L(['assignee' => 'none', 'status' => implode(',', REPORT_OPEN_CATEGORIES)]), 'icon' => 'feather-user-x', 'tone' => $counts['unassigned'] > 0 ? 'warning' : 'secondary']) ?>
        <?= view('reports/partials/count-card.php', ['id' => 'dash-sla', 'label' => 'At risk / Breached', 'n' => $counts['at_risk'] . ' / ' . $counts['breached'], 'href' => $L(['sla' => $counts['breached'] > 0 ? 'breached' : 'at_risk']), 'icon' => 'feather-alert-triangle', 'tone' => $counts['breached'] > 0 ? 'danger' : ($counts['at_risk'] > 0 ? 'warning' : 'secondary'),
            'sub' => '<a href="' . e($L(['sla' => 'at_risk'])) . '" id="dash-sla-at-risk-link">at risk</a> · <a href="' . e($L(['sla' => 'breached'])) . '" id="dash-sla-breached-link">breached</a>']) ?>
        <?= view('reports/partials/count-card.php', ['id' => 'dash-new', 'label' => 'New in triage', 'n' => $counts['by_category']['new'], 'href' => $L(['status' => 'new']), 'icon' => 'feather-sunrise']) ?>
        <?= view('reports/partials/count-card.php', ['id' => 'dash-stale', 'label' => 'Stale (7 d)', 'n' => $counts['stale'], 'href' => $L(['stale_days' => 7, 'status' => implode(',', REPORT_OPEN_CATEGORIES)]), 'icon' => 'feather-clock', 'tone' => $counts['stale'] > 0 ? 'warning' : 'secondary']) ?>
    </div>
    <div class="row g-3" id="queue-dashboard-tables">
        <div class="col-12 col-xl-4"><div class="card h-100 mb-0" id="dash-priority"><div class="card-header"><h5 class="card-title">By priority</h5></div>
            <div class="table-responsive" id="dash-priority-table-wrap"><table class="table table-sm table-hover mb-0 fs-12" id="dash-priority-table"><thead class="thead-light"><tr><th>Priority</th><th class="text-end">Open</th><th class="text-end">At risk</th><th class="text-end">Needs reply</th></tr></thead><tbody>
            <?php foreach ($byPriority as $r): ?><tr id="dash-priority-<?= e($r['priority']) ?>"><td><span class="badge bg-<?= e(PRIORITY_COLORS[$r['priority']]) ?>"><?= e(ucfirst($r['priority'])) ?></span></td>
                <td class="text-end"><a href="<?= e($L(['priority' => $r['priority'], 'status' => implode(',', REPORT_OPEN_CATEGORIES)])) ?>" id="dash-priority-<?= e($r['priority']) ?>-open"><?= (int) $r['open'] ?></a></td>
                <td class="text-end <?= $r['at_risk'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $r['at_risk'] ?></td>
                <td class="text-end"><a href="<?= e($L(['priority' => $r['priority'], 'needs_reply' => 1])) ?>"><?= (int) $r['needs_reply'] ?></a></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        <div class="col-12 col-xl-4"><div class="card h-100 mb-0" id="dash-aging"><div class="card-header"><h5 class="card-title">Aging</h5></div>
            <div class="table-responsive" id="dash-aging-table-wrap"><table class="table table-sm table-hover mb-0 fs-12 text-nowrap" id="dash-aging-table"><thead class="thead-light"><tr><th>Age</th><?php foreach (REPORT_PRIORITIES as $p): ?><th class="text-end"><?= e(ucfirst($p)) ?></th><?php endforeach; ?><th class="text-end">Total</th></tr></thead><tbody>
            <?php foreach ($aging as $r): ?><tr id="dash-aging-<?= e($r['bucket']) ?>"><td><?= e(AGING_BUCKETS[$r['bucket']]) ?></td><?php foreach (REPORT_PRIORITIES as $p): ?><td class="text-end"><?= (int) $r[$p] ?></td><?php endforeach; ?><td class="text-end fw-semibold"><?= (int) $r['total'] ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
        <div class="col-12 col-xl-4"><div class="card h-100 mb-0" id="dash-workload"><div class="card-header"><h5 class="card-title">Workload</h5><?= hx_link('/reports/workload' . ($qs !== null ? '?queue=' . $qs : ''), 'Report', 'fs-12') ?></div>
            <div class="table-responsive" id="dash-workload-table-wrap"><table class="table table-sm table-hover mb-0 fs-12 text-nowrap" id="dash-workload-table"><thead class="thead-light"><tr><th>Who</th><th class="text-end">Open</th><th class="text-end">Needs reply</th><th class="text-end">At risk</th><th class="text-end">Capacity</th><th>Availability</th></tr></thead><tbody>
            <?php if ($workload === []): ?><tr><td colspan="6" class="text-center text-muted py-3" id="dash-workload-empty">Nobody in this queue.</td></tr><?php endif; ?>
            <?php foreach ($workload as $w): $mid = (int) $w['member_id']; ?><tr id="workload-row-<?= $mid ?>" class="<?= $w['over_capacity'] ? 'table-danger' : '' ?>">
                <td><i class="<?= $w['kind'] === 'agent' ? 'feather-cpu' : 'feather-user' ?> me-1 text-muted" title="<?= $w['kind'] === 'agent' ? 'Agent' : 'Person' ?>"></i><a href="<?= e($L(['assignee' => $mid, 'status' => implode(',', REPORT_OPEN_CATEGORIES)])) ?>" id="workload-row-<?= $mid ?>-name"><?= e($w['display_name']) ?></a></td>
                <td class="text-end <?= $w['over_capacity'] ? 'text-danger fw-bold' : '' ?>" id="workload-row-<?= $mid ?>-open"><?= (int) $w['open_now'] ?></td><td class="text-end"><?= (int) $w['needs_reply'] ?></td><td class="text-end <?= $w['at_risk'] > 0 ? 'text-danger' : '' ?>"><?= (int) $w['at_risk'] ?></td>
                <td class="text-end" id="workload-row-<?= $mid ?>-capacity"><?= $w['capacity'] === null ? '—' : (int) $w['capacity'] ?></td><td id="workload-row-<?= $mid ?>-availability"><?= $w['availability'] === null ? '<span class="text-muted">—</span>' : '<span class="badge bg-soft-' . ($w['availability'] === 'online' ? 'success text-success' : ($w['availability'] === 'away' ? 'warning text-warning' : 'secondary text-secondary')) . '">' . e($w['availability']) . '</span>' ?></td></tr><?php endforeach; ?>
            </tbody></table></div></div></div>
    </div>
    <?php endif; ?>
</div>
