<?php
/** Proof — JSON mode (spec "Proof", 8): every action of the slice under action tokens answers the contract. */
require __DIR__ . '/lib.php';
$W = world();
$owner = ['X-Action-Token: ' . person_token(1)]; $marco = ['X-Action-Token: ' . person_token(27)];
[$c, $b] = act_token('/settings/mailboxes/save.php', ['address' => 'json@example.invalid', 'display_name' => 'JSON box', 'queue' => $W['it'], 'webhook_secret_ref' => 'MAILBOX_SUPPORT_WEBHOOK_SECRET', 'poll_enabled' => 'no'], $owner);
$m = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $m > 0 && ($b['location'] ?? '') === '/settings/mailboxes/' . $m . '/edit?notice=saved' && ($b['secret_set'] ?? false) === true, 'mailbox_create: {ok, record_id, location, secret_set}');
[$c, $b] = act_token('/settings/mailboxes/save.php', ['mailbox' => $m, 'display_name' => 'JSON box 2'], $owner);
ok($c === 200 && (string) one('SELECT display_name FROM mailboxes WHERE id = :m', ['m' => $m]) === 'JSON box 2' && (string) one('SELECT address FROM mailboxes WHERE id = :m', ['m' => $m]) === 'json@example.invalid', 'mailbox_update keeps what it was not sent');
[$c, $b] = act_token('/settings/mailboxes/poll.php', ['mailbox' => $m], $owner);
ok($c === 200 && ($b['handled'] ?? -1) === 0, 'mailbox_poll_now: {ok, handled}');
[$c, $b] = act_token('/settings/mailboxes/archive.php', ['mailbox' => $m], $owner);
ok($c === 200 && ($b['ok'] ?? false), 'mailbox_archive: {ok}');
[$c, $b] = act_token('/settings/mailboxes/archive.php', ['mailbox' => $m], $owner);
ok($c === 422, 'again: 422');
[$c, $b] = act_token('/settings/mailboxes/inbound/retry.php', ['inbound' => 999999], $owner);
ok($c === 404, 'inbound_retry on nothing: 404');
[$c, $b] = act_token('/settings/mailboxes/save.php', ['address' => 'x@example.invalid', 'display_name' => 'x', 'queue' => $W['it']], $marco);
ok($c === 403, 'mailbox_create as support: 403');
[$c, $b] = act_token('/settings/notifications/read.php', [], $marco);
ok($c === 200 && ($b['ok'] ?? false) && isset($b['count']), 'notification_read: {ok, count}');
[$c, $b] = act_token('/settings/prefs.php', ['text_enabled' => 'no', 'kinds' => 'assigned,replied'], $marco);
ok($c === 200 && ($b['ok'] ?? false) && ($b['text_enabled'] ?? null) === false, 'prefs_save: {ok, email_enabled, text_enabled}');
$r = req('GET', '/settings/mailboxes/', ['headers' => array_merge(JSONH, $owner)]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['mailboxes']) && !str_contains($r['body'], need('MAILBOX_SUPPORT_WEBHOOK_SECRET')), 'the list answers JSON without a secret');
$r = req('GET', '/settings/mailboxes/inbound?status=failed', ['headers' => array_merge(JSONH, $owner)]);
ok($r['code'] === 200 && isset(json_decode($r['body'], true)['data']['inbound']), 'the inbound list answers JSON');
finish();
