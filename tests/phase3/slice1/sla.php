<?php
/** Proof — SLA and hours (spec "Proof", 6): the targets grid round-trips to jsonb; the preview equals hd_business_add_minutes(); a default schedule with no open day → 422; Standard cannot be archived; move up/down. */
require __DIR__ . '/lib.php';
$owner = as_member(1);
$std = (int) one("SELECT id FROM sla_policies WHERE name = 'Standard'");
$office = (int) one("SELECT id FROM business_hours WHERE is_default");

echo "1. A policy from the grid\n";
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/sla/save.php', ['name' => 'SMOKE Gold', 'sort_order' => 10, 'warning_percent' => 75, 'schedule' => $office, 'priorities' => ['urgent', 'high'], 'queues' => [general_queue()], 'tags' => 'vip',
    'targets' => ['urgent' => ['first_response' => '15', 'next_response' => '30', 'resolution' => '240'], 'high' => ['first_response' => '60', 'next_response' => '', 'resolution' => '480'], 'normal' => ['first_response' => '', 'next_response' => '', 'resolution' => ''], 'low' => []]]);
$pid = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $pid > 0 && str_ends_with((string) $b['location'], '#policy-card-' . $pid), "sla_policy_create: 200, record_id $pid, lands on its card");
$row = q('SELECT conditions, targets FROM sla_policies WHERE id = :p', ['p' => $pid])[0];
ok(json_decode($row['targets'], true) == ['urgent' => ['first_response' => 15, 'next_response' => 30, 'resolution' => 240], 'high' => ['first_response' => 60, 'resolution' => 480]], 'the grid round-trips to targets jsonb (blanks dropped)');
ok(json_decode($row['conditions'], true) == ['queue_ids' => [general_queue()], 'priorities' => ['urgent', 'high'], 'tags' => ['vip']], 'the matches round-trip to conditions jsonb');
[$c, $d] = screen($owner, '/settings/sla/');
$card = array_values(array_filter($d['policies'], fn ($p) => $p['policy_id'] === $pid))[0];
ok($card['matches'] === 'any organization · General · urgent and high · tagged vip' && $d['policies'][0]['policy_id'] === $pid, 'the card reads "any organization · General · urgent and high · tagged vip" and comes first (sort 10)');
ok(count(activity('sla_policy.create', $since)) === 1, 'sla_policy.create logged');
[$c, $b] = act($owner, '/settings/sla/save.php', ['name' => 'SMOKE JSON', 'targets' => json_encode(['normal' => ['resolution' => 1440]])]);
ok($c === 200 && json_decode((string) one('SELECT targets FROM sla_policies WHERE id = :p', ['p' => (int) $b['record_id']]), true) == ['normal' => ['resolution' => 1440]], 'an agent\'s JSON targets land the same way');
$jid = (int) $b['record_id'];
[$c, $b] = act($owner, '/settings/sla/save.php', ['name' => 'SMOKE Bad', 'targets' => ['urgent' => ['first_response' => '-5']]]);
ok($c === 422 && isset(fields($b)['targets']), 'a negative target: 422');
[$c, $b] = act($owner, '/settings/sla/save.php', ['name' => 'SMOKE Bad', 'priorities' => ['extreme']]);
ok($c === 422 && isset(fields($b)['priorities']), 'an unknown priority: 422');
[$c, $b] = act($owner, '/settings/sla/save.php', ['name' => 'SMOKE Bad', 'warning_percent' => '100']);
ok($c === 422 && isset(fields($b)['warning_percent']), 'a warning percent of 100: 422');
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/sla/save.php', ['policy' => $pid, 'warning_percent' => 90]);
ok($c === 200 && json_decode((string) activity('sla_policy.update', $since)[0]['after'], true) === ['warning_percent' => 90] && json_decode((string) one('SELECT targets FROM sla_policies WHERE id = :p', ['p' => $pid]), true)['urgent']['resolution'] === 240, 'sla_policy_update with one field: the targets kept, the log holds the one change');

