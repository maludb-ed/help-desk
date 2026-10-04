<?php
declare(strict_types=1);
/**
 * /reports/{report} — one report as a table and a CSV (screen `reports`; the dashboard's right): ?queue=, ?from=, ?to= (the last 30 days; at most 366), ?group_by= (queue · category ·
 * organization · assignee · day · week), ?page= (50 rows), ?format=csv (the whole table; log `report.export`). An unknown report: 404 "No such report."; a grouping not allowed: 422;
 * a period in the wrong order or too long: 422 with the sentence. The cells are the same strings on the page, in the JSON and in the CSV.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/present.php';
require_once dirname(__DIR__, 2) . '/app/features/reports/csv.php';
require_login();
if (!has_right('reports.view') && !has_right('tickets.view_queue')) {
    refuse(403, 'You may not see reports.');
}
$pdo = db();
$report = request_string('report');
if (!isset(REPORTS[$report])) {
    refuse(404, 'No such report.');
}
$groupBy = request_string('group_by', 'queue');
if (!isset(REPORT_GROUPS[$groupBy])) {
    refuse(422, "'" . mb_substr($groupBy, 0, 40) . "' is not a grouping (queue, category, organization, assignee, day, week).");
}
try {
    $period = report_period(request_string('from'), request_string('to'), business_timezone($pdo));
} catch (DomainException $e) {
    refuse(422, $e->getMessage());
}
$queues = report_queues($pdo);
$queue = request_integer('queue');
$unknown = $queue !== null && array_filter($queues, static fn (array $q): bool => $q['queue_id'] === $queue) === [];
$format = request_string('format');
$allowsGroup = !in_array($report, ['workload'], true);
[$columns, $typed] = $unknown ? [report_columns($report, $groupBy), []] : run_report($pdo, $report, $period, $groupBy, $queue);
$cells = report_cells($report, $typed);
$totals = report_totals($report, $typed);
$title = REPORTS[$report];
if ($format === 'csv') {
    if ($unknown) {
        refuse(404, 'Nothing to report for that queue.');
    }
    if (!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1') {
        log_activity($pdo, 'report.export', null, null, ['screen' => 'reports', 'queue_id' => $queue, 'after' => ['report' => $report, 'queue_id' => $queue, 'from' => $period['from'], 'to' => $period['to'], 'group_by' => $groupBy, 'rows' => count($cells)]]);
    }
    stream_csv('helpdesk-' . $report . '-' . $period['from'] . '-' . $period['to'] . '.csv', array_values($columns), $cells);
}
$page = max(1, request_integer('page') ?? 1);
$pageRows = array_slice($cells, ($page - 1) * 50, 50);
$more = count($cells) > $page * 50;
$query = array_filter(['queue' => $queue, 'from' => $period['from'], 'to' => $period['to'], 'group_by' => $allowsGroup ? $groupBy : null]);
log_activity($pdo, 'screen.view', null, null, ['screen' => 'reports', 'queue_id' => $queue, 'after' => ['report' => $report, 'queue_id' => $queue, 'from' => $period['from'], 'to' => $period['to'], 'group_by' => $groupBy]]);
if (wants_json()) {
    respond_screen(['report' => $report, 'title' => $title, 'period' => ['from' => $period['from'], 'to' => $period['to'], 'days' => $period['days'], 'timezone' => $period['tz']], 'group_by' => $allowsGroup ? $groupBy : null, 'queue_id' => $queue, 'unknown_queue' => $unknown,
        'columns' => array_values($columns), 'rows' => $pageRows, 'totals' => $cells === [] ? null : $totals, 'row_count' => count($cells), 'page' => $page, 'more' => $more, 'csv' => '/reports/' . $report . '?' . http_build_query($query + ['format' => 'csv'])]);
}
render_screen($title, view('reports/report.php', ['report' => $report, 'title' => $title, 'queues' => $queues, 'queue' => $queue, 'period' => $period, 'groupBy' => $groupBy, 'columns' => $columns, 'rows' => $pageRows, 'totals' => $totals,
    'page' => $page, 'more' => $more, 'unknownQueue' => $unknown, 'rowCount' => count($cells), 'csvUrl' => '/reports/' . $report . '?' . http_build_query($query + ['format' => 'csv']), 'allowsGroup' => $allowsGroup]),
    ['activeNav' => 'reports', 'screen' => 'reports', 'entity' => 'report', 'recordId' => $report]);
