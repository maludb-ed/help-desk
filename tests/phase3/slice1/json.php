<?php
/** Proof — JSON mode (spec "Proof", 7): every handler answers {ok, location} to an action token and 422 {error:{fields}} on a bad form; a create's record_id parses from location; an agent's run token pauses nothing here (the approvals live in the kernel) but obeys the rights. */
require __DIR__ . '/lib.php';
$admin = ['X-Action-Token: ' . person_token(1)];
$support = ['X-Action-Token: ' . person_token(27)];

echo "1. Creates under an action token: {ok, record_id, location ending in the id}\n";
$creates = [
    ['/settings/queues/save.php', ['name' => 'SMOKE Token queue'], $admin, '#^/settings/queues/(\d+)\?notice=saved$#'],
    ['/organizations/save.php', ['name' => 'SMOKE Token org', 'domains' => 'token.invalid'], $support, '#^/organizations/(\d+)\?notice=saved$#'],
    ['/requesters/save.php', ['display_name' => 'SMOKE Token person', 'email' => 'token@token.invalid'], $support, '#^/requesters/(\d+)\?notice=saved$#'],
    ['/settings/sla/save.php', ['name' => 'SMOKE Token policy'], $admin, '#^/settings/sla/\?notice=saved\#policy-card-(\d+)$#'],
    ['/settings/sla/hours.php', ['name' => 'SMOKE Token hours', 'timezone' => 'UTC', 'intervals' => '[{"weekday":1,"opens":"09:00","closes":"17:00"}]'], $admin, '#^/settings/sla/hours\?schedule=(\d+)&notice=hours$#'],
];
$ids = [];
foreach ($creates as [$path, $form, $h, $re]) {
    [$c, $b, $r] = act_token($path, $form, $h);
    $m = [];
    $okc = $c === 200 && ($b['ok'] ?? false) && preg_match($re, (string) ($b['location'] ?? ''), $m) && (int) $m[1] === (int) ($b['record_id'] ?? -1) && !str_contains($r['headers'], 'Set-Cookie');
    ok($okc, "$path → {ok, record_id {$b['record_id']}, location {$b['location']}}" . ($okc ? '' : " ($c " . msg($b) . ')'));
    $ids[$path] = (int) ($b['record_id'] ?? 0);
}
echo "2. Updates, members, archives and the rest\n";
$q = $ids['/settings/queues/save.php']; $o = $ids['/organizations/save.php']; $p = $ids['/requesters/save.php']; $pol = $ids['/settings/sla/save.php']; $s = $ids['/settings/sla/hours.php'];
$writes = [
    ['/settings/queues/save.php', ['queue' => $q, 'description' => 'SMOKE changed'], $admin],
    ['/settings/queues/member.php', ['queue' => $q, 'member' => 27, 'is_lead' => 'yes'], $admin],
    ['/settings/queues/member-remove.php', ['queue' => $q, 'member' => 27], $admin],
    ['/settings/queues/presence.php', ['availability' => 'online'], $support],
    ['/organizations/save.php', ['organization' => $o, 'support_tier' => 'Bronze'], $support],
    ['/requesters/save.php', ['requester' => $p, 'phone' => '+1 555 0102'], $support],
    ['/requesters/email.php', ['requester' => $p, 'email' => 'second@token.invalid'], $support],
    ['/requesters/email-remove.php', ['requester' => $p, 'email' => 'second@token.invalid'], $support],
    ['/settings/sla/save.php', ['policy' => $pol, 'warning_percent' => 50], $admin],
    ['/settings/sla/holiday.php', ['schedule' => $s, 'holiday' => '2026-12-25'], $admin],
    ['/settings/sla/holiday-remove.php', ['schedule' => $s, 'holiday' => '2026-12-25'], $admin],
    ['/settings/general.php', ['reopen_window_days' => '21'], $admin],
    ['/settings/sla/archive.php', ['policy' => $pol], $admin],
    ['/settings/queues/archive.php', ['queue' => $q], $admin],
    ['/organizations/archive.php', ['organization' => $o], $admin],
];
$wrong = [];
foreach ($writes as [$path, $form, $h]) {
    [$c, $b] = act_token($path, $form, $h);
    if ($c !== 200 || !($b['ok'] ?? false) || !isset($b['location']) || !isset($b['did'])) { $wrong[] = "$path $c " . msg($b); }
}
ok($wrong === [], count($writes) . ' more handlers answer {ok, did, location}' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
act_token('/settings/general.php', ['reopen_window_days' => '14'], $admin);
$ext = (int) one("SELECT hd_requester_for_email('merge-me@token.invalid', 'SMOKE Merge me')");
[$c, $b] = act_token('/requesters/merge.php', ['requester' => $ext, 'into' => $p], ['X-Action-Token: ' . person_token(28)]);
ok($c === 200 && ($b['ok'] ?? false) && ($b['location'] ?? '') === '/requesters/' . $p . '?notice=merged', 'requester_merge under a lead\'s token: {ok, location}');

echo "3. A bad form: 422 {error: {code invalid, fields}}\n";
[$c, $b] = act_token('/settings/queues/save.php', ['name' => '', 'assignment_method' => 'magic', 'sort_order' => 'x'], $admin);
$f = fields($b);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($f['name'], $f['assignment_method'], $f['sort_order']) && count($b['error']['errors']) === 3, 'queue_create with three bad fields: 422, three field errors (' . implode(', ', array_keys($f)) . ')');
[$c, $b] = act_token('/requesters/save.php', ['display_name' => 'SMOKE Known', 'email' => 'token@token.invalid'], $support);
ok($c === 422 && str_contains(msg($b), 'belongs to SMOKE Token person'), 'a known address: 422 naming the owner');
[$c, $b] = act_token('/settings/queues/save.php', ['name' => 'SMOKE Not admin'], $support);
ok($c === 403 && ($b['error']['code'] ?? '') === 'forbidden', 'the rights hold under a token: support staff creating a queue → 403');
[$c, $b] = act_token('/settings/queues/save.php', ['name' => 'SMOKE Expired'], ['X-Action-Token: ' . person_token(1, -5)]);
ok($c === 401, 'an expired token: 401');

