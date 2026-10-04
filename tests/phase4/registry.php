<?php
/**
 * Proof — the application's registration is whole and consistent: the action registry regenerates from the manifest (96 actions, 40 pausing, 65 screens built) with the approval
 * kinds maludb-os.json declares; every log event an approval policy will match is one the handlers really write; the kernel-side wrapper's resolve block names real tools that
 * answer a plain LIST when given `q` alone; the three agents' grants name real tools, and none can do what it should not; the endpoints, services, skills and job descriptions the
 * manifest lists exist; the deploy units start the two servers; the installer's read-only plan sees no stop.
 */
require __DIR__ . '/lib.php';
$F = dated(); $W = world();
$m = json_decode(file_get_contents('maludb-os.json'), true);
$reg = json_decode(file_get_contents('mcp/action_registry.json'), true);

echo "1. The registry regenerates\n";
$tmp = sys_get_temp_dir() . '/hd-reg-' . getmypid();
@mkdir("$tmp/bin", 0777, true); @mkdir("$tmp/mcp", 0777, true);
copy('bin/build_action_registry.php', "$tmp/bin/build_action_registry.php");
symlink(realpath('docs'), "$tmp/docs"); symlink(realpath('html'), "$tmp/html");
shell_exec('php ' . escapeshellarg("$tmp/bin/build_action_registry.php") . ' 2>&1');
$new = json_decode((string) @file_get_contents("$tmp/mcp/action_registry.json"), true);
ok(is_array($new) && count($new['actions']) === 96, 'built from docs/helpdesk-action-manifest.md into a scratch copy: ' . count($new['actions'] ?? []) . ' actions');
$strip = function (array $r): array { unset($r['generated_at']); return $r; };
ok($strip($new) == $strip($reg), 'the committed mcp/action_registry.json is exactly what the builder writes today (bar its timestamp)');
exec('rm -rf ' . escapeshellarg($tmp));
ok(str_contains((string) shell_exec('php bin/build_action_registry.php --check 2>&1; echo "exit=$?"'), 'exit=0'), 'bin/build_action_registry.php --check: current');
ok(count(array_filter($reg['actions'], fn ($a) => $a['built'])) === 96 && count($reg['screens']) === 65 && count(array_filter($reg['screens'], fn ($s) => $s['built'])) === 65, '96 of 96 actions and 65 of 65 screens built');
$pausing = array_filter($reg['actions'], fn ($a) => $a['approval']);
ok(count($pausing) === 40, '40 actions carry an approval category for an agent: ' . count($pausing));
$kinds = array_count_values(array_column($pausing, 'approval'));
ok(($kinds['external_send'] ?? 0) === 4 && ($kinds['deletion'] ?? 0) === 7 && $kinds['other'] === 29, 'four external_send (ticket_reply, canned_apply, article_send, survey_send), seven deletion, twenty-nine other: ' . json_encode($kinds));
$cat = array_column($m['approvals'], 'category', 'action');
ok(count($m['approvals']) === 40 && array_map(fn ($a) => $a['approval'], $pausing) == $cat, "maludb-os.json approvals[] (40 entries) equal the registry's categories, action for action");
foreach (['ticket_reply' => 'external_send', 'canned_apply' => 'external_send', 'article_send' => 'external_send', 'survey_send' => 'external_send', 'ticket_delete' => 'deletion', 'ticket_spam' => 'deletion', 'article_delete' => 'deletion', 'queue_create' => 'other', 'ticket_merge' => 'other', 'ticket_close' => 'other', 'settings_save' => 'other'] as $a => $k) {
    ok(($reg['actions'][$a]['approval'] ?? '') === $k, "$a pauses as $k");
}
foreach (['ticket_create', 'ticket_note', 'ticket_assign', 'ticket_transition', 'ticket_set_fields', 'ticket_add_tags', 'ticket_link', 'ticket_follow', 'article_suggest', 'ticket_time_log'] as $a) {
    if (isset($reg['actions'][$a])) { ok(!$reg['actions'][$a]['approval'], "$a never pauses: an agent files, notes, routes and solves freely"); }
}
$src = '';
foreach (['html', 'app'] as $dir) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) { if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) { $src .= file_get_contents($f->getPathname()); } } }
$missing = [];
foreach ($pausing as $k => $a) { if (!str_contains($src, "'" . $a['log_event'] . "'")) { $missing[] = "$k:{$a['log_event']}"; } }
ok($missing === [], 'every pausing action\'s log event is one the application writes' . ($missing ? ' — NOT: ' . implode(', ', $missing) : ''));

