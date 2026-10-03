<?php
/** Proof — the translator is the law (spec "Proof", 1): for each key a ticket that matches and one that does not, through the inbox's query keys and through a saved view; an unknown key refused by name; sort_by=foo falls back, never SQL. */
require __DIR__ . '/lib.php';
$W = world();
$lena = as_member(28); $marco = as_member(27); $owner = as_member(1);
$it = $W['it']; $fac = $W['fac'];
echo "1. Fixtures\n";
$mine = (int) act($lena, '/tickets/save.php', ['subject' => 'SMOKE Lena needs a laptop', 'body' => 'x', 'queue' => $it])[1]['record_id'];            // requester = Lena herself
$a = (int) act($lena, '/tickets/save.php', ['subject' => 'SMOKE translator A', 'body' => 'x', 'queue' => $it, 'requester' => $W['jo'], 'priority' => 'urgent', 'type' => type_id('problem'), 'tags' => 'translator-a'])[1]['record_id'];
$b = (int) act($lena, '/tickets/save.php', ['subject' => 'SMOKE translator B', 'body' => 'x', 'queue' => $fac, 'requester' => requester_of_member(26), 'priority' => 'low', 'type' => type_id('incident'), 'tags' => 'translator-b'])[1]['record_id'];
$cat = (int) act($owner, '/settings/vocabulary/category.php', ['name' => 'SMOKE Translator cat'])[1]['record_id'];
act($lena, '/tickets/fields.php', ['ticket' => $a, 'category' => $cat]);
act($lena, '/tickets/transition.php', ['ticket' => $a, 'status' => 'open', 'assignee' => 'me']);
q("INSERT INTO messages (ticket_id, kind, author_requester_id, body_text, channel) VALUES (:t, 'public_reply', :r, 'SMOKE needs reply', 'portal')", ['t' => $a, 'r' => $W['jo']]);   // needs_reply on A
q("UPDATE sla_targets SET due_at = now() - interval '2 hours' WHERE ticket_id = :t AND metric = 'resolution'", ['t' => $a]);                                                          // breached on A
q("UPDATE tickets SET channel = 'email' WHERE id = :t", ['t' => $a]);
q("UPDATE tickets SET last_agent_reply_at = now() WHERE id = :t", ['t' => $b]);                                                                                   // B was answered: no reply needed
q("UPDATE tickets SET last_activity_at = now() - interval '10 days' WHERE id = :t", ['t' => $b]);
$stale = (int) one('SELECT count(*) FROM mcp_tickets WHERE ticket_id = :t AND last_activity_at < now() - interval \'9 days\'', ['t' => $b]);
ok($stale === 1, 'B is stale by 10 days (the date stuck)');
$has = fn (array $ids, int $yes, int $no) => in_array($yes, $ids, true) && !in_array($no, $ids, true);
echo "2. Each key, through the inbox's query keys (Lena sees everything)\n";
$cases = [
    ['status=open', $a, $b, 'status_category'], ['status_key=new', $b, $a, 'status'], ['queue=' . $it, $a, $b, 'queue_id'], ['assignee=me', $a, $b, 'assignee=me'], ['assignee=none', $b, $a, 'assignee=none'],
    ['assignee=28', $a, $b, 'assignee=<id>'], ['requester=me', $mine, $a, 'requester=me'], ['organization=' . $W['org'], $a, $b, 'organization_id'], ['priority=urgent', $a, $b, 'priority'],
    ['type=' . type_id('problem'), $a, $b, 'type_id'], ['category=' . $cat, $a, $b, 'category_id'], ['tags=translator-a', $a, $b, 'tags'], ['needs_reply=1', $a, $b, 'needs_reply'],
    ['sla=breached', $a, $b, 'sla'], ['stale_days=9', $b, $a, 'stale_days'], ['channel=email', $a, $b, 'channel'], ['q=translator+B', $b, $a, 'text'],
];
$bad = [];
foreach ($cases as [$qs, $yes, $no, $label]) {
    $ids = inbox_ids($lena, $qs);
    if (!$has($ids, $yes, $no)) { $bad[] = $label . ' (' . $qs . ': ' . implode(',', $ids) . ')'; }
}
ok($bad === [], count($cases) . ' keys each keep the matching ticket and drop the other' . ($bad ? ' — wrong: ' . implode('; ', $bad) : ''));
echo "3. A saved view and an ad-hoc filter answer the same ids\n";
[$c, $bd] = act($lena, '/views/save.php', ['name' => 'SMOKE open and mine', 'scope' => 'personal', 'filters' => json_encode(['status_category' => ['open'], 'assignee' => 'me'])]);
$vid = (int) ($bd['record_id'] ?? 0);
ok($c === 200 && $vid > 0 && ($bd['location'] ?? '') === '/views/' . $vid . '/edit?notice=saved', 'view_create: 200, lands on the edit page');
$adhoc = inbox_ids($lena, 'status=open&assignee=me'); sort($adhoc);
$saved = inbox_ids($lena, 'view=' . $vid); sort($saved);
ok($adhoc === $saved && in_array($a, $saved, true), 'the inbox with ?status=open&assignee=me and the saved view {"status_category":["open"],"assignee":"me"} answer the same ids (' . count($saved) . ')');
[$c, $d] = screen($lena, '/inbox?view=' . $vid);
ok($d['view'] === $vid && $d['view_name'] === 'SMOKE open and mine', 'the inbox names the saved view');
echo "4. Refusals and strangers\n";
[$c, $bd] = act($lena, '/views/save.php', ['name' => 'SMOKE bad key', 'scope' => 'personal', 'filters' => json_encode(['foo' => 1, 'status_category' => ['open']])]);
ok($c === 422 && (fields($bd)['foo'] ?? '') === "'foo' is not a filter.", "an unknown key on save: 422 \"'foo' is not a filter.\"");
[$c, $bd] = act($lena, '/views/save.php', ['name' => 'SMOKE bad values', 'scope' => 'personal', 'filters' => json_encode(['priority' => ['extreme'], 'stale_days' => 900, 'assignee' => 'them'])]);
$f = fields($bd);
ok($c === 422 && isset($f['priority'], $f['stale_days'], $f['assignee']), 'bad values: 422 by key (' . implode(', ', array_keys($f)) . ')');
[$c, $bd] = act($lena, '/views/save.php', ['name' => 'SMOKE bad sort', 'scope' => 'personal', 'sort_by' => 'foo; DROP TABLE tickets']);
ok($c === 422 && isset(fields($bd)['sort_by']) && (int) one('SELECT count(*) FROM tickets') > 0, 'sort_by as SQL on save: 422 by key, and the tickets are still there');
$def = inbox_ids($lena, 'view=' . $vid); $foo = inbox_ids($lena, 'view=' . $vid . '&sort=foo');
ok($def === $foo && $foo !== [-1], 'the inbox with ?sort=foo answers the default order (200)');
[$c, $d] = screen($lena, '/inbox?view=' . $vid . '&sort=priority');
ok($c === 200 && ($d['filters']['sort'] ?? '') === 'priority', 'and a known sort key is honoured');
[$c, $bd] = act($lena, '/views/save.php', ['name' => 'SMOKE form filters', 'scope' => 'personal', 'filters' => ['status_category' => ['new', 'open'], 'tags' => 'translator-a, translator-b', 'priority' => [], 'text' => '', 'needs_reply' => 'true'], 'columns' => ['number', 'subject', 'bogus'], 'layout' => 'list']);
$row = view_row((int) $bd['record_id']);
$fj = json_decode($row['filters'], true); ksort($fj);
ok($c === 200 && $fj === ['needs_reply' => true, 'status_category' => ['new', 'open'], 'tags' => ['translator-a', 'translator-b']] && pg_text_array($row['columns']) === ['number', 'subject'], 'the form\'s arrays: blanks dropped, tags split, columns allow-listed (' . json_encode($fj) . ')');
finish();
