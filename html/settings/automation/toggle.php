<?php
declare(strict_types=1);
/** Action `rule_toggle` (log `rule.toggle`: active; an agent pauses — other): settings.manage. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rule') ?? request_integer('rule_id') ?? refuse(422, 'Say which rule.');
$cur = find_rule($pdo, $id) ?? refuse(404, 'Rule not found.');
$active = hd_yes('active', !$cur['active']);
hd_guard($pdo, static function () use ($pdo, $cur, $active, $me): void {
    $pdo->beginTransaction();
    toggle_rule($pdo, $cur['rule_id'], $active, $me);
    if ($active !== $cur['active']) {
        log_activity($pdo, 'rule.toggle', 'automation_rule', $cur['rule_id'], ['before' => ['active' => $cur['active']], 'after' => ['active' => $active]]);
    }
    $pdo->commit();
});
hd_done(($active ? 'Turned on ' : 'Turned off ') . $cur['name'], $cur['rule_id'], hd_land('/settings/automation/', 'saved', 'rule-card-' . $cur['rule_id']), 'ruleChanged', ['rule_id' => $cur['rule_id'], 'active' => $active]);
