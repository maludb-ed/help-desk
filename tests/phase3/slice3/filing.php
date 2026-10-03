<?php
/** Proof — filing for every requester kind (spec "Proof", 2): Priya for herself; Marco on behalf of Priya and for a new external; an agent under a run token with an idempotent external_id; a plain requester posting on_behalf_of → 422; the created notice holding the link, the raw token only there. */
require __DIR__ . '/lib.php';
$W = world();
$priya = as_member(26); $marco = as_member(27); $owner = as_member(1);
act($owner, '/settings/queues/member.php', ['queue' => general_queue(), 'member' => 27]);                 // Marco also works General

echo "1. Priya files for herself\n";
$since = last_activity_id();
[$c, $b] = act($priya, '/tickets/save.php', ['subject' => 'SMOKE my screen flickers', 'body' => 'Every morning for ten minutes.', 'priority' => 'urgent']);
$t1 = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $t1 > 0 && ($b['location'] ?? '') === '/tickets/' . $t1 && preg_match('/^HD-\d{4}$/', (string) $b['number']), "ticket_create: 200, location /tickets/$t1, number {$b['number']}");
$row = ticket_row($t1);
ok((int) $row['requester_id'] === $W['priya'] && $row['submitter_member_id'] === null && $row['channel'] === 'web' && (int) $row['queue_id'] === general_queue() && $row['priority'] === 'urgent', 'requester = her row, no submitter, channel web, the default queue, urgent as she said');
$m = q('SELECT kind, author_member_id, author_requester_id FROM messages WHERE ticket_id = :t', ['t' => $t1]);
ok(count($m) === 1 && $m[0]['kind'] === 'public_reply' && (int) $m[0]['author_member_id'] === 26, 'the first message is her public reply, by her member id');
$log = activity('ticket.create', $since);
ok(count($log) === 1 && (int) $log[0]['ticket_id'] === $t1 && (int) $log[0]['queue_id'] === general_queue() && json_decode((string) $log[0]['after'], true)['channel'] === 'web', 'ticket.create logged with ticket_id, queue_id and the channel');
$ob = outbox($t1, 'created');
ok(count($ob) === 1 && (int) $ob[0]['member_id'] === 26 && str_contains($ob[0]['body'], '/tickets/' . $t1), 'a created notice for her, with a link to her page');
ok((int) one('SELECT count(*) FROM ticket_links_secure WHERE ticket_id = :t AND rotated_at IS NULL', ['t' => $t1]) === 1, 'a secure link was minted (its hash only)');
[$c, $b] = act($priya, '/tickets/save.php', ['subject' => 'SMOKE for my colleague', 'body' => 'x', 'on_behalf_of' => 27]);
ok($c === 422 && msg($b) === 'You can file a ticket for yourself here; support files on behalf of others.', 'a plain requester posting on_behalf_of: 422 in words');
[$c, $b] = act($priya, '/tickets/save.php', ['subject' => 'SMOKE hidden category', 'body' => 'x', 'category' => (int) one("INSERT INTO categories (name, requester_visible) VALUES ('SMOKE Hidden', false) RETURNING id")]);
ok($c === 422 && isset(fields($b)['category']), 'a category requesters may not choose: 422');

