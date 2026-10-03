<?php
/** Proof — organizations (spec "Proof", 4): a duplicate domain → 422 naming the owner; a domain claims a new external at hd_requester_for_email(); the card's counts follow; archive refused while open tickets exist. */
require __DIR__ . '/lib.php';
$marco = as_member(27); $lena = as_member(28);

echo "1. Create, with domains\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/organizations/save.php', ['name' => 'SMOKE Globex', 'support_tier' => 'Silver', 'domains' => "globex.invalid\nGLOBEX.example", 'tags' => 'vip, retail', 'members_see_each_other' => 'yes', 'notes' => 'SMOKE org note']);
$org = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $org > 0 && ($b['location'] ?? '') === '/organizations/' . $org . '?notice=saved', "organization_create: 200, record_id $org");
ok(q('SELECT domain::text AS d FROM organization_domains WHERE organization_id = :o ORDER BY domain', ['o' => $org]) === [['d' => 'globex.example'], ['d' => 'globex.invalid']], 'both domains stored, lower-cased');
ok(count(activity('organization.create', $since)) === 1 && !str_contains((string) activity('organization.create', $since)[0]['after'], 'SMOKE org note') && str_contains((string) activity('organization.create', $since)[0]['after'], '"has_notes": true'), 'organization.create logged; the notes\' text never');
[$c, $b] = act($marco, '/organizations/save.php', ['name' => 'SMOKE Other', 'domains' => 'globex.invalid']);
ok($c === 422 && msg($b) === 'globex.invalid already belongs to SMOKE Globex.', 'a duplicate domain: 422 "globex.invalid already belongs to SMOKE Globex."');
[$c, $b] = act($marco, '/organizations/save.php', ['name' => 'SMOKE Globex']);
ok($c === 422 && str_contains(msg($b), 'already exists'), 'a duplicate name: 422');
[$c, $b] = act($marco, '/organizations/save.php', ['name' => 'SMOKE Bad', 'domains' => 'not a domain']);
ok($c === 422 && isset(fields($b)['domains']), 'a malformed domain: 422 (field domains)');

echo "2. A domain claims a new external\n";
$ext = external('ann@globex.invalid', 'SMOKE Ann');
ok((int) one('SELECT organization_id FROM requesters WHERE id = :r', ['r' => $ext]) === $org, 'hd_requester_for_email() for ann@globex.invalid lands her in SMOKE Globex');
[$c, $d] = screen($marco, '/organizations/');
$card = array_values(array_filter($d['organizations'], fn ($o) => $o['organization_id'] === $org))[0];
ok($card['people_count'] === 1 && $card['open_count'] === 0, 'the card counts 1 person, 0 open');
$t = mkticket($ext, general_queue(), 'SMOKE Globex ticket');
[$c, $d] = screen($marco, '/organizations/' . $org);
ok($d['organization']['open_count'] === 1 && $d['organization']['ticket_count'] === 1 && count($d['people']) === 1 && $d['people'][0]['requester_id'] === $ext, 'after a ticket: open 1, ticket 1, Ann among the people');
$body = page($marco, '/organizations/' . $org)['body'];
ok(str_contains($body, 'id="requester-card-' . $ext . '"') && str_contains($body, 'id="organization-view-domain-globex-invalid"') && str_contains($body, 'id="organization-view-see"'), 'the page shows her card, the domain chips and the facts');
[$c, $d] = screen($marco, '/organizations/?q=globex.ex');
ok(count($d['organizations']) === 1 && $d['organizations'][0]['organization_id'] === $org, 'search by domain finds it');
[$c, $d] = screen($marco, '/organizations/?tier=Silver');
ok(count(array_filter($d['organizations'], fn ($o) => $o['organization_id'] === $org)) === 1, 'the tier filter finds it');

echo "3. Update keeps what it is not sent; the domain forms\n";
$since = last_activity_id();
[$c, $b] = act($marco, '/organizations/save.php', ['organization' => $org, 'support_tier' => 'Gold']);
ok($c === 200 && (string) one('SELECT support_tier FROM organizations WHERE id = :o', ['o' => $org]) === 'Gold' && (string) one('SELECT name FROM organizations WHERE id = :o', ['o' => $org]) === 'SMOKE Globex' && (int) one('SELECT count(*) FROM organization_domains WHERE organization_id = :o', ['o' => $org]) === 2, 'one field changed; the name and the domains kept');
$log = activity('organization.update', $since);
ok(count($log) === 1 && json_decode((string) $log[0]['after'], true) === ['support_tier' => 'Gold'], 'organization.update logs the one changed field');
[$c, $b] = act($marco, '/organizations/save.php', ['organization' => $org, 'domains' => ['globex.invalid', 'globex.example', 'globex.test', '']]);
ok($c === 200 && (int) one('SELECT count(*) FROM organization_domains WHERE organization_id = :o', ['o' => $org]) === 3 && str_ends_with((string) $b['location'], 'notice=domains'), 'the inline domain form adds a third (notice domains)');
[$c, $b] = act($marco, '/organizations/save.php', ['organization' => $org, 'domains' => ['globex.invalid', '']]);
ok($c === 200 && q('SELECT domain::text AS d FROM organization_domains WHERE organization_id = :o', ['o' => $org]) === [['d' => 'globex.invalid']], 'the chip form removes the others (the set is replaced whole)');
[$c, $b] = act($marco, '/organizations/save.php', ['organization' => 999999, 'name' => 'x']);
ok($c === 404, 'an unknown organization: 404');

echo "4. Archive\n";
[$c, $b] = act($lena, '/organizations/archive.php', ['organization' => $org]);
ok($c === 422 && msg($b) === 'Solve or move its 1 open ticket first.', 'archive with an open ticket: 422 "Solve or move its 1 open ticket first."');
q("UPDATE tickets SET status_id = (SELECT id FROM ticket_statuses WHERE category = 'solved' ORDER BY sort_order LIMIT 1) WHERE id = :t", ['t' => $t]);
$since = last_activity_id();
[$c, $b] = act($lena, '/organizations/archive.php', ['organization' => $org]);
ok($c === 200 && !(bool) one('SELECT active FROM organizations WHERE id = :o', ['o' => $org]) && count(activity('organization.archive', $since)) === 1, 'solved: archived, logged');
ok(page($marco, '/organizations/' . $org)['code'] === 404 && !str_contains(page($marco, '/organizations/')['body'], 'organization-card-' . $org), 'it is gone from the list and its page');
ok((int) one('SELECT organization_id FROM requesters WHERE id = :r', ['r' => $ext]) === $org, 'Ann still belongs to it (nothing deleted)');
finish();
