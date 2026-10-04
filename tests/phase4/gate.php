<?php
/**
 * Proof — the door. Who gets in and what they are offered: no token, a forged, an expired one -> 401; a person's own token, and the command bar's action token -> every tool;
 * an agent's RUN token -> exactly the tools the kernel's run-facts call grants on THIS endpoint (fail closed: facts that say invalid, facts for another application, a kernel
 * that cannot be reached, an endpoint the agent was not granted); an agent the directory has not admitted is admitted at first contact only when the kernel vouches for it; an
 * evaluation run may read; the kernel's own token reaches app_roles only. Servers: records :8295, activity :8296, records-with-a-dead-kernel :8297.
 */
require __DIR__ . '/lib.php';
dated();
$run = fn () => random_int(700000, 799999);      // a fresh run id every time: the servers cache a run's facts
const RECORDS_36 = ['activity_search'];           // (placeholder so the list below reads as the surface)

echo "1. Tokens that do not get in\n";
foreach ([REC, ACT] as $port) {
    $n = $port === REC ? 'records' : 'activity';
    ok(mcp_tools($port, '') === null, "$n: no token -> 401");
    ok(mcp_tools($port, 'mcp_' . str_repeat('0', 48)) === null, "$n: a made-up mcp_ token -> 401");
    $t = rtoken(951, $run());
    ok(mcp_tools($port, substr($t, 0, -1) . (substr($t, -1) === '0' ? '1' : '0')) === null, "$n: a run token with a bad signature -> 401");
    ok(mcp_tools($port, rtoken(951, $run(), -5)) === null, "$n: an expired run token -> 401");
    ok(mcp_tools($port, atoken(30, -5)) === null, "$n: an expired action token -> 401");
    ok(mcp_tools($port, atoken(9999)) === null, "$n: an action token for a member the mirror does not know -> 401 (never created)");
}
ok(one('SELECT count(*) FROM members WHERE id = 9999') == 0, 'and the unknown member was not created');

echo "2. People\n";
$rec = mcp_tools(REC, ptoken(27));
ok(is_array($rec) && count($rec) === 36, 'Marco (support) is offered the 36 records tools: ' . count($rec ?? []));
$want = ['activity_search', 'actor_timeline', 'config_change_log', 'queue_activity', 'ticket_history', 'who_touched'];
$act = mcp_tools(ACT, ptoken(27));
ok($act === $want, 'and the 6 activity tools on the activity server');
ok(mcp_tools(REC, ptoken(26)) === $rec && mcp_tools(REC, atoken(26)) === $rec && mcp_tools(REC, atoken(1)) === $rec, 'a requester (own token), the command bar\'s action token and the owner get the same 36 (row scoping does the rest)');
$r = mcp_tool(REC, atoken(26), 'find_tickets', ['requester_id' => requester_of_member(26)]);
ok(!$r['error'] && isset($r['data']['tickets']), 'the action token acts as its member: find_tickets runs');
$me = mcp_tool(REC, atoken(27), 'my_queue', []);
ok(!$me['error'] && ($me['data']['member_id'] ?? 0) === 27, "the action token acts as its member: my_queue is Marco's");
ok(count(array_filter($rec, fn ($n) => in_array($n, ['find_tickets', 'get_ticket', 'my_queue', 'records_search', 'app_roles', 'find_members', 'metrics_summary']))) === 7, 'the key tools are among them');

echo "3. An agent sees exactly what the kernel granted on this endpoint\n";
make_agent(951, 'SMOKE Triage P4', ['support', 'user']);
$r1 = $run();
facts($r1, 951, ['Records MCP' => ['my_queue' => [], 'get_ticket' => [], 'find_tickets' => []], 'Activity MCP' => ['ticket_history' => []]]);
$tok = rtoken(951, $r1);
ok(mcp_tools(REC, $tok) === ['find_tickets', 'get_ticket', 'my_queue'], 'records: exactly the three granted (' . implode(',', mcp_tools(REC, $tok) ?? []) . ')');
ok(mcp_tools(ACT, $tok) === ['ticket_history'], 'activity: exactly ticket_history — a grant on one endpoint is not a grant on the other');
$c = mcp_tool(REC, $tok, 'my_queue', []);
ok(!$c['error'] && ($c['data']['member_id'] ?? 0) === 951, 'a granted tool answers (as the agent)');
$c = mcp_tool(REC, $tok, 'find_members', ['q' => 'Lena']);
ok($c['error'] && str_contains($c['text'], 'not among the tools'), 'a tool it was not granted is refused: ' . substr($c['text'], 0, 90));
ok(mcp_tool(REC, $tok, 'records_search', ['sql' => 'select 1'])['error'], 'records_search is a tool like any other: refused unless granted');
ok(mcp_tool(ACT, $tok, 'activity_search', ['sql' => 'select 1'])['error'], 'and activity_search');

