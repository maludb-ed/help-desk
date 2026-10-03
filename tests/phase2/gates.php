<?php
/**
 * Proof: rights per role on the shell's screens and placeholders (403 vs 200), the menu, CSRF, the settings and tokens screens,
 * notifications, the command bar through the kernel's chat endpoint, the activity trail's visibility, and the action-token /
 * run-token gate (sso-shell.md "The shell", "Handlers", "Proof before Phase 3"). Run after sso.php through tests/phase2/run.sh.
 */
require __DIR__ . '/lib.php';
$L = 'https://app.example.invalid/launcher?app=helpdesk';
$json = ['headers' => ['Accept: application/json']];

echo "1. Rights per role — every screen and placeholder answers 200 or 403\n";
// route => the roles that get 200; the roles are user (Priya 26), collaborator (Colin 29), support (Marco 27), lead (Lena 28), admin (the owner 1).
$all = 'user collaborator support lead admin';
$matrix = [
    '/' => $all, '/my-tickets' => $all, '/tickets/new' => $all, '/kb/' => $all, '/settings/' => $all, '/settings/tokens/' => $all, '/notifications' => $all, '/activity' => $all,
    '/inbox' => 'collaborator support lead admin', '/board' => 'collaborator support lead admin', '/requesters/' => 'collaborator support lead admin', '/organizations/' => 'collaborator support lead admin',
    '/canned/' => 'support lead admin', '/reports/' => 'lead admin', '/settings/queues/' => 'lead admin',
    '/settings/statuses/' => 'admin', '/settings/vocabulary/' => 'admin', '/settings/fields/' => 'admin', '/settings/sla/' => 'admin', '/settings/automation/' => 'collaborator support lead admin',
    '/settings/mailboxes/' => 'admin', '/settings/csat' => 'admin', '/settings/agents' => 'admin',
];
[$jUser, ] = sign_on(26);
[$jCollab, ] = sign_on(29);
[$jSupport, ] = sign_on(27);
[$jLead, ] = sign_on(28);
[$jAdmin, ] = sign_on(1);
$who = ['user' => $jUser, 'collaborator' => $jCollab, 'support' => $jSupport, 'lead' => $jLead, 'admin' => $jAdmin];
$wrong = [];
$n = 0;
foreach ($matrix as $route => $allowed) {
    foreach ($who as $role => $jarf) {
        $want = in_array($role, explode(' ', $allowed), true) ? 200 : 403;
        $code = page($jarf, $route)['code'];
        $n++;
        if ($code !== $want) { $wrong[] = "$role $route wanted $want got $code"; }
    }
}
ok($wrong === [], "$n role × screen checks (" . count($matrix) . ' screens × 5 roles) answer 200 or 403 as the rights say' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 6)) : ''));
$r = page($jUser, '/inbox');
ok(str_contains($r['body'], 'You may not see this queue&#039;s tickets.') || str_contains($r['body'], "You may not see this queue's tickets."), 'a refusal says what the person may not do, in words ("You may not see this queue\'s tickets.")');
$r = page($jLead, '/reports/');                                                           // the knowledge base is slice 9's; reports are the one placeholder left until slice 10
ok($r['code'] === 200 && str_contains($r['body'], 'id="reports-coming"') && str_contains($r['body'], 'slice 10'), 'a placeholder names the slice that builds it (Reports → slice 10)');
ok((json_decode(page($jLead, '/reports/', $json)['body'], true)['error']['code'] ?? '') === 'not_built', 'and answers JSON callers not_built (501)');
$menu = fn (string $j): array => (preg_match_all('/id="nav-([a-z-]+)"/', page($j, '/')['body'], $m) ? $m[1] : []);
$groups = fn (string $j): array => (preg_match_all('/nxl-caption"><label>([^<]+)</', page($j, '/')['body'], $m) ? $m[1] : []);
ok($groups($jUser) === ['Help', 'Settings', 'Me'] && !in_array('inbox', $menu($jUser), true) && in_array('my-settings', $menu($jUser), true), 'a requester-only person sees Help, My settings (and Me): ' . implode(', ', $groups($jUser)));
ok($groups($jSupport) === ['Help', 'Work', 'Settings', 'Me'] && in_array('canned', $menu($jSupport), true) && !in_array('statuses', $menu($jSupport), true) && !in_array('reports', $menu($jSupport), true), 'support staff see Work (Canned responses) but no Reports and no admin settings');
ok(in_array('reports', $menu($jLead), true) && in_array('queues', $menu($jLead), true) && !in_array('statuses', $menu($jLead), true), 'a lead sees Reports and Queues, not Statuses');
ok(in_array('statuses', $menu($jAdmin), true) && in_array('agents', $menu($jAdmin), true), 'an admin sees every settings item');
ok(!in_array('canned', $menu($jCollab), true) && in_array('inbox', $menu($jCollab), true), 'a collaborator sees the Inbox but not Canned responses');
$r = page(jar(), '/inbox');
ok($r['code'] === 302 && $r['location'] === $L, 'no session: a screen goes to the launcher with ?app=helpdesk');
$r = req('GET', '/inbox', $json);
ok($r['code'] === 401 && (json_decode($r['body'], true)['error']['code'] ?? '') === 'unauthorized', 'no session, JSON: 401 unauthorized');
$r = req('GET', '/', ['jar' => $jSupport] + $json);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && $d['sees_queues'] === true && $d['can_create'] === true && array_key_exists('needs_me', $d) && array_key_exists('my_tickets', $d), 'the dashboard answers JSON: the shape (needs_me, my_tickets, can_create, sees_queues)');
$h = page($jUser, '/')['body'];
ok(!str_contains($h, 'id="home-needs-me"') && str_contains($h, 'id="home-my-tickets-empty"') && str_contains($h, 'id="dashboard-new-btn"'), 'a requester\'s home: no "Needs me", the empty "My tickets", the New request button');
ok(str_contains(page($jSupport, '/')['body'], 'id="home-needs-me-empty"'), 'support staff\'s home shows "Needs me" (empty until slice 3)');
$ext = sign_on(31)[0];
ok(page($ext, '/')['code'] === 200 && $groups($ext) === ['Help', 'Settings', 'Me'], 'an external member with the user role gets the same requester shell');