echo "2. Move up and down, archive\n";
[$c, $b] = act($owner, '/settings/sla/save.php', ['policy' => $pid, 'move' => 'down']);
[$c2, $d] = screen($owner, '/settings/sla/');
ok($c === 200 && $d['policies'][0]['policy_id'] !== $pid && $d['policies'][1]['policy_id'] === $pid, 'Move down: the policy is second');
[$c, $b] = act($owner, '/settings/sla/save.php', ['policy' => $pid, 'move' => 'up']);
[$c2, $d] = screen($owner, '/settings/sla/');
ok($c === 200 && $d['policies'][0]['policy_id'] === $pid, 'Move up: first again');
[$c, $b] = act($owner, '/settings/sla/archive.php', ['policy' => $std]);
ok($c === 422 && msg($b) === 'Standard applies when nothing else matches.', 'archiving Standard: 422 "Standard applies when nothing else matches."');
[$c, $b] = act($owner, '/settings/sla/archive.php', ['policy' => $jid]);
ok($c === 200 && !(bool) one('SELECT active FROM sla_policies WHERE id = :p', ['p' => $jid]) && count(array_filter(screen($owner, '/settings/sla/')[1]['policies'], fn ($p) => $p['policy_id'] === $jid)) === 0, 'another policy archives and leaves the list');
ok(!str_contains(page($owner, '/settings/sla/')['body'], 'id="policy-card-' . $std . '-archive-btn"'), 'the Standard card has no Archive button');

echo "3. Schedules and the preview\n";
[$c, $d] = screen($owner, '/settings/sla/');
$sched = array_values(array_filter($d['schedules'], fn ($s) => $s['schedule_id'] === $office))[0];
ok($sched['summary'] === 'Mon–Fri 09:00–17:00' && $sched['is_default'], 'the seeded Office hours read "Mon–Fri 09:00–17:00", default');
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/sla/hours.php', ['schedule' => $office, 'name' => 'Office hours', 'timezone' => 'America/New_York', 'is_default' => 'yes',
    'intervals' => [1 => [['opens' => '09:00', 'closes' => '12:00'], ['opens' => '13:00', 'closes' => '17:30']], 2 => [['opens' => '09:00', 'closes' => '17:30']], 3 => [['opens' => '09:00', 'closes' => '17:30']], 4 => [['opens' => '09:00', 'closes' => '17:30']], 5 => [['opens' => '09:00', 'closes' => '16:00']], 6 => [['opens' => '', 'closes' => '']], 0 => []]]);
