<?php
/** Proof — rights (spec "Proof", 1): a requester-only member → 403 on every screen here with the right's sentence; support sees organizations and requesters with emails; a collaborator sees notes (the view's rule). */
require __DIR__ . '/lib.php';
$priya = as_member(26); $colin = as_member(29); $marco = as_member(27); $lena = as_member(28); $owner = as_member(1);

echo "1. A requester-only member is refused everywhere here, in the right's sentence\n";
$screens = ['/settings/queues/' => "change Help Desk's settings", '/settings/queues/new' => "change Help Desk's settings", '/organizations/' => "see this queue's tickets", '/organizations/new' => "see this queue's tickets",
    '/requesters/' => "see this queue's tickets", '/requesters/new' => "see this queue's tickets", '/settings/sla/' => "change Help Desk's settings", '/settings/sla/new' => "change Help Desk's settings",
    '/settings/sla/hours' => "change Help Desk's settings", '/settings/general' => "change Help Desk's settings", '/settings/agents' => "change Help Desk's settings"];
$wrong = [];
foreach ($screens as $path => $words) {
    $r = page($priya, $path);
    if ($r['code'] !== 403 || !str_contains(html_entity_decode($r['body'], ENT_QUOTES), 'You may not ' . $words)) { $wrong[] = "$path {$r['code']}"; }
}
ok($wrong === [], count($screens) . ' screens → 403 for Priya with the right\'s sentence' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
$writes = ['/settings/queues/save.php' => ['name' => 'x'], '/settings/queues/archive.php' => ['queue' => 1], '/organizations/save.php' => ['name' => 'x'], '/requesters/save.php' => ['display_name' => 'x', 'email' => 'x@example.invalid'],
    '/requesters/merge.php' => ['requester' => 1, 'into' => 2], '/settings/sla/save.php' => ['name' => 'x'], '/settings/sla/hours.php' => ['name' => 'x'], '/settings/general.php' => ['ticket_prefix' => 'X']];
$wrong = [];
foreach ($writes as $path => $form) { [$c, $b] = act($priya, $path, $form); if ($c !== 403) { $wrong[] = "$path $c"; } }
ok($wrong === [], count($writes) . ' writes → 403 for Priya' . ($wrong ? ' — wrong: ' . implode('; ', $wrong) : ''));
[$c, $b] = act($priya, '/settings/queues/member.php', ['queue' => general_queue(), 'member' => 26]);
ok($c === 403 && msg($b) === 'You may not change who is in a queue.', 'queue_member_add for a requester: 403 "You may not change who is in a queue."');
[$c, $b] = act($priya, '/settings/queues/presence.php', ['availability' => 'away']);
ok($c === 200 && (string) one('SELECT availability FROM queue_presence WHERE member_id = 26') === 'away', 'queue_presence_set is anyone\'s own: Priya may set hers (200)');

echo "2. Support staff and a collaborator see organizations and requesters with emails and notes (the view's rule: tickets.view_queue)\n";
$org = (int) one("INSERT INTO organizations (name, support_tier, notes) VALUES ('SMOKE Acme', 'Gold', 'SMOKE a note') RETURNING id");
q("INSERT INTO organization_domains (domain, organization_id) VALUES ('acme.invalid', :o)", ['o' => $org]);
$ext = external('jo@acme.invalid', 'SMOKE Jo');
q("UPDATE requesters SET notes = 'SMOKE jo note', phone = '+1 555 0100' WHERE id = :r", ['r' => $ext]);
[$c, $d] = screen($marco, '/requesters/');
$jo = array_values(array_filter($d['requesters'] ?? [], fn ($x) => $x['requester_id'] === $ext))[0] ?? null;
ok($c === 200 && $jo !== null && $jo['email'] === 'jo@acme.invalid' && $jo['phone'] === '+1 555 0100', 'Marco (support) lists Jo with her email and phone');
[$c, $d] = screen($colin, '/requesters/' . $ext);
ok($c === 200 && $d['requester']['email'] === 'jo@acme.invalid' && $d['requester']['notes'] === 'SMOKE jo note', 'Colin (collaborator, tickets.view_queue) sees her email and the notes too, as db/015 says');
[$c, $d] = screen($colin, '/organizations/' . $org);
ok($c === 200 && $d['organization']['notes'] === 'SMOKE a note' && $d['organization']['domains'] === ['acme.invalid'], 'and the organization with its notes and domain');
ok(page($colin, '/organizations/' . $org . '/edit')['code'] === 200 && page($colin, '/requesters/' . $ext . '/edit')['code'] === 200, 'a collaborator may edit them (tickets.view_queue)');
[$c, $b] = act($colin, '/organizations/archive.php', ['organization' => $org]);
ok($c === 403 && str_contains(msg($b), 'merge, close early or bulk-update tickets'), 'but not archive (tickets.manage): 403');
[$c, $b] = act($marco, '/requesters/merge.php', ['requester' => $ext, 'into' => $ext]);
ok($c === 403, 'nor merge (tickets.manage): 403 for support staff');
ok(page($lena, '/settings/queues/')['code'] === 200 && page($lena, '/settings/queues/new')['code'] === 403, 'a lead reaches the queues list (queues.members) but not the add form (settings.manage)');
ok(page($lena, '/settings/sla/')['code'] === 403 && page($owner, '/settings/sla/')['code'] === 200, 'SLA and hours are the admin\'s');
finish();
