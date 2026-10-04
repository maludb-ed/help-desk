<?php
declare(strict_types=1);
/**
 * /reports/ — the queues now (screen `queue-dashboard`; reports.view, or a queue's holder with tickets.view_queue for their queues — the views already narrow it): the headline counts,
 * by priority, aging, workload; ?queue= one of mine (another: "Nothing to report for that queue."); every number links to the inbox with the matching filter; refreshes every 60 s.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
require_login();
if (!has_right('reports.view') && !has_right('tickets.view_queue')) {
    refuse(403, 'You may not see reports.');
}
require_human();
$pdo = db();
$queues = report_queues($pdo);
$queue = request_integer('queue');
$queueName = null;
$unknown = false;
if ($queue !== null) {
    $match = array_values(array_filter($queues, static fn (array $q): bool => $q['queue_id'] === $queue));
    $unknown = $match === [];
    $queueName = $match[0]['name'] ?? null;
}
$counts = $unknown ? ['open' => 0, 'by_category' => array_fill_keys(REPORT_OPEN_CATEGORIES, 0), 'needs_reply' => 0, 'unassigned' => 0, 'at_risk' => 0, 'breached' => 0, 'stale' => 0] : dashboard_counts($pdo, $queue);
$byPriority = $unknown ? [] : dashboard_by_priority($pdo, $queue);
$aging = $unknown ? [] : report_aging($pdo, 'none', $queue);
$workload = $unknown ? [] : dashboard_workload($pdo, $queue);
log_activity($pdo, 'screen.view', null, null, ['screen' => 'queue-dashboard', 'queue_id' => $queue, 'after' => ['queue_id' => $queue]]);
if (wants_json()) {
    respond_screen(['queue_id' => $queue, 'queue_name' => $queueName, 'unknown_queue' => $unknown, 'queues' => $queues, 'counts' => $counts, 'by_priority' => $byPriority,
        'aging' => array_map(static fn (array $r): array => ['bucket' => $r['bucket'], 'label' => AGING_BUCKETS[$r['bucket']], 'total' => $r['total']] + array_intersect_key($r, array_fill_keys(REPORT_PRIORITIES, 1)), $aging),
        'workload' => $workload, 'links' => ['open' => inbox_link($queue, ['status' => implode(',', REPORT_OPEN_CATEGORIES)]), 'needs_reply' => inbox_link($queue, ['needs_reply' => 1]), 'unassigned' => inbox_link($queue, ['assignee' => 'none', 'status' => implode(',', REPORT_OPEN_CATEGORIES)]), 'at_risk' => inbox_link($queue, ['sla' => 'at_risk']), 'breached' => inbox_link($queue, ['sla' => 'breached']), 'new' => inbox_link($queue, ['status' => 'new']), 'stale' => inbox_link($queue, ['stale_days' => 7, 'status' => implode(',', REPORT_OPEN_CATEGORIES)])]]);
}
render_screen('Reports', view('reports/dashboard.php', ['queues' => $queues, 'queue' => $queue, 'queueName' => $queueName, 'counts' => $counts, 'byPriority' => $byPriority, 'aging' => $aging, 'workload' => $workload, 'unknownQueue' => $unknown, 'here' => here_url()]),
    ['activeNav' => 'reports', 'screen' => 'queue-dashboard', 'entity' => 'report']);
