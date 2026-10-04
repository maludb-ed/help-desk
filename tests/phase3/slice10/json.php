<?php
/** Proof — JSON and the screens (spec "Proof"): both screens answer JSON with the same cells as the page; screen.view logged with the report; the registry says every screen and action is built. */
require __DIR__ . '/lib.php';
$F = dated();
$lena = as_member(28);
$P = sixty();
echo "1. JSON\n";
[$c, $d] = screen($lena, '/reports/');
ok($c === 200 && isset($d['counts']['open'], $d['by_priority'], $d['aging'], $d['workload'], $d['links']['open'], $d['queues']) && $d['queue_id'] === null && $d['unknown_queue'] === false, 'queue-dashboard answers JSON: counts, by_priority, aging, workload, links, queues');
ok(count($d['by_priority']) === 4 && $d['by_priority'][0]['priority'] === 'urgent' && count($d['aging']) === 5 && $d['aging'][0]['bucket'] === 'today', 'four priorities urgent first; five age buckets today first');
[$c, $d] = screen($lena, '/reports/?queue=' . $F['fac']);
$facOpen = (int) one("SELECT count(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.queue_id = :q AND t.merged_into_id IS NULL AND s.category IN " . OPEN_SET, ['q' => $F['fac']]);
ok($c === 200 && $d['queue_id'] === $F['fac'] && $d['queue_name'] === 'SMOKE Facilities' && $d['counts']['open'] === $facOpen && $facOpen >= 2, "with ?queue=: the queue named and its counts (Facilities $facOpen open)");
[$c, $d] = report_json('first_response', $P + ['group_by' => 'queue']);
ok($c === 200 && $d['report'] === 'first_response' && $d['title'] === 'First response' && count($d['columns']) === 8 && count($d['rows']) === $d['row_count'] && is_array($d['totals']) && str_contains($d['csv'], 'format=csv'), 'reports answers JSON: report, title, columns, rows, totals, row_count, csv');
$page = page($lena, '/reports/first_response?' . http_build_query($P + ['group_by' => 'queue']))['body'];
$same = true;
foreach ($d['rows'] as $row) { foreach ($row as $cell) { if (!str_contains($page, '>' . e($cell) . '<')) { $same = false; } } }
ok($same && str_contains($page, 'id="report-table-totals"') && str_contains($page, 'id="report-csv-link"'), 'the page shows the same cells, the totals row and Download CSV');
$since = last_activity_id();
page($lena, '/reports/csat?' . http_build_query($P));
$rows = activity('screen.view', $since);
ok(count($rows) === 1 && json_decode($rows[0]['after'], true)['report'] === 'csat' && $rows[0]['queue_id'] === null, 'a look is screen.view with after.report');
$since = last_activity_id();
page($lena, '/reports/?queue=' . $F['it']);
$rows = activity('screen.view', $since);
ok(count($rows) === 1 && (int) $rows[0]['queue_id'] === $F['it'], 'the dashboard logs screen.view with the queue');
[$c, $d] = report_json('volume', $P + ['group_by' => 'day', 'page' => 2]);
ok($c === 200 && $d['page'] === 2 && $d['rows'] === [] && $d['more'] === false && $d['row_count'] >= 8, 'paging: page 2 of a short report is empty, row_count the whole');
echo "2. Phase 3 closes\n";
$reg = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/mcp/action_registry.json'), true);
$unbuiltS = array_keys(array_filter($reg['screens'], static fn (array $s): bool => !$s['built']));
$unbuiltA = array_keys(array_filter($reg['actions'], static fn (array $a): bool => !$a['built']));
ok(count($reg['screens']) === 65 && $unbuiltS === [] && count($reg['actions']) === 96 && $unbuiltA === [], '65 screens built, 96 actions built' . ($unbuiltS !== [] || $unbuiltA !== [] ? ' — unbuilt: ' . implode(',', $unbuiltS + $unbuiltA) : ''));
ok(trim((string) shell_exec('grep -rl "render_nav_stub(" ' . escapeshellarg(dirname(__DIR__, 3) . '/html'))) === '', 'no placeholder left in html/');
$conf = (string) file_get_contents(dirname(__DIR__, 3) . '/deploy/apache-helpdesk.conf');
ok(substr_count($conf, 'RewriteRule ^/reports/([a-z_]+)$ /reports/report.php?report=$1') === 2 && substr_count($conf, 'RewriteRule ^/kb/([a-z0-9-]+)$ /kb/article.php?slug=$1') === 2 && substr_count($conf, 'RewriteRule ^/settings/automation/([0-9]+)/runs$') === 2, "Phase 3's three rewrites are in both vhosts of the deploy template");
exit($GLOBALS['fails'] === 0 ? 0 : 1);