echo "2. My settings — how I am told\n";
$t = csrf_of(page($jUser, '/')['body']);
$d = json_decode(page($jUser, '/settings/', $json)['body'], true)['data'];
ok($d['notify']['email_enabled'] === true && $d['notify']['text_enabled'] === false && $d['notify']['saved'] === false && in_array('assigned', $d['notify']['kinds'], true), 'the defaults before any save: email on, text off, the default kinds');
$r = req('POST', '/settings/prefs.php', ['jar' => $jUser, 'form' => ['email_enabled' => 'yes', 'text_enabled' => 'yes', 'kinds' => ['assigned', 'replied'], 'text_kinds' => ['assigned'], 'digest' => 'yes']]);
ok($r['code'] === 403, 'saving without the CSRF token: 403');
$since = last_activity_id();
$r = req('POST', '/settings/prefs.php', ['jar' => $jUser, 'form' => ['email_enabled' => 'yes', 'text_enabled' => 'yes', 'kinds' => ['assigned', 'replied'], 'text_kinds' => ['assigned'], 'digest' => 'yes', 'csrf_token' => $t, 'return_to' => '/settings/?tab=notify']]);
ok($r['code'] === 302 && str_starts_with($r['location'], '/settings/?tab=notify') && str_contains($r['location'], 'notice=prefs_saved'), 'with it: 302 back to the settings with the notice');
$row = q('SELECT email_enabled, text_enabled, kinds, text_kinds, digest FROM notification_prefs WHERE member_id = 26')[0] ?? null;
ok($row && $row['text_enabled'] && $row['kinds'] === '{assigned,replied}' && $row['text_kinds'] === '{assigned}' && $row['digest'], 'the row holds the choices (text on, two kinds, one text kind, digest)');
$log = activity('prefs.save', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'text_enabled') && str_contains((string) $log[0]['before'], 'text_enabled'), 'prefs.save logs before and after of the changed keys');
$r = req('POST', '/settings/prefs.php', ['jar' => $jUser, 'form' => ['kinds' => ['assigned', 'nonsense'], 'csrf_token' => $t]]);
ok($r['code'] === 422 && str_contains($r['body'], 'Unknown event: nonsense'), 'an unknown event kind: 422 in words');
$r = req('POST', '/settings/prefs.php', ['jar' => $jUser, 'form' => ['digest' => 'maybe', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a yes/no field that is neither: 422');
$r = req('POST', '/settings/prefs.php', ['jar' => $jUser, 'form' => ['text_enabled' => 'no', 'csrf_token' => $t]]);
ok($r['code'] === 302 && q('SELECT text_enabled, kinds FROM notification_prefs WHERE member_id = 26')[0] === ['text_enabled' => false, 'kinds' => '{assigned,replied}'], 'a field left out stays as it was (text off, the kinds kept)');
ok(str_contains(page($jUser, '/settings/')['body'], 'id="prefs-text-hint"'), 'the settings screen shows where texts go (the OS channels link) when nothing was refused');
$r = req('POST', '/settings/prefs.php', ['jar' => $jUser, 'headers' => ['HX-Request: true'], 'form' => ['digest' => 'no', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['headers'], 'HX-Location') && str_contains($r['headers'], 'prefsChanged'), 'under HTMX: HX-Location to the settings and the prefsChanged trigger');

echo "3. CSRF and the tokens screen\n";
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jUser, 'form' => ['label' => 'proof']]);
ok($r['code'] === 403, 'minting a token without the CSRF token: 403');
$before = (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jUser, 'form' => ['label' => 'proof', 'csrf_token' => $t]]);
ok($r['code'] === 302 && $r['location'] === '/settings/tokens/', 'with it: 302 to the tokens screen');
$r = page($jUser, '/settings/tokens/');
preg_match('/id="tokens-minted-value">(mcp_[0-9a-f]{48})</', $r['body'], $m);
$raw = $m[1] ?? '';
ok($raw !== '' && !str_contains(page($jUser, '/settings/tokens/')['body'], $raw), 'the new token is shown once (mcp_ + 48 hex) and never again');
$row = q('SELECT id, token_hash, label, scope FROM mcp_access_tokens WHERE member_id = 26 ORDER BY id DESC LIMIT 1')[0];
ok($row['token_hash'] === hash('sha256', $raw) && $row['token_hash'] !== $raw && $row['scope'] === 'mcp' && (int) one('SELECT count(*) FROM mcp_access_tokens WHERE member_id = 26') === $before + 1, 'only its SHA-256 is stored; scope mcp');
$logged = q("SELECT after::text AS a FROM activity_log WHERE action = 'token.mint' ORDER BY id DESC LIMIT 1")[0]['a'] ?? '';
ok($logged !== '' && !str_contains($logged, $raw) && str_contains($logged, 'proof'), 'token.mint logs the label, never the token');
$mine = json_decode(page($jUser, '/settings/tokens/', $json)['body'], true)['data']['tokens'];
ok($mine !== [] && !isset($mine[0]['token_hash']) && !str_contains(json_encode($mine), $raw) && isset($mine[0]['token_id']), 'the tokens screen\'s JSON lists tokens without the value or the hash');
pdo()->exec("SELECT set_config('app.member_id', '26', false)");
$resolved = q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]);
ok(count($resolved) === 1 && (int) $resolved[0]['member_id'] === 26 && $resolved[0]['member_kind'] === 'human', 'mcp_resolve_token() resolves the minted token to Priya (what the records server will do in Phase 4)');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jUser, 'form' => ['label' => 'x', 'scope' => 'other', 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a scope that is neither mcp nor api: 422');
$r = req('POST', '/settings/tokens/mint.php', ['jar' => $jUser, 'form' => ['label' => str_repeat('x', 81), 'csrf_token' => $t]]);
ok($r['code'] === 422, 'a label over 80 characters: 422');
$id = (int) $row['id'];
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jSupport, 'form' => ['token' => $id, 'csrf_token' => csrf_of(page($jSupport, '/')['body'])]]);
ok($r['code'] === 404 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) === null && str_contains($r['body'], 'Token not found.'), 'another person cannot revoke it: 404 "Token not found.", still live');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jUser, 'form' => ['token' => $id, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT revoked_at FROM mcp_access_tokens WHERE id = :i', ['i' => $id]) !== null, 'the owner revokes it: 302, revoked_at set');
ok(count(q("SELECT 1 FROM activity_log WHERE action = 'token.revoke' AND entity_id = :i", ['i' => $id])) === 1, 'token.revoke is logged');
ok(q("SELECT * FROM mcp_resolve_token(:h, 'mcp')", ['h' => hash('sha256', $raw)]) === [], 'and the revoked token no longer resolves');
$r = req('POST', '/settings/tokens/revoke.php', ['jar' => $jUser, 'form' => ['token' => $id, 'csrf_token' => $t]]);
ok($r['code'] === 404, 'revoking it again: 404');

echo "4. Notifications\n";
ok(str_contains(page($jUser, '/notifications')['body'], 'id="notifications-empty"') && !str_contains(page($jUser, '/')['body'], 'id="header-bell-count"'), 'the empty shape ("Nothing yet"); no count on the bell');
pdo()->exec("INSERT INTO notifications (member_id, kind, title, body) VALUES (26, 'assigned', 'SMOKE a ticket was assigned to you', 'HD-1 Printer'), (26, 'replied', 'SMOKE a requester replied', null), (27, 'assigned', 'SMOKE for Marco', null)");
$h = page($jUser, '/')['body'];
ok(preg_match('/id="header-bell-count">2</', $h) === 1, 'two unread: the bell shows 2');
$d = json_decode(page($jUser, '/notifications', $json)['body'], true)['data'];
ok(count($d['notifications']) === 2 && $d['notifications'][0]['read'] === false && !str_contains(json_encode($d), 'for Marco'), 'the screen lists her two, never Marco\'s');
$nid = (int) one("SELECT id FROM notifications WHERE member_id = 26 AND kind = 'assigned'");
$since = last_activity_id();
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jUser, 'form' => ['notification' => $nid, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $nid]) !== null && preg_match('/id="header-bell-count">1</', page($jUser, '/')['body']) === 1, 'marking one read: 302, read_at set, the bell shows 1');
$mid = (int) one("SELECT id FROM notifications WHERE member_id = 27");
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jUser, 'form' => ['notification' => $mid, 'csrf_token' => $t]]);
ok($r['code'] === 302 && one('SELECT read_at FROM notifications WHERE id = :i', ['i' => $mid]) === null, 'marking Marco\'s: nothing changes (own rows only)');
$r = req('POST', '/settings/notifications/read.php', ['jar' => $jUser, 'form' => ['csrf_token' => $t]]);
ok($r['code'] === 302 && (int) one('SELECT count(*) FROM notifications WHERE member_id = 26 AND read_at IS NULL') === 0 && count(activity('notification.read', $since)) === 3, 'no id marks all of hers read; notification.read logged each time');
ok(count(json_decode(page($jUser, '/notifications?unread=1', $json)['body'], true)['data']['notifications']) === 0, '?unread=1 now lists none');

