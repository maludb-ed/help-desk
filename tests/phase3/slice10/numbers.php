<?php
/** Proof — the numbers reconcile (spec "Proof", 1): each report's totals equal direct SQL over the fixture, computed both ways; the dashboard's counts too. */
require __DIR__ . '/lib.php';
$F = dated();
$lena = as_member(28);
$P = sixty();
echo "1. Volume\n";
[$c, $d] = report_json('volume', $P + ['group_by' => 'queue']);
[$start, $end] = bounds($d['period']);
$in = static fn (string $col): string => "($col >= :s AND $col < :e)";
$exp = q("SELECT count(*) FILTER (WHERE " . $in('created_at') . ") AS created, count(*) FILTER (WHERE " . $in('solved_at') . ") AS solved, count(*) FILTER (WHERE " . $in('closed_at') . ") AS closed,
                 count(*) FILTER (WHERE reopen_count > 0 AND " . $in('solved_at') . ") AS reopened,
                 count(*) FILTER (WHERE created_at < :e AND (solved_at IS NULL OR solved_at >= :e) AND status_id <> (SELECT id FROM ticket_statuses WHERE key = 'closed')) AS open_at_end,
                 count(*) FILTER (WHERE " . $in('created_at') . " AND channel = 'web') AS web, count(*) FILTER (WHERE " . $in('created_at') . " AND channel = 'email') AS email, count(*) FILTER (WHERE " . $in('created_at') . " AND channel = 'form') AS form, count(*) FILTER (WHERE " . $in('created_at') . " AND channel = 'agent') AS agent
            FROM tickets WHERE merged_into_id IS NULL", ['s' => $start, 'e' => $end])[0];
$tot = $d['totals'];
ok($c === 200 && cell_int($tot[1]) === (int) $exp['created'] && cell_int($tot[2]) === (int) $exp['solved'] && cell_int($tot[3]) === (int) $exp['closed'] && cell_int($tot[4]) === (int) $exp['reopened'] && cell_int($tot[5]) === (int) $exp['open_at_end'], 'volume totals: created ' . $tot[1] . ', solved ' . $tot[2] . ', closed ' . $tot[3] . ', reopened ' . $tot[4] . ', open at end ' . $tot[5] . ' = direct SQL');
ok(cell_int($tot[6]) === (int) $exp['web'] && cell_int($tot[7]) === (int) $exp['email'] && cell_int($tot[8]) === (int) $exp['form'] && cell_int($tot[9]) === (int) $exp['agent'], 'the channel columns reconcile');
$byQueue = [];
foreach ($d['rows'] as $r) { $byQueue[$r[0]] = $r; }
$itCreated = (int) one("SELECT count(*) FROM tickets WHERE queue_id = :q AND merged_into_id IS NULL AND " . $in('created_at'), ['q' => $F['it'], 's' => $start, 'e' => $end]);
ok(cell_int($byQueue['SMOKE IT help'][1]) === $itCreated && $itCreated >= 10, "IT help's created row equals direct SQL ($itCreated)");
ok(isset($byQueue['SMOKE Empty']) && $byQueue['SMOKE Empty'][1] === '0', 'a queue with nothing in the period shows a zero row');
ok(count($d['rows']) === $d['row_count'] && $d['row_count'] === count(q('SELECT 1 FROM queues WHERE archived_at IS NULL')), 'one row per live queue');
echo "2. First response and resolution\n";
[$c, $d] = report_json('first_response', $P);
$exp = q("SELECT count(*) AS tickets, count(first_response_at) AS responded, percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (first_response_at - created_at)) / 60) AS med,
                 (SELECT count(*) FROM sla_targets g JOIN tickets t2 ON t2.id = g.ticket_id WHERE g.metric = 'first_response' AND g.due_at IS NOT NULL AND t2.merged_into_id IS NULL AND t2.created_at >= :s AND t2.created_at < :e) AS with_target,
                 (SELECT count(*) FROM sla_targets g JOIN tickets t2 ON t2.id = g.ticket_id WHERE g.metric = 'first_response' AND g.met_at IS NOT NULL AND g.met_at <= g.due_at AND t2.merged_into_id IS NULL AND t2.created_at >= :s AND t2.created_at < :e) AS within
            FROM tickets WHERE merged_into_id IS NULL AND created_at >= :s AND created_at < :e", ['s' => $start, 'e' => $end])[0];
$tot = $d['totals'];
ok($c === 200 && cell_int($tot[1]) === (int) $exp['tickets'] && cell_int($tot[2]) === (int) $exp['responded'] && cell_int($tot[5]) === (int) $exp['with_target'] && cell_int($tot[6]) === (int) $exp['within'], 'first response totals: tickets, responded, with a target, within target = direct SQL (' . implode(' · ', array_slice($tot, 1)) . ')');
$it = null; foreach ($d['rows'] as $r) { if ($r[0] === 'SMOKE IT help') { $it = $r; } }
$itMed = (float) one("SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (first_response_at - created_at)) / 60) FROM tickets WHERE queue_id = :q AND merged_into_id IS NULL AND created_at >= :s AND created_at < :e AND first_response_at IS NOT NULL", ['q' => $F['it'], 's' => $start, 'e' => $end]);
ok($it !== null && cell_int($it[3]) === (int) round($itMed), "IT help's median first-response minutes = direct SQL (" . $it[3] . ')');
[$c, $d] = report_json('resolution', $P);
$exp = q("SELECT count(*) AS solved, percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (solved_at - created_at)) / 3600) AS med FROM tickets WHERE merged_into_id IS NULL AND solved_at >= :s AND solved_at < :e", ['s' => $start, 'e' => $end])[0];
ok($c === 200 && cell_int($d['totals'][1]) === (int) $exp['solved'], 'resolution: solved in the period = direct SQL (' . $d['totals'][1] . ')');
$it = null; foreach ($d['rows'] as $r) { if ($r[0] === 'SMOKE IT help') { $it = $r; } }
$itMed = (float) one("SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (solved_at - created_at)) / 3600) FROM tickets WHERE queue_id = :q AND merged_into_id IS NULL AND solved_at >= :s AND solved_at < :e", ['q' => $F['it'], 's' => $start, 'e' => $end]);
ok($it !== null && abs((float) str_replace(',', '', $it[2]) - $itMed) < 0.06, "IT help's median resolution hours = direct SQL (" . $it[2] . ')');
echo "3. SLA attainment\n";
[$c, $d] = report_json('sla_attainment', $P);
$exp = q("SELECT count(*) AS due, count(*) FILTER (WHERE g.met_at IS NOT NULL AND g.met_at <= g.due_at) AS met,
                 count(*) FILTER (WHERE g.breached_at IS NOT NULL OR (g.met_at IS NULL AND now() > g.due_at) OR (g.met_at IS NOT NULL AND g.met_at > g.due_at)) AS breached
            FROM sla_targets g JOIN tickets t ON t.id = g.ticket_id WHERE g.due_at IS NOT NULL AND t.merged_into_id IS NULL AND t.created_at >= :s AND t.created_at < :e", ['s' => $start, 'e' => $end])[0];
ok($c === 200 && cell_int($d['totals'][2]) === (int) $exp['due'] && cell_int($d['totals'][3]) === (int) $exp['met'] && cell_int($d['totals'][4]) === (int) $exp['breached'], 'SLA attainment totals: due, met, breached = direct SQL (' . $d['totals'][2] . ' · ' . $d['totals'][3] . ' · ' . $d['totals'][4] . ')');
ok(count(array_filter($d['rows'], fn ($r) => $r[1] === 'First response')) >= 2 && count(array_filter($d['rows'], fn ($r) => $r[1] === 'Resolution')) >= 2, 'rows per queue × metric');
echo "4. Reopen rate and CSAT\n";
[$c, $d] = report_json('reopen_rate', $P);
$exp = q("SELECT count(*) AS solved, count(*) FILTER (WHERE reopen_count > 0) AS reopened FROM tickets WHERE merged_into_id IS NULL AND solved_at >= :s AND solved_at < :e", ['s' => $start, 'e' => $end])[0];
ok($c === 200 && cell_int($d['totals'][1]) === (int) $exp['solved'] && cell_int($d['totals'][2]) === (int) $exp['reopened'] && (int) $exp['reopened'] >= 1, 'reopen rate: solved and reopened = direct SQL (' . $d['totals'][1] . ' · ' . $d['totals'][2] . ')');
$it = null; foreach ($d['rows'] as $r) { if ($r[0] === 'SMOKE IT help') { $it = $r; } }
ok($it !== null && $it[3] !== '—' && abs((float) $it[4] - 2.0) < 0.1, "IT help's % reopened shows and the median days to reopen is 2.0 (the fixture's it3)");
[$c, $d] = report_json('csat', $P);
$exp = q("SELECT count(*) AS sent, count(responded_at) AS answered, count(*) FILTER (WHERE responded_at IS NOT NULL AND score >= 5) AS good, count(*) FILTER (WHERE responded_at IS NOT NULL AND score < 5) AS bad FROM satisfaction_ratings WHERE survey_sent_at >= :s AND survey_sent_at < :e", ['s' => $start, 'e' => $end])[0];
ok($c === 200 && cell_int($d['totals'][1]) === (int) $exp['sent'] && cell_int($d['totals'][2]) === (int) $exp['answered'] && cell_int($d['totals'][4]) === (int) $exp['good'] && cell_int($d['totals'][5]) === (int) $exp['bad'], 'CSAT totals: sent, answered, good, bad = direct SQL (' . implode(' · ', [$d['totals'][1], $d['totals'][2], $d['totals'][4], $d['totals'][5]]) . ')');
$it = null; foreach ($d['rows'] as $r) { if ($r[0] === 'SMOKE IT help') { $it = $r; } }
ok($it !== null && $it[8] === 'slow (1)' && $it[7] === '—', 'the bad reasons are counted; the mean is — on the good/bad scale');
echo "5. Workload and aging\n";
[$c, $d] = report_json('workload', $P);
$marco = null; foreach ($d['rows'] as $r) { if ($r[0] === 'SMOKE Marco') { $marco = $r; } }
$exp = q("SELECT count(*) FILTER (WHERE s.category IN " . OPEN_SET . ") AS open_now, count(*) FILTER (WHERE t.solved_at >= :s AND t.solved_at < :e) AS solved FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.assignee_member_id = 27 AND t.merged_into_id IS NULL", ['s' => $start, 'e' => $end])[0];
$mins = (int) one("SELECT COALESCE(sum(minutes), 0) FROM time_entries WHERE member_id = 27 AND worked_on >= CAST(:f AS date) AND worked_on <= CAST(:t AS date)", ['f' => $P['from'], 't' => $P['to']]);
ok($c === 200 && $marco !== null && $marco[1] === 'Person' && cell_int($marco[2]) === (int) $exp['open_now'] && cell_int($marco[3]) === (int) $exp['solved'] && cell_int($marco[5]) === $mins, "Marco's workload row: open now, solved, minutes logged = direct SQL (" . $marco[2] . ' · ' . $marco[3] . ' · ' . $marco[5] . ')');
$agent = null; foreach ($d['rows'] as $r) { if ($r[1] === 'Agent') { $agent = $r; } }
ok($agent !== null && $agent[0] === 'SMOKE Frontline', 'the agent assignee appears as an Agent');
[$c, $d] = report_json('aging', ['group_by' => 'queue']);
$exp = (int) one("SELECT count(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.merged_into_id IS NULL AND s.category IN " . OPEN_SET);
ok($c === 200 && cell_int($d['totals'][6]) === $exp, 'aging: the open total = direct SQL (' . $d['totals'][6] . ')');
$older = (int) one("SELECT count(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.merged_into_id IS NULL AND s.category IN " . OPEN_SET . " AND t.created_at >= now() - interval '7 days' AND t.created_at < now() - interval '3 days'");
ok(array_sum(array_map(fn ($r) => cell_int($r[6]), array_filter($d['rows'], fn ($r) => $r[1] === '4–7 d'))) === $older, 'the 4–7 d bucket = direct SQL (' . $older . ')');
ok(count($d['rows']) === 5 * count(q('SELECT 1 FROM queues WHERE archived_at IS NULL')), 'five buckets for every queue, empty ones included');
echo "6. The dashboard\n";
[$c, $d] = screen($lena, '/reports/');
$exp = q("SELECT count(*) AS open, count(*) FILTER (WHERE t.assignee_member_id IS NULL) AS unassigned, count(*) FILTER (WHERE t.last_activity_at < now() - interval '7 days') AS stale,
                 count(*) FILTER (WHERE s.category = 'new') AS new, count(*) FILTER (WHERE t.last_requester_reply_at IS NOT NULL AND (t.last_agent_reply_at IS NULL OR t.last_requester_reply_at > t.last_agent_reply_at)) AS needs_reply
            FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.merged_into_id IS NULL AND s.category IN " . OPEN_SET)[0];
ok($c === 200 && $d['counts']['open'] === (int) $exp['open'] && $d['counts']['unassigned'] === (int) $exp['unassigned'] && $d['counts']['stale'] === (int) $exp['stale'] && $d['counts']['by_category']['new'] === (int) $exp['new'] && $d['counts']['needs_reply'] === (int) $exp['needs_reply'], 'the dashboard counts = direct SQL (open ' . $d['counts']['open'] . ', unassigned ' . $d['counts']['unassigned'] . ', stale ' . $d['counts']['stale'] . ', new ' . $d['counts']['by_category']['new'] . ', needs reply ' . $d['counts']['needs_reply'] . ')');
$sla = q("SELECT sla_state, count(*) AS n FROM mcp_tickets WHERE status_category IN " . OPEN_SET . " AND merged_into_id IS NULL GROUP BY 1");
pdo()->exec("SELECT set_config('app.member_id', '28', false)");
$sla = []; foreach (q("SELECT sla_state, count(*) AS n FROM mcp_tickets WHERE status_category IN " . OPEN_SET . " AND merged_into_id IS NULL GROUP BY 1") as $r) { $sla[$r['sla_state'] ?? 'none'] = (int) $r['n']; }
ok($d['counts']['at_risk'] === ($sla['at_risk'] ?? 0) && $d['counts']['breached'] === ($sla['breached'] ?? 0) && $d['counts']['breached'] >= 2 && $d['counts']['at_risk'] >= 1, 'at risk ' . $d['counts']['at_risk'] . ' and breached ' . $d['counts']['breached'] . ' = the view as Lena');
$w = array_column($d['workload'], null, 'member_id');
ok(($w[27]['open_now'] ?? 0) === 3 && $w[27]['capacity'] === 2 && $w[27]['over_capacity'] === true && $w[27]['availability'] === 'online', 'Marco: 3 open over a capacity of 2, online');
ok(($w[40]['kind'] ?? '') === 'agent' && $w[40]['capacity'] === null && $w[40]['availability'] === null && $w[40]['over_capacity'] === false, 'the agent: kind agent, no capacity, no availability (no presence row)');
ok(($w[28]['availability'] ?? '') === 'away' && isset($w[29]), 'Lena away; Colin listed from the Facilities queue');
exit($GLOBALS['fails'] === 0 ? 0 : 1);
