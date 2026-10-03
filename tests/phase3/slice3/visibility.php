<?php
/** Proof — see only what is mine or my queue's (spec "Proof", 1): the matrix per person on the inbox, the pages and the JSON; no address and no token leaks outside the queue holders' requester card. */
require __DIR__ . '/lib.php';
$W = world();
$priya = as_member(26); $marco = as_member(27); $lena = as_member(28); $colin = as_member(29); $ext = as_member(31); $owner = as_member(1);
$ids = fn (array $d) => array_map(fn ($t) => $t['ticket_id'], $d['tickets'] ?? []);

echo "1. Priya (a requester)\n";
[$c, $d] = screen($priya, '/my-tickets?state=all');
$i = $ids($d); sort($i);
ok($c === 200 && $i === [$W['t_p1'], $W['t_p2']], 'my-tickets lists her two tickets and nothing else (' . implode(',', $ids($d)) . ')');
ok(page($priya, '/inbox')['code'] === 403 && str_contains(html_entity_decode(page($priya, '/inbox')['body'], ENT_QUOTES), "You may not see this queue's tickets."), '/inbox → 403 in the right\'s sentence');
$r = page($priya, '/tickets/' . $W['t_jo']);
ok($r['code'] === 404 && str_contains($r['body'], 'Ticket not found.'), 'Jo\'s ticket by URL → 404 "Ticket not found."');
[$c, $d] = screen($priya, '/tickets/' . $W['t_p1']);
ok($c === 200 && $d['may']['internals'] === false && !isset($d['ticket']['targets']) && !isset($d['ticket']['secure_link']), 'her own ticket: no SLA targets, no secure link in the JSON');
$body = page($priya, '/tickets/' . $W['t_p1'])['body'];
ok(!str_contains($body, 'id="ticket-fields"') && str_contains($body, 'id="ticket-summary"') && str_contains($body, 'id="ticket-composer-reply"') && !str_contains($body, 'id="ticket-composer-note"'), 'her page: a summary, the Reply composer, no fields panel, no Note');

echo "2. Marco (support, IT through the department)\n";
[$c, $d] = screen($marco, '/inbox');
$seen = $ids($d);
ok($c === 200 && in_array($W['t_jo'], $seen, true) && in_array($W['t_jo2'], $seen, true) && !in_array($W['t_fac'], $seen, true) && !in_array($W['t_p1'], $seen, true), 'the inbox: IT\'s tickets, nothing of Facilities or Priya\'s');
[$c, $d] = screen($marco, '/tickets/' . $W['t_jo']);
ok($c === 200 && count(array_filter($d['conversation'], fn ($m) => $m['kind'] === 'internal_note')) === 1 && $d['requester']['email'] === 'jo@customer.invalid' && isset($d['ticket']['targets']), 'Jo\'s ticket: the note, Jo\'s email on the card, the SLA targets');
ok(page($marco, '/tickets/' . $W['t_fac'])['code'] === 404, 'the Facilities ticket → 404 for Marco');
$body = page($marco, '/tickets/' . $W['t_jo'])['body'];
ok(str_contains($body, 'id="ticket-requester-contact"') && str_contains($body, 'jo@customer.invalid') && str_contains($body, 'SMOKE internal: check the fuse first'), 'his page shows the address on the requester card and the note');

echo "3. Colin (collaborator, Facilities) and Lena (lead)\n";
[$c, $d] = screen($colin, '/inbox');
$seen = $ids($d);
ok($c === 200 && in_array($W['t_fac'], $seen, true) && !in_array($W['t_jo'], $seen, true), 'Colin\'s inbox: Facilities, not IT');
[$c, $d] = screen($colin, '/tickets/' . $W['t_fac']);
ok($c === 200 && count(array_filter($d['conversation'], fn ($m) => $m['kind'] === 'internal_note')) === 1 && $d['may']['note'] === true && $d['may']['work'] === false, 'the Facilities note is his to read and he may note, not work');
[$c, $d] = screen($colin, '/tickets/' . $W['t_jo2']);
ok($c === 200 && $d['may']['internals'] === false, 'the IT ticket he follows: visible, without internals');
[$c, $d] = screen($lena, '/inbox');
$seen = $ids($d);
ok(in_array($W['t_jo'], $seen, true) && in_array($W['t_fac'], $seen, true) && in_array($W['t_p1'], $seen, true), 'Lena (view_all) sees everything');

echo "4. The invited external member\n";
[$c, $d] = screen($ext, '/my-tickets?state=all');
$seen = $ids($d);
ok($c === 200 && in_array($W['t_jo'], $seen, true) && !in_array($W['t_p1'], $seen, true), 'sees Customer Co\'s tickets (the organization lets members see each other) but not Priya\'s');
[$c, $d] = screen($ext, '/tickets/' . $W['t_jo']);
ok($c === 200 && ($d['requester']['email'] ?? null) === null && count(array_filter($d['conversation'], fn ($m) => $m['kind'] === 'internal_note')) === 0, 'Jo\'s ticket: no email of hers, no note');

echo "5. No leaks\n";
$bad = [];
foreach ([[$priya, '/tickets/' . $W['t_p1']], [$priya, '/my-tickets'], [$ext, '/tickets/' . $W['t_jo']], [$ext, '/my-tickets?state=all'], [$colin, '/tickets/' . $W['t_jo2']], [$marco, '/inbox'], [$marco, '/tickets/' . $W['t_jo']]] as [$j, $p]) {
    foreach ([page($j, $p)['body'], page($j, $p, ['headers' => JSONH])['body']] as $i => $body) {
        $l = leaks($body);
        if ($j === $marco && $p === '/tickets/' . $W['t_jo']) { $l = array_diff($l, ['jo@customer.invalid']); }     // the queue holder's requester card
        if ($j === $ext) { $l = array_diff($l, ['ext@customer.invalid']); }                                        // their own address in the header's account menu
        if ($l !== []) { $bad[] = $p . ($i ? ' (json)' : '') . ': ' . implode(', ', $l); }
    }
}
ok($bad === [], 'no page or JSON leaks an external\'s address outside the queue holders\' card, and never a secure token' . ($bad ? ' — ' . implode('; ', $bad) : ''));
ok(count(q("SELECT 1 FROM activity_log WHERE after::text ~ '/t/[a-f0-9]{48}' OR after::text ~ '[a-f0-9]{48}'")) === 0, 'no activity row carries a token');
finish();
