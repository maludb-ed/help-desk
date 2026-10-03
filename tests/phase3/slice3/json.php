<?php
/** Proof — JSON mode (spec "Proof", 8): every handler under an action token answers {ok, location, record_id} or 422 {error:{code invalid}} or 403/404 in one sentence; _partial keeps the fields; the relay missing → 401; an unknown member → 401 and no row. */
require __DIR__ . '/lib.php';
$W = world();
$marco = ['X-Action-Token: ' . person_token(27)]; $lena = ['X-Action-Token: ' . person_token(28)]; $owner = ['X-Action-Token: ' . person_token(1)];
echo "1. Every handler\n";
[$c, $b] = act_token('/tickets/save.php', ['subject' => 'SMOKE by token', 'body' => 'x', 'queue' => $W['it'], 'requester' => $W['jo']], $marco);
$t = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $t > 0 && ($b['location'] ?? '') === '/tickets/' . $t, "ticket_create → {ok, record_id $t, location}");
$calls = [
    ['/tickets/save.php', ['ticket' => $t, 'subject' => 'SMOKE by token (edited)']], ['/tickets/reply.php', ['ticket' => $t, 'body' => 'r']], ['/tickets/note.php', ['ticket' => $t, 'body' => 'n']],
    ['/tickets/transition.php', ['ticket' => $t, 'status' => 'open']], ['/tickets/assign.php', ['ticket' => $t, 'assignee' => 'me']], ['/tickets/fields.php', ['ticket' => $t, 'priority' => 'high']],
    ['/tickets/tags.php', ['ticket' => $t, 'tags' => 'a,b']], ['/tickets/untag.php', ['ticket' => $t, 'tag' => 'a']], ['/tickets/follow.php', ['ticket' => $t]], ['/tickets/unfollow.php', ['ticket' => $t]],
    ['/tickets/cc.php', ['ticket' => $t, 'cc' => 'cc1@customer.invalid']], ['/tickets/cc-remove.php', ['ticket' => $t, 'cc' => (string) (int) one("SELECT hd_requester_for_email('cc1@customer.invalid')")]],
    ['/tickets/link.php', ['ticket' => $t, 'other_ticket' => $W['t_jo'], 'kind' => 'related']], ['/tickets/unlink.php', ['ticket' => $t, 'other_ticket' => $W['t_jo'], 'kind' => 'related']],
    ['/tickets/time.php', ['ticket' => $t, 'minutes' => 5]], ['/tickets/secure-link.php', ['ticket' => $t]], ['/tickets/transition.php', ['ticket' => $t, 'status' => 'solved', 'resolution_code' => 'resolved']], ['/tickets/reopen.php', ['ticket' => $t]],
];
$wrong = [];
foreach ($calls as [$p, $f]) { [$c, $b] = act_token($p, $f, $marco); if ($c !== 200 || !($b['ok'] ?? false) || !isset($b['location'], $b['record_id'], $b['did'])) { $wrong[] = "$p $c " . msg($b); } }
ok($wrong === [], count($calls) . ' handlers answer {ok, did, record_id, location}' . ($wrong ? ' — ' . implode('; ', $wrong) : ''));
$eid = (int) one('SELECT max(id) FROM time_entries WHERE ticket_id = :t', ['t' => $t]);
[$c, $b] = act_token('/tickets/time-delete.php', ['entry' => $eid], $marco);
ok($c === 200 && ($b['ok'] ?? false), 'time_entry_delete too');
[$c, $b] = act_token('/tickets/close.php', ['ticket' => $t, 'resolution_code' => 'resolved'], $lena);
ok($c === 200 && ($b['ok'] ?? false), 'ticket_close as the lead');
$t2 = (int) act_token('/tickets/save.php', ['subject' => 'SMOKE to spam', 'body' => 'x', 'queue' => $W['it']], $marco)[1]['record_id'];
[$c, $b] = act_token('/tickets/spam.php', ['ticket' => $t2], $lena);
ok($c === 200 && ($b['ok'] ?? false), 'ticket_spam as the lead');
[$c, $b] = act_token('/tickets/delete.php', ['ticket' => $t2], $owner);
ok($c === 200 && ($b['ok'] ?? false) && ($b['location'] ?? '') === '/inbox?notice=deleted', 'ticket_delete as the admin');
echo "2. The refusals\n";
[$c, $b] = act_token('/tickets/save.php', ['subject' => '', 'body' => '', 'priority' => 'extreme'], $marco);
$f = fields($b);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset($f['subject'], $f['body'], $f['priority']), 'a bad create: 422 with three field errors');
[$c, $b] = act_token('/tickets/transition.php', ['ticket' => $W['t_fac'], 'status' => 'open'], $marco);
ok($c === 404 && msg($b) === 'Ticket not found.', 'a ticket Marco cannot see: 404 "Ticket not found."');
[$c, $b] = act_token('/tickets/transition.php', ['ticket' => $W['t_fac'], 'status' => 'open'], ['X-Action-Token: ' . person_token(26)]);
ok($c === 404, 'the same for Priya');
[$c, $b] = act_token('/tickets/close.php', ['ticket' => $t, 'resolution_code' => 'resolved'], $marco);
ok($c === 403, 'ticket_close as support: 403');
echo "3. _partial, the relay, an unknown member\n";
$t3 = (int) act_token('/tickets/save.php', ['subject' => 'SMOKE partial', 'body' => 'x', 'queue' => $W['it'], 'priority' => 'high', 'due_at' => '2026-12-01T10:00'], $marco)[1]['record_id'];
[$c, $b] = act_token('/tickets/save.php', ['ticket' => $t3, 'subject' => 'SMOKE partial (renamed)', '_partial' => '1'], $marco);
$row = ticket_row($t3);
ok($c === 200 && $row['subject'] === 'SMOKE partial (renamed)' && $row['priority'] === 'high' && $row['due_at'] !== null, 'ticket_update with _partial=1 keeps priority and due');
[$tok, $relay] = run_token(40, 303);
[$c, $b] = act_token('/tickets/save.php', ['subject' => 'x', 'body' => 'x'], ['X-Action-Token: ' . $tok]);
ok($c === 401, 'a run token without the relay: 401');
$since = last_activity_id();
[$c, $b] = act_token('/tickets/save.php', ['subject' => 'x', 'body' => 'x'], ['X-Action-Token: ' . person_token(9999)]);
ok($c === 401 && (int) one('SELECT count(*) FROM members WHERE id = 9999') === 0 && count(activity('member.refused', $since)) === 1, 'an unknown member id: 401, no row, member.refused logged');
echo "4. Reads under a token\n";
$r = req('GET', '/tickets/' . $t, ['headers' => array_merge(JSONH, $marco)]);
$d = json_decode($r['body'], true)['data'] ?? [];
ok($r['code'] === 200 && ($d['ticket']['number'] ?? '') === ticket_row($t)['number'] && isset($d['ticket']['targets']) && is_array($d['moves']), 'get-ticket-shaped JSON for a queue holder: the ticket, targets, moves');
$r = req('GET', '/inbox?view=mine', ['headers' => array_merge(JSONH, $marco)]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['tickets']), 'the inbox answers JSON');
finish();