echo "4. The `any field of` partial update (the kernel's _partial)\n";
$q2 = (int) act_token('/settings/queues/save.php', ['name' => 'SMOKE Partial', 'description' => 'SMOKE keep me', 'sort_order' => 42], $admin)[1]['record_id'];
[$c, $b] = act_token('/settings/queues/save.php', ['queue' => $q2, 'name' => 'SMOKE Partial renamed', '_partial' => '1'], $admin);
ok($c === 200 && q('SELECT name, description, sort_order FROM queues WHERE id = :q', ['q' => $q2])[0] == ['name' => 'SMOKE Partial renamed', 'description' => 'SMOKE keep me', 'sort_order' => 42], 'queue_update with _partial=1: the base row fills what was not sent');

echo "5. An agent's run token obeys the rights and is logged as the run\n";
kernel_state(function ($st) { $st['facts']['88'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 88, 'request_id' => 'req-run-88', 'trigger' => 'duty', 'endpoints' => [['name' => 'Records MCP']]]; return $st; });
[$tok, $relay] = run_token(40, 88);
$hdr = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
$since = last_activity_id();
[$c, $b] = act_token('/organizations/save.php', ['name' => 'SMOKE By agent'], $hdr);
ok($c === 200 && ($b['ok'] ?? false), 'the Frontline agent (support role) creates an organization: 200');
$row = q("SELECT source, agent_run_id, request_id FROM activity_log WHERE action = 'organization.create' AND id > :s", ['s' => $since])[0] ?? [];
ok(($row['source'] ?? '') === 'agent' && (int) ($row['agent_run_id'] ?? 0) === 88 && ($row['request_id'] ?? '') === 'req-run-88', 'its row is source agent, run 88, the run\'s request id');
[$c, $b] = act_token('/settings/queues/save.php', ['name' => 'SMOKE Agent queue'], $hdr);
ok($c === 403, 'but it may not create a queue (settings.manage): 403');
kernel_state(function ($st) { unset($st['facts']); return $st; });
finish();
