<?php
/** Proof — JSON mode (spec "Proof", 7): survey_send, rating_submit and csat_settings_save answer the contract under action tokens. */
require __DIR__ . '/lib.php';
$W = world();
$marco = ['X-Action-Token: ' . person_token(27)]; $priya = ['X-Action-Token: ' . person_token(26)]; $owner = ['X-Action-Token: ' . person_token(1)];
$t = (int) act_token('/tickets/save.php', ['subject' => 'SMOKE json rated', 'body' => 'x'], $priya)[1]['record_id'];
q('UPDATE tickets SET queue_id = :q WHERE id = :t', ['q' => $W['it'], 't' => $t]);
act_token('/tickets/reply.php', ['ticket' => $t, 'body' => 'Done.', 'then_status' => 'solved', 'resolution_code' => 'resolved'], $marco);
[$c, $b] = act_token('/ratings/save.php', ['ticket' => $t, 'score' => 9], $priya);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset(fields($b)['score']), 'rating_submit with a score of 9: 422 by field');
[$c, $b] = act_token('/ratings/save.php', ['ticket' => $t, 'score' => 5], $priya);
ok($c === 200 && ($b['ok'] ?? false) && ($b['score'] ?? 0) === 5 && ($b['record_id'] ?? 0) === $t && str_starts_with((string) $b['location'], '/tickets/' . $t), 'rating_submit: {ok, did, record_id, score, location}');
$t2 = (int) act_token('/tickets/save.php', ['subject' => 'SMOKE json survey', 'body' => 'x', 'queue' => $W['it'], 'requester' => $W['jo']], $marco)[1]['record_id'];
[$c, $b] = act_token('/ratings/send.php', ['ticket' => $t2], $marco);
ok($c === 422 && msg($b) === ticket_row($t2)['number'] . ' is not solved yet.', 'survey_send on an unsolved ticket: 422 in the sentence');
act_token('/tickets/transition.php', ['ticket' => $t2, 'status' => 'open'], $marco);
act_token('/tickets/transition.php', ['ticket' => $t2, 'status' => 'solved', 'resolution_code' => 'resolved'], $marco);
[$c, $b] = act_token('/ratings/send.php', ['ticket' => $t2], $marco);
ok($c === 200 && ($b['ok'] ?? false) && isset($b['rating_id']) && ($b['location'] ?? '') === '/tickets/' . $t2 . '?notice=saved', 'survey_send: {ok, did, rating_id, location}');
[$c, $b] = act_token('/settings/csat.php', ['response_window_days' => 21], $owner);
ok($c === 200 && ($b['ok'] ?? false) && ($b['changed'] ?? []) === ['response_window_days'], 'csat_settings_save: {ok, did, changed}');
[$c, $b] = act_token('/settings/csat.php', ['response_window_days' => 21], $owner);
ok($c === 200 && ($b['did'] ?? '') === 'Nothing changed' && ($b['changed'] ?? null) === [], 'again: Nothing changed');
[$c, $b] = act_token('/settings/csat.php', ['response_window_days' => 14], $marco);
ok($c === 403, 'as support: 403');
act_token('/settings/csat.php', ['response_window_days' => 14], $owner);
$r = req('GET', '/settings/csat', ['headers' => array_merge(JSONH, $owner)]);
ok($r['code'] === 200 && (json_decode($r['body'], true)['data']['settings']['response_window_days'] ?? 0) === 14, 'the settings screen answers JSON');
finish();