echo "2. Marco files on behalf of Priya, and for a new customer\n";
[$c, $b] = act($marco, '/tickets/save.php', ['subject' => 'SMOKE Priya called about her badge', 'body' => 'She cannot get in.', 'for' => 'member', 'on_behalf_of' => 26, 'queue' => $W['it'], 'tags' => 'badge, access', 'cc' => 'boss@customer.invalid', 'as_note' => 'yes']);
$t2 = (int) ($b['record_id'] ?? 0);
$row = ticket_row($t2);
ok($c === 200 && (int) $row['requester_id'] === $W['priya'] && (int) $row['submitter_member_id'] === 27 && (int) $row['queue_id'] === $W['it'], 'on behalf: requester Priya, submitter Marco, in IT help');
ok(q('SELECT kind FROM messages WHERE ticket_id = :t', ['t' => $t2])[0]['kind'] === 'internal_note' && (int) one('SELECT count(*) FROM ticket_tags WHERE ticket_id = :t', ['t' => $t2]) === 2, 'the body filed as a note; two tags');
$cc = q("SELECT f.requester_id, r.primary_email FROM ticket_followers f JOIN requesters r ON r.id = f.requester_id WHERE f.ticket_id = :t AND f.kind = 'cc'", ['t' => $t2]);
ok(count($cc) === 1 && $cc[0]['primary_email'] === 'boss@customer.invalid' && (int) one('SELECT organization_id FROM requesters WHERE id = :r', ['r' => (int) $cc[0]['requester_id']]) === $W['org'], 'the cc became an external, claimed by Customer Co\'s domain');
ok(json_decode((string) activity('ticket.create', $since)[1]['after'], true)['on_behalf_of'] === 26, 'ticket.create carries on_behalf_of');
[$c, $b] = act($marco, '/tickets/save.php', ['subject' => 'SMOKE new customer cannot log in', 'body' => 'Password reset loop.', 'for' => 'customer', 'requester_name' => 'SMOKE Newbie', 'requester_email' => 'New@Customer.invalid', 'queue' => $W['it']]);
$t3 = (int) ($b['record_id'] ?? 0);
$row = ticket_row($t3);
$req = q('SELECT kind, display_name, primary_email::text AS e, organization_id FROM requesters WHERE id = :r', ['r' => (int) $row['requester_id']])[0];
ok($c === 200 && $req['kind'] === 'external' && $req['e'] === 'new@customer.invalid' && $req['display_name'] === 'SMOKE Newbie' && (int) $req['organization_id'] === $W['org'] && (int) $row['organization_id'] === $W['org'], 'for a customer: an external made, claimed by the domain; the ticket carries the organization');
$ob = outbox($t3, 'created');
ok(count($ob) === 1 && $ob[0]['requester_id'] !== null && preg_match('#/t/([a-f0-9]{48})#', $ob[0]['body'], $m) === 1, 'the external\'s created notice carries the secure link');
$raw = $m[1];
ok((int) one('SELECT ticket_id FROM ticket_links_secure WHERE token_hash = :h AND rotated_at IS NULL', ['h' => hash('sha256', $raw)]) === $t3, 'the raw token in the notice is the live link\'s (hashed in the table)');
ok(!str_contains(json_encode(activity('ticket.create', $since)), $raw) && !str_contains(page($marco, '/tickets/' . $t3)['body'], $raw), 'the raw token appears nowhere else — not the log, not the page');
[$c, $b] = act($marco, '/tickets/save.php', ['subject' => 'SMOKE via requester id', 'body' => 'x', 'requester' => $W['jo']]);
ok($c === 200 && (int) ticket_row((int) $b['record_id'])['requester_id'] === $W['jo'], 'requester as an id (an agent\'s way) works too');
[$c, $b] = act($marco, '/tickets/save.php', ['subject' => 'SMOKE via requester email', 'body' => 'x', 'requester' => 'jo@customer.invalid']);
ok($c === 200 && (int) ticket_row((int) $b['record_id'])['requester_id'] === $W['jo'], 'requester as a known email resolves to Jo');

echo "3. Routing by category\n";
$cat = (int) act($owner, '/settings/vocabulary/category.php', ['name' => 'SMOKE Access', 'default_queue' => $W['it'], 'default_priority' => 'high', 'default_type' => type_id('incident')])[1]['record_id'];
[$c, $b] = act($priya, '/tickets/save.php', ['subject' => 'SMOKE locked out', 'body' => 'x', 'category' => $cat]);
$row = ticket_row((int) $b['record_id']);
ok($c === 200 && (int) $row['queue_id'] === $W['it'] && $row['priority'] === 'high' && (int) $row['type_id'] === type_id('incident'), 'a category routes the queue, the priority and the type');
$tg = targets((int) $b['record_id']);
ok(ticket_status((int) $b['record_id']) === 'new' && isset($tg['first_response'], $tg['resolution']) && (int) ticket_row((int) $b['record_id'])['sla_policy_id'] > 0, 'the trigger: status new, first-response and resolution targets from the matched policy (her own first message also starts next-response)');

