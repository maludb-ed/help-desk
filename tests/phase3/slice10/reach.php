<?php
/** Proof — reach (spec "Proof", 3) and the dashboard's links and words (4, 5): Lena every queue; Marco IT only; Priya 403; an agent through the same functions; every count links to an inbox listing exactly that count; the empty queue's sentence. */
require __DIR__ . '/lib.php';
$F = dated();
$lena = as_member(28); $marco = as_member(27); $priya = as_member(26); $colin = as_member(29);
echo "1. Reach\n";
$r = page($lena, '/reports/');
preg_match_all('/<option value="(\d+)"[^>]*>([^<]+)</', substr($r['body'], strpos($r['body'], 'id="queue-dashboard-queue"')), $m);
ok($r['code'] === 200 && count($m[1]) === count(q('SELECT 1 FROM queues WHERE archived_at IS NULL')), 'Lena (reports.view) is offered every live queue (' . count($m[1]) . ')');
$r = page($marco, '/reports/');
preg_match_all('/<option value="(\d+)"[^>]*>([^<]+)</', substr($r['body'], strpos($r['body'], 'id="queue-dashboard-queue"')), $m);
ok($r['code'] === 200 && $m[2] === ['SMOKE IT help'], 'Marco (IT holder, no reports.view) opens the dashboard; the selector offers IT alone');
$r = page($marco, '/reports/?queue=' . $F['fac']);
ok($r['code'] === 200 && str_contains($r['body'], 'Nothing to report for that queue.') && !str_contains($r['body'], 'id="dash-open"'), '?queue= another: "Nothing to report for that queue." and no numbers');
[$c, $d] = report_json('volume', sixty() + ['group_by' => 'queue'], $marco);
ok($c === 200 && array_column($d['rows'], 0) === ['SMOKE IT help'], "Marco's volume report lists IT only");
[$c, $d] = report_json('volume', sixty(), $marco);
$itCreated = (int) one("SELECT count(*) FROM tickets WHERE queue_id = :q AND merged_into_id IS NULL", ['q' => $F['it']]);
ok(cell_int($d['totals'][1]) === $itCreated, "and its total is IT's own count ($itCreated)");
$r = page($priya, '/reports/');
ok($r['code'] === 403 && str_contains(html_entity_decode(strip_tags($r['body']), ENT_QUOTES), 'You may not see reports.'), 'Priya: 403 "You may not see reports."');
ok(page($priya, '/reports/volume')['code'] === 403 && page($priya, '/reports/volume?format=csv')['code'] === 403, 'and on a report and its CSV');
[$c, $d] = screen($colin, '/reports/');
ok($c === 200 && array_column($d['queues'], 'name') === ['SMOKE Facilities'], 'Colin (collaborator, Facilities) sees Facilities only');
if (right_of(40, 'tickets.view_queue')) {
    [$c, $d] = screen_token(40, '/reports/volume?' . http_build_query(sixty()));
    ok($c === 200 && array_column($d['rows'], 0) === ['SMOKE IT help'], 'the agent (granted support in IT) reads IT\'s numbers through the same functions');
} else {
    [$c, $d] = screen_token(40, '/reports/volume');
    ok($c === 403, 'the agent holds no queue here: 403 (the Phase 4 tools grant it support when hired)');
}
echo "2. The links\n";
[$c, $d] = screen($lena, '/reports/');
foreach (['open' => $d['counts']['open'], 'needs_reply' => $d['counts']['needs_reply'], 'unassigned' => $d['counts']['unassigned'], 'at_risk' => $d['counts']['at_risk'], 'breached' => $d['counts']['breached'], 'new' => $d['counts']['by_category']['new'], 'stale' => $d['counts']['stale']] as $k => $n) {
    $inbox = page($lena, $d['links'][$k]);
    $rows = preg_match_all('/<tr id="ticket-row-\d+"/', $inbox['body']);
    ok($inbox['code'] === 200 && $rows === $n, "$k: " . $d['links'][$k] . " lists exactly $n");
}
[$c, $d] = screen($lena, '/reports/?queue=' . $F['it']);
$inbox = page($lena, $d['links']['open']);
ok(preg_match_all('/<tr id="ticket-row-\d+"/', $inbox['body']) === $d['counts']['open'] && str_contains($d['links']['open'], 'queue=' . $F['it']), "with a queue chosen the links carry it (IT open " . $d['counts']['open'] . ')');
$body = page($lena, '/reports/')['body'];
ok(str_contains($body, 'id="dash-open-link"') && str_contains($body, 'id="workload-row-27"') && str_contains($body, 'workload-row-27" class="table-danger"') && str_contains($body, 'id="dash-priority-urgent-open"'), 'the page: count cards, the workload row over capacity in danger, the priority links');
ok(str_contains($body, 'feather-cpu') && str_contains($body, 'id="workload-row-40-capacity">—<'), 'the agent with its kind icon and no capacity');
ok(str_contains($body, 'hx-trigger="every 60s"'), 'the dashboard refreshes every 60 s');
echo "3. Words\n";
$r = page($lena, '/reports/?queue=' . $F['empty']);
ok($r['code'] === 200 && str_contains($r['body'], 'Nothing open in SMOKE Empty.') && str_contains($r['body'], 'id="dash-workload-empty"'), 'an empty queue: "Nothing open in SMOKE Empty." and nobody in the workload');
ok(leaks(page($lena, '/reports/')['body']) === [] && leaks(page($lena, '/reports/csat?' . http_build_query(sixty()))['body']) === [], 'the pages leak no address and no token');
exit($GLOBALS['fails'] === 0 ? 0 : 1);