echo "4. Fail closed\n";
$r = $run(); facts($r, 951, ['Records MCP' => ['my_queue' => []]], ['valid' => false]);
$t = rtoken(951, $r);
ok(mcp_tools(REC, $t) === [], 'the kernel says the token is not valid: an admitted agent is offered NOTHING');
ok(mcp_tool(REC, $t, 'my_queue', [])['error'], 'and cannot call anything');
$r = $run(); facts($r, 951, []);
ok(mcp_tools(REC, rtoken(951, $r)) === [], "facts with no endpoint for this application (another application's agent): nothing offered");
ok(mcp_tools(REC, rtoken(951, $run())) === [], 'a run the kernel does not know (valid:false by default): nothing offered');
$r = $run(); facts($r, 951, ['Records MCP' => ['my_queue' => []]], ['is_agent' => false]);
ok(count(mcp_tools(REC, rtoken(951, $r)) ?? []) === 36, 'facts that say is_agent false are a person: never filtered');
$r = $run(); facts($r, 951, ['Records MCP' => ['my_queue' => []]]);
$t = rtoken(951, $r);
ok(mcp_tools(REC_DEAD, $t) === [], 'the kernel cannot be reached (:8297, dead URL): an admitted agent is offered nothing');
ok(mcp_tool(REC_DEAD, $t, 'my_queue', [])['error'], 'and cannot call');
ok(count(mcp_tools(REC_DEAD, ptoken(27)) ?? []) === 36 && count(mcp_tools(REC_DEAD, atoken(27)) ?? []) === 36, "while a person's own token and the command bar's action token still work with no kernel");

echo "5. An agent the directory has not admitted yet\n";
make_agent(953, 'SMOKE Unadmitted P4', ['support', 'user'], null);
ok(one('SELECT capability FROM members WHERE id = 953') === null, 'agent 953 is in the mirror with no capability');
$r = $run(); $t = rtoken(953, $r);
ok(mcp_tools(REC, $t) === null, 'a run the kernel does not vouch for: 401, and it is not admitted');
ok(one('SELECT capability FROM members WHERE id = 953') === null, '...capability still null');
facts($r, 953, ['Records MCP' => ['my_queue' => [], 'find_tickets' => []]]);
ok(mcp_tools(REC, $t) === ['find_tickets', 'my_queue'], 'the kernel vouches for it with endpoints here: admitted at first contact, offered its two tools');
ok(one('SELECT capability FROM members WHERE id = 953') === 'write', '...and its capability is now write');
make_agent(956, 'SMOKE Unadmitted P4 B', ['support', 'user'], null);
$r = $run(); facts($r, 956, []);
ok(mcp_tools(REC, rtoken(956, $r)) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'facts with NO endpoint here do not admit: 401, capability still null');
$r = $run(); facts($r, 956, ['Records MCP' => ['my_queue' => []]], ['valid' => false]);
ok(mcp_tools(REC, rtoken(956, $r)) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'facts valid:false do not admit');
$r = $run(); $t = rtoken(956, $r); facts($r, 956, ['Records MCP' => ['my_queue' => []]]);
ok(mcp_tools(REC_DEAD, $t) === null && one('SELECT capability FROM members WHERE id = 956') === null, 'a dead kernel does not admit either (401)');
$r = $run(); facts($r, 957, ['Records MCP' => ['my_queue' => []]]);
ok(mcp_tools(REC, rtoken(957, $r)) === null && one('SELECT count(*) FROM members WHERE id = 957') == 0, 'a run token for a member the mirror does not know -> 401 even when the kernel vouches; 957 is never created');
q("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (958, 'human', 'SMOKE Human-like P4', 'user', 'active', NULL, '{user}') ON CONFLICT (id) DO NOTHING");
$r = $run(); facts($r, 958, ['Records MCP' => ['my_queue' => []]]);
ok(mcp_tools(REC, rtoken(958, $r)) === null && one('SELECT capability FROM members WHERE id = 958') === null, 'a HUMAN is never admitted this way (their capability comes with the hand-off claims)');

echo "6. An evaluation may read\n";
$r = $run(); facts($r, 951, ['Records MCP' => ['find_tickets' => [], 'my_queue' => []]], ['trigger' => 'eval']);
$t = rtoken(951, $r);
ok(mcp_tools(REC, $t) === ['find_tickets', 'my_queue'] && !mcp_tool(REC, $t, 'my_queue', [])['error'], 'an eval run reads (the tools never write: nothing an eval could change)');

echo "7. The kernel's own token\n";
$k = ktoken();
ok(mcp_tools(REC, $k) === ['app_roles'], 'records: app_roles, nothing else (Help Desk shares nothing in version 1)');
foreach (['find_tickets' => [], 'records_search' => ['sql' => 'select 1'], 'my_queue' => [], 'get_vocabulary' => []] as $tn => $args) {
    $c = mcp_tool(REC, $k, $tn, $args);
    ok($c['error'] && str_contains($c['text'], 'reaches'), "the kernel's token calling $tn: refused");
}
$c = mcp_tool(REC, $k, 'app_roles', []);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', "the kernel's token calling app_roles: answers os.app-roles/1");
ok(count($c['data']['roles'] ?? []) === 5 && count($c['data']['rights'] ?? []) === 14, 'five roles and fourteen rights');
ok(mcp_tools(REC, ktoken('hr')) === null, 'a kernel token for another application (hr) -> 401');
ok(mcp_tools(REC, ktoken('helpdesk', -5)) === null, 'an expired kernel token -> 401');
$bad = ktoken(); ok(mcp_tools(REC, substr($bad, 0, -1) . (substr($bad, -1) === '0' ? '1' : '0')) === null, 'a kernel token with a bad signature -> 401');
ok(mcp_tools(REC_DEAD, ktoken()) === ['app_roles'], "the kernel's token needs no kernel round-trip (:8297)");
$c = mcp_tool(REC, ptoken(26), 'app_roles', []);
ok(!$c['error'] && ($c['data']['schema'] ?? '') === 'os.app-roles/1', 'any person may call app_roles (the catalogue is about no one)');
$r = $run(); facts($r, 951, ['Records MCP' => ['app_roles' => []]]);
ok(!mcp_tool(REC, rtoken(951, $r), 'app_roles', [])['error'], 'and an agent granted it');
ok(mcp_tools(ACT, $k) === [], "the activity server offers the kernel's token nothing");
finish();