echo "2. The kernel-side wrapper's resolve block\n";
$w = json_decode(file_get_contents('deploy/kernel-registry-helpdesk.json'), true);
$recTools = mcp_tools(REC, ptoken(28));
$params = [];
foreach ($reg['actions'] as $a) { foreach ($a['params'] as $p) { if ($p['name'] !== null) { $params[$p['name']] = ($params[$p['name']] ?? false) || $p['repeated']; } } }
ok(count($w['resolve']) === 14, 'fourteen entities resolve: ' . implode(', ', array_keys($w['resolve'])));
$bad = [];
foreach ($w['resolve'] as $ent => $spec) {
    if (!in_array($spec['tool'], $recTools, true) || $spec['query_param'] !== 'q') { $bad[] = "$ent tool"; }
    foreach ($spec['params'] as $p) { if (!isset($params[$p]) || $params[$p]) { $bad[] = "$ent:$p"; } }
}
ok($bad === [], 'every entity names a tool of the records server and real, non-repeated action params' . ($bad ? ' — NOT: ' . implode(', ', $bad) : ''));
$samples = ['ticket' => 'dated it5', 'queue' => 'IT help', 'member' => 'Marco', 'requester' => 'Jo', 'organization' => 'Customer Co', 'status' => 'Solved', 'category' => 'SMOKE', 'type' => 'incident', 'canned' => 'reset', 'article' => 'guide', 'view' => 'triage', 'policy' => 'Standard', 'rule' => 'reopen', 'mailbox' => 'regsupport'];
q("INSERT INTO canned_responses (title, body_text, scope) VALUES ('SMOKE p4 reg reset', 'x', 'global') ON CONFLICT DO NOTHING");
q("INSERT INTO mailboxes (address, display_name, queue_id) VALUES ('regsupport@example.invalid', 'SMOKE reg box', :q) ON CONFLICT DO NOTHING", ['q' => $F['it']]);
act(as_member(1), '/kb/admin/save.php', ['title' => 'SMOKE p4 reg guide', 'body' => 'x', 'visibility' => 'public', 'status' => 'published']);
q("INSERT INTO categories (name, requester_visible) VALUES ('SMOKE p4 reg category', true)");
foreach ($w['resolve'] as $ent => $spec) {
    $r = mcp_tool(REC, ptoken(1), $spec['tool'], ['q' => $samples[$ent]]);
    $rows = is_array($r['data']) && array_is_list($r['data']) ? $r['data'] : null;
    $withId = $rows === null ? [] : array_values(array_filter($rows, fn ($x) => isset($x[$spec['id_field']])));
    ok($rows !== null && count($withId) >= 1 && isset($withId[0][$spec['label_field']]), "$ent: {$spec['tool']}(q='{$samples[$ent]}') answers a plain list whose rows carry {$spec['id_field']} and {$spec['label_field']} (" . count($withId) . ')');
}
ok(count(mcp_tool(REC, ptoken(1), 'find_tickets', ['q' => 'zzzznothing'])['data'] ?? [1]) === 0, 'a name nobody has resolves to an empty list ("No ticket matching…" from the kernel)');