echo "4. An agent under a run token, idempotent by external_id\n";
kernel_state(function ($s) { $s['facts']['301'] = ['valid' => true, 'is_agent' => true, 'member_id' => 40, 'run_id' => 301, 'request_id' => 'req-run-301', 'trigger' => 'duty', 'endpoints' => [['name' => 'Records MCP']]]; return $s; });
[$tok, $relay] = run_token(40, 301);
$hdr = ['X-Action-Token: ' . $tok, 'X-Action-Relay: ' . $relay];
$since = last_activity_id();
[$c, $b] = act_token('/tickets/save.php', ['subject' => 'SMOKE disk 91% on db-1', 'body' => 'Monitoring saw it.', 'external_id' => 'SMOKE-mon-42', 'queue' => $W['it'], 'priority' => 'high'], $hdr);
$ta = (int) ($b['record_id'] ?? 0);
$row = ticket_row($ta);
ok($c === 200 && $ta > 0 && (int) $row['requester_id'] === requester_of_member(40) && $row['channel'] === 'agent' && $row['external_id'] === 'SMOKE-mon-42', 'the agent files one: requester = the agent, channel agent, the external id kept');
$log = activity('ticket.create', $since);
ok(count($log) === 1 && $log[0]['source'] === 'agent' && (int) $log[0]['agent_run_id'] === 301 && $log[0]['request_id'] === 'req-run-301', 'its row is source agent, run 301, the run\'s request id');
[$c, $b] = act_token('/tickets/save.php', ['subject' => 'SMOKE disk 91% on db-1', 'body' => 'Monitoring saw it again.', 'external_id' => 'SMOKE-mon-42', 'queue' => $W['it']], $hdr);
ok($c === 200 && (int) ($b['record_id'] ?? 0) === $ta && str_contains((string) $b['did'], 'already exists') && (int) one("SELECT count(*) FROM tickets WHERE external_id = 'SMOKE-mon-42'") === 1, 'the same external_id again: 200 with the same record_id, nothing created');
[$c, $b] = act_token('/tickets/save.php', ['subject' => 'SMOKE for Priya by the agent', 'body' => 'x', 'on_behalf_of' => 26, 'queue' => $W['it']], $hdr);
ok($c === 200 && (int) ticket_row((int) $b['record_id'])['requester_id'] === $W['priya'] && (int) ticket_row((int) $b['record_id'])['submitter_member_id'] === 40, 'on_behalf_of by the agent: requester Priya, submitter the agent');
kernel_state(function ($s) { unset($s['facts']); return $s; });

echo "5. The default assignee\n";
$aq = (int) act($owner, '/settings/queues/save.php', ['name' => 'SMOKE Agent desk', 'department' => 5, 'assignment_method' => 'default_assignee', 'default_assignee' => 40])[1]['record_id'];
$since = last_activity_id();
[$c, $b] = act($marco, '/tickets/save.php', ['subject' => 'SMOKE goes to the agent', 'body' => 'x', 'queue' => $aq]);
$td = (int) $b['record_id'];
ok((int) ticket_row($td)['assignee_member_id'] === 40 && count(dispatches($td)) === 1 && dispatches($td)[0]['via'] === 'chat' && dispatches($td)[0]['status'] === 'answered', 'a queue whose method is default assignee: the agent assigned and dispatched (answered by the fake kernel)');
ok(count(activity('ticket.dispatch', $since)) === 1, 'ticket.dispatch logged');
finish();