echo "5. The activity trail — the caller's own rows\n";
$rows = json_decode(page($jUser, '/activity', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 26, 'Priya sees only her own rows (' . count($rows) . ', all by member 26)');
ok(str_contains(json_encode($rows), 'changed how they are told') && str_contains(json_encode($rows), 'made an access token'), 'each row has its sentence');
$rows = json_decode(page($jAdmin, '/activity?action=member.', $json)['body'], true)['data']['rows'] ?? [];
ok($rows !== [] && count(array_unique(array_map(fn ($x) => $x['actor']['member_id'], $rows))) === 1 && $rows[0]['actor']['member_id'] === 1, 'the trail is the member\'s OWN by default — even the super-admin\'s shows only their rows here');
$r = page($jUser, '/activity');
ok($r['code'] === 200 && str_contains($r['body'], 'id="activity-table"'), 'the screen renders (Pattern B table)');
$r = page($jUser, '/activity?period=7', ['headers' => ['HX-Request: true', 'HX-Target: activity-results']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '<div id="activity-results"') && !str_contains($r['body'], '<html'), 'the filter swaps only #activity-results');

echo "6. The command bar goes through the kernel's chat endpoint\n";
$r = req('POST', '/assistant/ask.php', ['jar' => $jUser, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'Who can fix the printer?', 'screen' => 'dashboard', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'Hello from the fake expert'), 'the utterance is answered with the kernel\'s reply');
$a = activity('assistant.ask', 0);
$la = json_decode((string) end($a)['after'], true);
ok($a !== [] && ($la['length'] ?? 0) === 24 && ($la['run_id'] ?? 0) === 1 && !str_contains((string) end($a)['after'], 'printer'), 'assistant.ask logs the length and the run id, never the text');
$r = req('POST', '/assistant/ask.php', ['jar' => $jUser, 'form' => ['utterance' => 'hi']]);
ok($r['code'] === 403, 'without the CSRF token: 403');
kernel_state(function ($s) { $s['chat_status'] = 404; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jUser, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 404 && str_contains($r['body'], 'No expert for this application.'), 'the kernel answers 404: the bar shows the kernel\'s words');
kernel_state(function ($s) { $s['chat_status'] = 409; $s['chat_message'] = 'The expert is busy with another turn.'; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jUser, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 409 && str_contains($r['body'], 'busy with another turn'), 'a 409: shown in the kernel\'s words');
kernel_state(function ($s) { unset($s['chat_status'], $s['chat_message']); $s['chat'] = ['run_id' => 2, 'status' => 'awaiting_approval', 'finished' => true, 'reply' => 'I drafted the reply; it waits for approval.',
    'actions' => [['tool' => 'ticket_reply', 'status' => 'awaiting_approval', 'record_id' => 5], ['tool' => 'ticket_set_priority', 'status' => 'ok', 'record_id' => 5]], 'approval_request_id' => 77]; return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jUser, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'reply', 'csrf_token' => $t]]);
ok($r['code'] === 200 && str_contains($r['body'], 'waits for approval') && str_contains($r['body'], 'request #77') && str_contains($r['body'], 'ticket_set_priority') && str_contains($r['headers'], 'HX-Trigger: ticketChanged'), 'a paused action is shown as "waits for approval"; the succeeded one fires ticketChanged');
kernel_state(function ($s) { unset($s['chat']); return $s; });
$r = req('POST', '/assistant/ask.php', ['jar' => $jUser, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => str_repeat('x', 2100), 'csrf_token' => $t]]);
ok($r['code'] === 422, 'an utterance over 2,000 characters: 422');
$r = req('POST', '/assistant/ask.php', ['jar' => $jUser, 'headers' => ['HX-Request: true'], 'form' => ['utterance' => 'hi', 'csrf_token' => $t]]);
ok($r['code'] === 200, 'fake kernel restored');

echo "7. Action tokens and run tokens — the gate for the actions server and the MCP servers\n";
$key = need('ACTION_TOKEN_KEY');
$relayKey = need('ACTIONS_RELAY_KEY');
$person = fn (int $m, int $ttl = 300): string => ($p = $m . '.' . (time() + $ttl)) . '.' . hash_hmac('sha256', $p, $key);
$run = fn (int $m, int $runId, int $ttl = 300): string => ($p = $m . '.' . (time() + $ttl) . '.' . $runId) . '.' . hash_hmac('sha256', 'run:' . $p, $key);
$relay = fn (string $tok): string => hash_hmac('sha256', $tok, $relayKey);
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(26)]]);
$rows = json_decode($r['body'], true)['data']['rows'] ?? [];
ok($r['code'] === 200 && $rows !== [] && $rows[0]['actor']['member_id'] === 26 && !str_contains($r['headers'], 'Set-Cookie'), 'a person\'s action token acts as that member for one request (200, her rows, no Set-Cookie)');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(26, -5)]]);
ok($r['code'] === 401, 'an expired action token: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . (function (string $t): string { return substr($t, 0, -1) . ($t[-1] === '0' ? '1' : '0'); })($person(26))]]);
ok($r['code'] === 401, 'a tampered action token: 401');
$since = last_activity_id();
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(4242)]]);
ok($r['code'] === 401 && (int) one('SELECT count(*) FROM members WHERE id = 4242') === 0, 'an action token for an id with no mirror row: 401 and NO row created');
ok(count(activity('member.refused', $since)) === 1, 'and member.refused is logged');
$r = req('POST', '/settings/tokens/mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(26)], 'form' => ['label' => 'by action token']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && str_starts_with($d['token'] ?? '', 'mcp_') && ($d['record_id'] ?? 0) > 0 && str_ends_with((string) $d['location'], '#token-row-' . $d['record_id']), 'a write under the action token needs no CSRF token: JSON carries the token once, record_id and a location ending in the id');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $person(26)], 'form' => ['digest' => 'yes']]);
$d = json_decode($r['body'], true);
ok($r['code'] === 200 && $d['ok'] === true && $d['did'] === 'Saved how you are told' && one('SELECT digest FROM notification_prefs WHERE member_id = 26'), 'prefs_save under the action token answers from emit_action_status (ok, did)');
// the agent in the mirror (the fixture's Frontline, 40, with a capability from access[]) and one with no grant yet
ok(q('SELECT member_kind, capability FROM members WHERE id = 40')[0] === ['member_kind' => 'agent', 'capability' => 'write'], 'the fixture\'s Frontline agent (40) is admitted by the feed\'s access[]');
kernel_state(function ($s) { $s['incremental'] = incr(['members' => [['id' => 900, 'member_kind' => 'agent', 'display_name' => 'SMOKE Triage agent', 'email' => null, 'business_role' => 'user', 'is_external' => false, 'status' => 'active', 'updated_at' => '2026-01-01T00:00:00Z', 'departments' => []]]], '2026-01-01T00:03:00.000000Z'); return $s; });
sync();
ok(q('SELECT member_kind, capability FROM members WHERE id = 900')[0] === ['member_kind' => 'agent', 'capability' => null], 'the feed introduced an agent (member 900) with no grant yet');
$tok = $run(900, 77);
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok]]);
ok($r['code'] === 401, 'a run token WITHOUT the relay: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . str_repeat('0', 64)]]);
ok($r['code'] === 401, 'a run token with a wrong relay: 401');
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)]]);
ok($r['code'] === 401 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === null, 'a run token with the relay but a run the kernel does not vouch for: 401, no admission recorded');
kernel_state(function ($s) { $s['facts']['77'] = ['valid' => true, 'is_agent' => true, 'member_id' => 900, 'run_id' => 77, 'request_id' => 'req-run-77', 'trigger' => 'chat', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
$since = last_activity_id();
$r = req('GET', '/activity', ['headers' => ['Accept: application/json', 'X-Screen-View: 1', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)]]);
ok($r['code'] === 200 && q('SELECT capability FROM members WHERE id = 900')[0]['capability'] === 'write', 'once the kernel vouches (valid, an agent, this application\'s endpoints): 200 and the admission is recorded');
$row = q("SELECT source, agent_run_id, request_id FROM activity_log WHERE action = 'screen.view' AND id > :s ORDER BY id LIMIT 1", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 77 && ($row['request_id'] ?? '') === 'req-run-77', 'its rows are source agent, run 77, with the run\'s own request id from the kernel');
$r = req('POST', '/settings/tokens/mint.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)], 'form' => ['label' => 'agent']]);
ok($r['code'] === 403, 'an agent may not mint a person\'s token (people only): 403');
$r = req('GET', '/', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)]]);
ok($r['code'] === 403, 'nor open the person\'s dashboard: 403');
$r = req('POST', '/settings/prefs.php', ['headers' => ['Accept: application/json', 'X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay($tok)], 'form' => ['digest' => 'no']]);
ok($r['code'] === 200 && one('SELECT digest FROM notification_prefs WHERE member_id = 900') === false, 'but an agent may save its own notification choices (prefs_save is "own")');
kernel_state(function ($s) { unset($s['incremental'], $s['facts']); return $s; });

echo "8. Health\n";
$r = req('GET', '/api/v1/health');
$h = json_decode($r['body'], true);
ok($r['code'] === 200 && $h['ok'] === true && $h['application'] === 'helpdesk' && $h['database'] === 'ok', 'GET /api/v1/health: ok, application helpdesk, database ok');
ok(array_key_exists('ingest_lag', $h) && is_array($h['directory']) && $h['directory']['synced'] === true && $h['directory']['error'] === null, 'it carries ingest_lag and the directory sync state (synced, no error)');
ok(req('POST', '/api/v1/health')['code'] === 405, 'POST to health: 405');
finish();
