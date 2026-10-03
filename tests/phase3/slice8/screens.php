<?php
/** Proof — rights and the screens (spec "Proof", 8): support → 403 "You may not change Help Desk's settings." on the form and every handler; view_queue reads the list; the admin's list shows the seeded rules Built in; the form's rows save a rule; the runs page. */
require __DIR__ . '/lib.php';
$W = world();
$owner = as_member(1); $marco = as_member(27); $priya = as_member(26);
echo "1. Rights\n";
$ml = page($marco, '/settings/automation/');
ok($ml['code'] === 200 && !str_contains($ml['body'], 'id="rules-new-trigger"') && !preg_match('/id="rule-card-\d+-toggle"/', $ml['body']), 'support reads the list without the buttons (' . $ml['code'] . ')');
ok(page($priya, '/settings/automation/')['code'] === 403, 'a requester: 403');
$r = page($marco, '/settings/automation/new');
ok($r['code'] === 403 && str_contains(html_entity_decode($r['body'], ENT_QUOTES), "You may not change Help Desk's settings."), 'support on the form: 403 in the sentence');
foreach (['save.php' => ['name' => 'x', 'kind' => 'trigger'], 'toggle.php' => ['rule' => 1], 'delete.php' => ['rule' => 1], 'run.php' => ['rule' => 1]] as $h => $form) {
    [$c, $b] = act($marco, '/settings/automation/' . $h, $form);
    ok($c === 403 && msg($b) === "You may not change Help Desk's settings.", $h . ' as support: 403 in the sentence');
}
echo "2. The list and the form\n";
$body = page($owner, '/settings/automation/')['body'];
ok(str_contains($body, 'id="rules-group-time_based"') && str_contains($body, 'Built in') && str_contains($body, 'id="rule-card-1-toggle"') && str_contains($body, 'id="rule-card-1-run"') && str_contains($body, 'id="rules-group-trigger"'), 'the seeded rules show Built in with a switch and Run now');
$since = last_activity_id();
[$c, $b] = act($owner, '/settings/automation/save.php', ['name' => 'SMOKE from rows', 'kind' => 'trigger', 'event' => 'ticket.create', 'sort_order' => 30, 'active' => 'yes',
    'conditions_rows' => [['field' => 'priority', 'op' => 'eq', 'value' => 'urgent, high'], ['field' => 'hours_since', 'op' => 'gte', 'value' => '2', 'args' => 'created_at'], ['field' => '', 'op' => 'eq', 'value' => '']],
    'actions_rows' => [['do' => 'set', 'with' => 'priority=urgent'], ['do' => 'notify', 'with' => 'queue_leads escalated Look at this'], ['do' => '', 'with' => '']]]);
$rid = (int) ($b['record_id'] ?? 0);
$row = q('SELECT * FROM automation_rules WHERE id = :r', ['r' => $rid])[0] ?? null;
ok($c === 200 && $row !== null && json_decode($row['conditions'], true) == ['all' => [['field' => 'priority', 'op' => 'in', 'value' => ['urgent', 'high']], ['field' => 'hours_since', 'op' => 'gte', 'value' => 2, 'args' => 'created_at']]] && json_decode($row['actions'], true) == [['set' => ['priority' => 'urgent']], ['notify' => ['who' => 'queue_leads', 'kind' => 'escalated', 'text' => 'Look at this']]], 'the rows became the documents (a list from "a, b", the empty row dropped)');
ok(count(activity('rule.create', $since)) === 1 && ($b['location'] ?? '') === '/settings/automation/' . $rid . '/edit?notice=saved', 'rule.create logged; lands on the edit page');
$body = page($owner, '/settings/automation/' . $rid . '/edit')['body'];
ok(str_contains($body, 'id="rule-form"') && str_contains($body, 'value="urgent, high"') && str_contains($body, 'value="priority=urgent"') && str_contains($body, 'id="rule-form-preview"'), 'the edit form shows the rows and "Would match N"');
[$c, $b] = act($owner, '/settings/automation/save.php', ['rule' => $rid, 'actions' => json_encode([['transition' => 'parked']])]);
ok($c === 422 && (fields($b)['actions'] ?? '') === "No status 'parked'.", 'a missing status on save: 422 "No status \'parked\'."');
[$c, $b] = act($owner, '/settings/automation/save.php', ['rule' => $rid, 'conditions' => json_encode(['all' => [['field' => 'colour', 'op' => 'eq', 'value' => 'red']]])]);
ok($c === 422 && str_contains(fields($b)['conditions'] ?? '', "'colour' is not a condition field."), 'an unknown field: 422 by name');
[$c, $b] = act($owner, '/settings/automation/toggle.php', ['rule' => $rid, 'active' => 'no']);
ok($c === 200 && (bool) one('SELECT active FROM automation_rules WHERE id = :r', ['r' => $rid]) === false, 'toggled off');
echo "3. The runs page\n";
$close = (int) one("SELECT id FROM automation_rules WHERE name = 'Close solved tickets after the window'");
$body = page($owner, '/settings/automation/' . $close . '/runs')['body'];
ok(str_contains($body, 'id="rule-runs-table"') && str_contains($body, 'id="rule-runs-words"'), 'the runs ledger renders');
ok(page($marco, '/settings/automation/' . $close . '/runs')['code'] === 200, 'support may read it');
[$c, $b] = act($owner, '/settings/automation/delete.php', ['rule' => $rid]);
ok($c === 200 && one('SELECT 1 FROM automation_rules WHERE id = :r', ['r' => $rid]) === false, 'deleted');
finish();
