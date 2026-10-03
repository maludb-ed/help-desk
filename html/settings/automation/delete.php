<?php
declare(strict_types=1);
/** Action `rule_delete` (log `rule.delete`; confirm; an agent pauses — deletion): settings.manage. Its runs go with it; its rule.fire rows stay. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('rule') ?? request_integer('rule_id') ?? refuse(422, 'Say which rule.');
$cur = find_rule($pdo, $id) ?? refuse(404, 'Rule not found.');
hd_guard($pdo, static function () use ($pdo, $cur, $me): void {
    $pdo->beginTransaction();
    log_activity($pdo, 'rule.delete', 'automation_rule', $cur['rule_id'], ['before' => rule_log_state($cur)]);
    delete_rule($pdo, $cur['rule_id'], $me);
    $pdo->commit();
});
hd_done('Deleted the rule ' . $cur['name'], $cur['rule_id'], hd_land('/settings/automation/', 'deleted'), 'ruleChanged', ['rule_id' => $cur['rule_id']]);