ok($c === 200 && (int) one('SELECT count(*) FROM business_hours_intervals WHERE schedule_id = :s', ['s' => $office]) === 6 && (string) one('SELECT timezone FROM business_hours WHERE id = :s', ['s' => $office]) === 'America/New_York', 'business_hours_save from the grid: six intervals, the zone');
$log = activity('business_hours.save', $since);
ok(count($log) === 1 && str_contains((string) $log[0]['after'], 'America/New_York') && str_contains((string) $log[0]['before'], '"UTC"'), 'business_hours.save logs before/after');
[$c, $d] = screen($owner, '/settings/sla/');
ok(array_values(array_filter($d['schedules'], fn ($s) => $s['schedule_id'] === $office))[0]['summary'] === 'Mon 09:00–12:00, 13:00–17:30 · Tue–Thu 09:00–17:30 · Fri 09:00–16:00', 'the summary reads the split day and the runs');
$r = req('GET', '/settings/sla/hours?preview=1&schedule=' . $office . '&from=2026-10-09T16:30&minutes=60', ['jar' => $owner, 'headers' => JSONH]);   // a Friday
$sentence = json_decode($r['body'], true)['data']['sentence'] ?? '';
$expected = (string) one("SELECT to_char(hd_business_add_minutes(CAST('2026-10-09 16:30 America/New_York' AS timestamptz), 60, :s) AT TIME ZONE 'America/New_York', 'FMDay HH24:MI')", ['s' => $office]);
ok($sentence === '60 minutes from Friday 16:30 lands ' . $expected . ' (Oct 12)' && str_starts_with($expected, 'Monday 10:'), "the preview equals hd_business_add_minutes(): \"$sentence\"");
$r = req('GET', '/settings/sla/hours?preview=1&schedule=' . $office . '&from=2026-10-09T16:30&minutes=60', ['jar' => $owner, 'headers' => ['HX-Request: true']]);
ok($r['code'] === 200 && str_starts_with(trim($r['body']), '60 minutes from Friday 16:30 lands'), 'the HTMX preview answers the sentence alone');
[$c, $b] = act($owner, '/settings/sla/hours.php', ['name' => 'SMOKE Never', 'timezone' => 'UTC', 'is_default' => 'yes', 'intervals' => '[]']);
ok($c === 422 && isset(fields($b)['is_default']), 'a schedule with no open day marked default: 422');
[$c, $b] = act($owner, '/settings/sla/hours.php', ['name' => 'SMOKE Weekend', 'timezone' => 'UTC', 'intervals' => json_encode([['weekday' => 6, 'opens' => '10:00', 'closes' => '14:00'], ['weekday' => 0, 'opens' => '10:00', 'closes' => '14:00']])]);
$wk = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $wk > 0 && (int) one('SELECT count(*) FROM business_hours_intervals WHERE schedule_id = :s', ['s' => $wk]) === 2, 'a new schedule from an agent\'s JSON intervals');
[$c, $b] = act($owner, '/settings/sla/hours.php', ['name' => 'SMOKE Bad', 'timezone' => 'UTC', 'intervals' => json_encode([['weekday' => 1, 'opens' => '17:00', 'closes' => '09:00']])]);
ok($c === 422 && isset(fields($b)['intervals']), 'closes before opens: 422');
[$c, $b] = act($owner, '/settings/sla/hours.php', ['schedule' => $office, 'is_default' => 'no']);
ok($c === 422 && msg($b) === 'Mark another schedule as the default first.', 'un-defaulting the only default: 422');

echo "4. Holidays\n";
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/sla/holiday.php', ['schedule' => $office, 'holiday' => '2026-10-12', 'name' => 'SMOKE day']);
ok($c === 200 && one('SELECT name FROM business_hours_holidays WHERE schedule_id = :s AND holiday = :d', ['s' => $office, 'd' => '2026-10-12']) === 'SMOKE day' && count(activity('business_hours.holiday_add', $since)) === 1, 'holiday_add: stored and logged');
$r = req('GET', '/settings/sla/hours?preview=1&schedule=' . $office . '&from=2026-10-09T16:30&minutes=60', ['jar' => $owner, 'headers' => JSONH]);
ok(str_contains(json_decode($r['body'], true)['data']['sentence'], 'lands Tuesday'), 'the preview skips the holiday: Friday 16:30 + 60 now lands Tuesday');
[$c, $b] = act($owner, '/settings/sla/holiday.php', ['schedule' => $office, 'holiday' => 'not-a-date']);
ok($c === 422, 'a bad date: 422');
[$c, $b] = act($owner, '/settings/sla/holiday-remove.php', ['schedule' => $office, 'holiday' => '2026-10-12']);
ok($c === 200 && one('SELECT 1 FROM business_hours_holidays WHERE schedule_id = :s AND holiday = :d', ['s' => $office, 'd' => '2026-10-12']) === false && count(activity('business_hours.holiday_remove', $since)) === 1, 'holiday_remove: gone and logged');
[$c, $b] = act($owner, '/settings/sla/holiday-remove.php', ['schedule' => $office, 'holiday' => '2026-10-12']);
ok($c === 404, 'removing it again: 404');
finish();
