<?php
/** Proof — JSON mode (spec "Proof", 8): rule_create / rule_update / rule_toggle / rule_run_now / rule_delete under action tokens answer the contract; validation 422 by field. */
require __DIR__ . '/lib.php';
$W = world();
$owner = ['X-Action-Token: ' . person_token(1)]; $marco = ['X-Action-Token: ' . person_token(27)];
[$c, $b] = act_token('/settings/automation/save.php', ['name' => 'SMOKE json rule', 'kind' => 'time_based', 'conditions' => '{"all":[{"field":"status_category","op":"eq","value":"new"},{"field":"subject_contains","op":"contains","value":"zzz-never-matches"}]}', 'actions' => '[{"add_tags":["json"]}]'], $owner);
$r = (int) ($b['record_id'] ?? 0);
ok($c === 200 && $r > 0 && ($b['kind'] ?? '') === 'time_based' && ($b['location'] ?? '') === '/settings/automation/' . $r . '/edit?notice=saved', 'rule_create: {ok, record_id, kind, location} (' . $c . ' ' . json_encode($b) . ')');
[$c, $b] = act_token('/settings/automation/save.php', ['rule' => $r, 'name' => 'SMOKE json rule 2'], $owner);
ok($c === 200 && (string) one('SELECT name FROM automation_rules WHERE id = :r', ['r' => $r]) === 'SMOKE json rule 2' && json_decode((string) one('SELECT actions FROM automation_rules WHERE id = :r', ['r' => $r]), true) == [['add_tags' => ['json']]], 'rule_update keeps what it was not sent');
[$c, $b] = act_token('/settings/automation/save.php', ['rule' => $r, 'kind' => 'trigger'], $owner);
ok($c === 200 && (string) one('SELECT kind FROM automation_rules WHERE id = :r', ['r' => $r]) === 'time_based', 'the kind is fixed once saved');
[$c, $b] = act_token('/settings/automation/save.php', ['name' => '', 'kind' => 'nope'], $owner);
ok($c === 422 && ($b['error']['code'] ?? '') === 'invalid' && isset(fields($b)['name'], fields($b)['kind']), 'validation: 422 by field');
[$c, $b] = act_token('/settings/automation/run.php', ['rule' => $r], $owner);
ok($c === 200 && ($b['ok'] ?? false) && isset($b['applied'], $b['skipped']) && str_starts_with((string) $b['did'], "Ran 'SMOKE json rule 2' on"), 'rule_run_now: {ok, did, applied, skipped}');
[$c, $b] = act_token('/settings/automation/toggle.php', ['rule' => $r, 'active' => 'no'], $owner);
ok($c === 200 && ($b['active'] ?? true) === false, 'rule_toggle: {ok, active}');
[$c, $b] = act_token('/settings/automation/run.php', ['rule' => $r], $marco);
ok($c === 403, 'as support: 403');
[$c, $b] = act_token('/settings/automation/delete.php', ['rule' => $r], $owner);
ok($c === 200 && ($b['ok'] ?? false) && one('SELECT 1 FROM automation_rules WHERE id = :r', ['r' => $r]) === false, 'rule_delete: {ok}');
$g = req('GET', '/settings/automation/', ['headers' => array_merge(JSONH, $owner)]);
ok($g['code'] === 200 && count(json_decode($g['body'], true)['data']['rules'] ?? []) >= 2, 'the list answers JSON');
finish();