echo "3. The three agents\n";
$actionNames = array_keys($reg['actions']);
$actTools = mcp_tools(ACT, ptoken(28));
$agents = array_column($m['agents'], null, 'key');
ok(array_keys($agents) === ['expert', 'triage', 'frontline'] && !array_filter($agents, fn ($a) => !$a['hired_on_install'] || $a['access_capability'] !== 'write'), 'expert, triage and frontline: hired on install at write capability');
$unknown = [];
foreach ($agents as $key => $a) {
    foreach ($a['tool_grants'] as $endpoint => $tools) {
        $known = $endpoint === 'Records MCP' ? $recTools : ($endpoint === 'Activity MCP' ? $actTools : $actionNames);
        foreach ($tools as $t) { if (!in_array($t, $known, true)) { $unknown[] = "$key:$endpoint:$t"; } }
    }
}
ok($unknown === [], 'every granted tool is a real tool of its endpoint' . ($unknown ? ' — NOT: ' . implode(', ', $unknown) : ''));
$grants = fn (string $k) => array_merge(...array_values($agents[$k]['tool_grants']));
ok(!array_intersect(['ticket_delete', 'ticket_spam', 'ticket_merge', 'queue_create', 'settings_save', 'rule_create', 'article_delete', 'tickets_bulk_update'], $grants('triage'), $grants('frontline'), $grants('expert')), 'no agent holds a destructive or configuration action');
ok(!in_array('ticket_reply', $grants('triage')) && !in_array('canned_apply', $grants('triage')) && !in_array('ticket_create', $grants('frontline')), 'triage cannot write to a requester (no reply, no canned send); frontline files no new ticket');
ok(in_array('ticket_reply', $grants('frontline')) && in_array('my_queue', $grants('frontline')) && in_array('triage_queue', $grants('triage')), 'frontline answers from my_queue; triage starts from triage_queue');
ok(!array_filter(['frontline', 'triage'], fn ($k) => in_array('config_change_log', $grants($k)) || in_array('find_mailboxes', $grants($k)) || in_array('agent_dispatches', $grants($k))), 'neither working agent reads configuration, mail failures or dispatches');
ok($agents['triage']['duty']['schedule_cron'] === '*/5 * * * *' && $agents['frontline']['duty']['schedule_cron'] === '*/10 * * * *' && !isset($agents['expert']['duty']), 'duties: triage every five minutes, frontline every ten, the expert answers when asked');
$gone = [];
foreach ($m['agents'] as $a) { foreach (array_merge([$a['job_description']], $a['skills']) as $f) { if (!is_file($f . (str_ends_with($f, '.md') ? '' : '/SKILL.md')) && !is_file($f) && !is_dir($f)) { $gone[] = $f; } } }
ok($gone === [], 'every job description and skill an agent names exists' . ($gone ? ' — NOT: ' . implode(', ', $gone) : ''));
$gone = array_filter($m['skills'], fn ($s) => !is_dir($s));
ok($gone === [], 'every skill the manifest lists exists');

echo "4. Endpoints, services, the vhost\n";
$ep = array_column($m['endpoints'], null, 'name');
ok(isset($ep['Records MCP'], $ep['Activity MCP']) && $ep['Records MCP']['port_env'] === 'MCP_RECORDS_PORT' && $ep['Activity MCP']['path'] === '/mcp/activity' && $ep['Records MCP']['agent_reachable'] === true, 'the manifest lists both MCP endpoints with their port env and path');
$missing = array_filter($m['services'], fn ($s) => !is_file($s));
ok($missing === [], 'every unit the manifest lists exists (' . count($m['services']) . ')');
$unit = (string) file_get_contents('deploy/helpdesk-records-mcp.service') . (string) file_get_contents('deploy/helpdesk-activity-mcp.service');
ok(str_contains($unit, 'records_server.py') && str_contains($unit, 'activity_server.py') && str_contains($unit, 'mcp/venv/bin/python') && str_contains($unit, 'User=www-data'), 'the two units run the servers from mcp/venv as www-data');
$conf = (string) file_get_contents('deploy/apache-helpdesk.conf');
ok(str_contains($conf, 'ProxyPass        /mcp/records  http://127.0.0.1:{{MCP_RECORDS_PORT}}/mcp') && str_contains($conf, 'ProxyPass        /mcp/activity http://127.0.0.1:{{MCP_ACTIVITY_PORT}}/mcp'), "the public vhost proxies both servers under the application's name");
$envReq = $m['env']['required'];
ok(in_array('MCP_RECORDS_PORT', $envReq) && in_array('MCP_ACTIVITY_PORT', $envReq) && in_array('OS_APPLICATION_TOKEN', $envReq) && in_array('ACTION_TOKEN_KEY', $envReq) && in_array('ACTIONS_RELAY_KEY', $envReq), 'the env the servers need is declared required (ports, run-facts token, signing keys)');
$plan = (string) shell_exec('env -i PATH="$PATH" HOME="$HOME" php /var/www/bin/app_install.php plan /srv/apps/helpdesk --domain subello.com 2>&1');   // a clean environment: the kernel's own, not the scratch one
ok(!preg_match('/^stop\b/mi', $plan) && preg_match('/^Plan: \d+ steps to do/m', $plan) === 1, "the installer's read-only plan sees no stop");
finish();
