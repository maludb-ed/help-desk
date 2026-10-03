<?php
declare(strict_types=1);
/** /ratings/ — the ratings received (screen `ratings-list`; reports.view, or a queue's holder for their queue — the views decide what is seen): bad ones first, the CSAT percentage for the filter; ?format=csv exports (report.export). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('reports.view') && !has_right('tickets.view_queue')) {
    refuse(403, 'You may not see reports.');
}
require_human();
$pdo = db();
$me = (int) current_member_id();
$filters = ['score' => in_array(request_string('score'), ['bad', 'good'], true) ? request_string('score') : 'all', 'queue' => request_integer('queue') ?? 0, 'period' => max(0, request_integer('period') ?? 0)];
$rows = find_ratings($pdo, $me, $filters, 500);
$cs = find_csat_settings($pdo);
$query = array_filter(['score' => $filters['score'] === 'all' ? null : $filters['score'], 'queue' => $filters['queue'] ?: null, 'period' => $filters['period'] ?: null]);
if (request_string('format') === 'csv') {
    log_activity($pdo, 'report.export', null, null, ['after' => ['report' => 'ratings', 'rows' => count($rows), 'filters' => $query]]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ratings.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['responded_at', 'ticket', 'subject', 'queue', 'score', 'words', 'bad_reason', 'comment', 'assignee']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['responded_at'], $r['number'], $r['subject'], $r['queue_name'], $r['score'], score_words($r['score'], $cs['scale']), $r['bad_reason'], $r['comment'], $r['assignee_name']]);
    }
    exit;
}
log_screen_view($pdo, 'ratings-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'csat_percent' => csat_percent($rows), 'scale' => $cs['scale'], 'ratings' => array_map('present_rating', $rows)]);
}
$data = ['rows' => $rows, 'filters' => $filters, 'queues' => find_queues($pdo, ['mine' => !has_right('tickets.view_all')]), 'cs' => $cs, 'percent' => csat_percent($rows), 'tz' => member_timezone(), 'here' => here_url(), 'query' => $query];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'ratings-table') {
    header('Vary: HX-Request');
}
render_screen('Ratings', view('ratings/list.php', $data), ['activeNav' => 'reports', 'screen' => 'ratings-list', 'entity' => 'rating']);
