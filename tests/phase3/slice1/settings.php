<?php
/** Proof — the business's settings and the agents screen: settings_save logs only the changed keys, "Nothing changed" logs nothing; the prefix numbers new tickets; the agents screen lists the Frontline agent with its queues. */
require __DIR__ . '/lib.php';
$owner = as_member(1);

echo "1. settings_save\n";
[$c, $d] = screen($owner, '/settings/general');
ok($c === 200 && $d['settings']['ticket_prefix'] === 'HD' && $d['settings']['default_queue']['name'] === 'General', 'the screen answers the seeded settings (HD, General)');
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/general.php', ['ticket_prefix' => 'sup', 'auto_close_days' => '7', 'request_form_enabled' => 'no']);
ok($c === 200 && ($b['changed'] ?? []) === ['ticket_prefix', 'auto_close_days', 'request_form_enabled'], 'three keys changed (the prefix upper-cased)');
$log = activity('settings.save', $since);
ok(count($log) === 1 && json_decode((string) $log[0]['before'], true) === ['ticket_prefix' => 'HD', 'auto_close_days' => 4, 'request_form_enabled' => true] && json_decode((string) $log[0]['after'], true) === ['ticket_prefix' => 'SUP', 'auto_close_days' => 7, 'request_form_enabled' => false], 'settings.save logs before/after of the changed keys only');
$t = mkticket(requester_of_member(26), general_queue(), 'SMOKE numbered');
ok(str_starts_with((string) one('SELECT number FROM tickets WHERE id = :t', ['t' => $t]), 'SUP-'), 'a new ticket is numbered with the new prefix');
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/general.php', ['auto_close_days' => '7']);
ok($c === 200 && ($b['did'] ?? '') === 'Nothing changed' && activity('settings.save', $since) === [], '"Nothing changed" logs nothing');
[$c, $b] = act($owner, '/settings/general.php', ['ticket_prefix' => 'toolongprefix']);
ok($c === 422 && isset(fields($b)['ticket_prefix']), 'a bad prefix: 422');
[$c, $b] = act($owner, '/settings/general.php', ['default_queue' => 999]);
ok($c === 422 && isset(fields($b)['default_queue']), 'an unknown default queue: 422');
[$c, $b] = act($owner, '/settings/general.php', ['secure_link_days' => '0']);
ok($c === 422, 'secure-link days of 0: 422');
[$c, $b] = act($owner, '/settings/general.php', ['ticket_prefix' => 'HD', 'auto_close_days' => '4', 'request_form_enabled' => 'yes']);
ok($c === 200, 'restored');

echo "2. Agents\n";
[$c, $d] = screen($owner, '/settings/agents');
$fl = array_values(array_filter($d['agents'], fn ($a) => $a['member_id'] === 40))[0] ?? null;
ok($c === 200 && $fl !== null && $fl['name'] === 'SMOKE Frontline' && $fl['last_dispatch'] === null, 'the Frontline agent is listed, never dispatched');
q('INSERT INTO queue_members (queue_id, member_id) VALUES (:q, 40)', ['q' => general_queue()]);
[$c, $d] = screen($owner, '/settings/agents');
$fl = array_values(array_filter($d['agents'], fn ($a) => $a['member_id'] === 40))[0];
ok(count($fl['queues']) === 1 && $fl['queues'][0]['name'] === 'General', 'named in General: the card says so');
$body = page($owner, '/settings/agents')['body'];
ok(str_contains($body, 'id="agent-card-40"') && str_contains($body, 'Hiring, grants and duties live in the OS'), 'the screen: the card and the note');

echo "3. My settings carries the presence card for support staff and the admin's link\n";
$marco = as_member(27); $priya = as_member(26);
ok(str_contains(page($marco, '/settings/')['body'], 'id="presence-form"') && !str_contains(page($priya, '/settings/')['body'], 'id="presence-form"'), 'Marco (tickets.work) sees My availability; Priya does not');
ok(str_contains(page($owner, '/settings/')['body'], 'id="settings-tab-general"') && !str_contains(page($marco, '/settings/')['body'], 'id="settings-tab-general"'), 'the Help Desk settings link is the admin\'s');
$since = last_activity_id();
[$c, $b] = act($marco, '/settings/queues/presence.php', ['availability' => 'away', 'capacity' => '5']);
ok($c === 200 && q('SELECT availability, capacity FROM queue_presence WHERE member_id = 27')[0] == ['availability' => 'away', 'capacity' => 5] && count(activity('queue.presence', $since)) === 1, 'queue_presence_set: away, capacity 5, logged');
[$c, $b] = act($marco, '/settings/queues/presence.php', ['capacity' => '']);
ok($c === 200 && one('SELECT capacity FROM queue_presence WHERE member_id = 27') === null && (string) one('SELECT availability FROM queue_presence WHERE member_id = 27') === 'away', 'an empty capacity clears it; availability kept');
[$c, $b] = act($marco, '/settings/queues/presence.php', ['availability' => 'busy']);
ok($c === 422, 'an unknown availability: 422');
finish();
