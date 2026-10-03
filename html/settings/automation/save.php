<?php
declare(strict_types=1);
/** Actions `rule_create` / `rule_update` (by the presence of `rule`; log `rule.create` / `rule.update`: name, kind, event, conditions, actions, sort_order, active; an agent pauses — other): settings.manage. conditions/actions as JSON (the tab, an agent) win over the rows; rule_validate() names what is wrong. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rule') ?? request_integer('rule_id');
$cur = $id === null ? null : (find_rule($pdo, $id) ?? refuse(404, 'Rule not found.'));
$f = ['name' => req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? ''), 'kind' => $cur['kind'] ?? (string) req_val('kind'), 'event' => req_has('event') ? (string) req_val('event') : ($cur['event'] ?? null),
      'sort_order' => 100, 'active' => hd_yes('active', $cur['active'] ?? true), 'conditions' => $cur['conditions'] ?? [], 'actions' => $cur['actions'] ?? []];
$errors = [];
$f['sort_order'] = hd_int('sort_order', $cur['sort_order'] ?? 100, 0, 1000, 'The order', $errors);
$rawC = $_POST['conditions'] ?? null;
if (is_string($rawC) && trim($rawC) !== '') {
    $c = json_decode($rawC, true);
    if (!is_array($c)) {
        $errors['conditions'] = 'conditions is not valid JSON.';
    } else {
        $f['conditions'] = $c;
    }
} elseif (is_array($rawC)) {
    $f['conditions'] = $c = $rawC;
} elseif (isset($_POST['conditions_rows']) && is_array($_POST['conditions_rows'])) {
    $f['conditions'] = rule_conditions_from_rows($_POST['conditions_rows']);
}
$rawA = $_POST['actions'] ?? null;
if (is_string($rawA) && trim($rawA) !== '') {
    $a = json_decode($rawA, true);
    if (!is_array($a)) {
        $errors['actions'] = 'actions is not valid JSON.';
    } else {
        $f['actions'] = $a;
    }
} elseif (is_array($rawA)) {
    $f['actions'] = $rawA;
} elseif (isset($_POST['actions_rows']) && is_array($_POST['actions_rows'])) {
    $f['actions'] = rule_actions_from_rows($_POST['actions_rows']);
}
$errors += rule_validate($pdo, $f);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f, $me): int {
    $pdo->beginTransaction();
    $newId = save_rule($pdo, $id, $f, $me);
    $after = rule_log_state(find_rule($pdo, $newId, true));
    if ($cur === null) {
        log_activity($pdo, 'rule.create', 'automation_rule', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(rule_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'rule.update', 'automation_rule', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the rule ' : 'Saved the rule ') . $f['name'], $newId, hd_land(return_path('/settings/automation/' . $newId . '/edit'), 'saved'), 'ruleChanged', ['rule_id' => $newId, 'kind' => $f['kind']]);
