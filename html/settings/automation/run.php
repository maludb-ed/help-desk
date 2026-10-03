<?php
declare(strict_types=1);
/** Action `rule_run_now` (log `rule.run_now`: applied, skipped; confirm; an agent pauses — other): settings.manage; a time-based rule run once now against its candidates. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/automation/worker_steps.php';
hd_handler_begin();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
$pdo = db();
$id = request_integer('rule') ?? request_integer('rule_id') ?? refuse(422, 'Say which rule.');
$cur = find_rule($pdo, $id) ?? refuse(404, 'Rule not found.');
if ($cur['kind'] !== 'time_based') {
    refuse(422, 'Only a time-based rule runs on demand.');
}
$GLOBALS['__rule_depth'] = 1;                                                                 // a run now never fires triggers
$r = rules_pass($pdo, date('c'), $cur['rule_id'])[$cur['rule_id']] ?? ['applied' => 0, 'skipped' => 0, 'error' => 0];
$GLOBALS['__rule_depth'] = 0;
log_activity($pdo, 'rule.run_now', 'automation_rule', $cur['rule_id'], ['after' => $r]);
hd_done("Ran '" . $cur['name'] . "' on " . ($r['applied'] + $r['skipped'] + $r['error']) . ' tickets (' . $r['applied'] . ' applied, ' . $r['skipped'] . ' skipped' . ($r['error'] ? ', ' . $r['error'] . ' errors' : '') . ').', $cur['rule_id'], hd_land('/settings/automation/', 'ran'), 'ruleChanged', ['rule_id' => $cur['rule_id']] + $r);
