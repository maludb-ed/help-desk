<?php
declare(strict_types=1);
/** /settings/automation/{id}/runs — what a rule did (screen `rule-runs`; settings.manage or tickets.view_queue): the last 30 days, 50 a page. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('settings.manage') && !has_right('tickets.view_queue')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('rule') ?? refuse(404, 'Rule not found.');
$r = find_rule($pdo, $id) ?? refuse(404, 'Rule not found.');
$page = max(1, request_integer('page') ?? 1);
$rows = find_rule_runs($pdo, $id, 30, $page);
$more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);
log_screen_view($pdo, 'rule-runs');
if (wants_json()) {
    respond_screen(['rule' => present_rule($r), 'page' => $page, 'more' => $more, 'runs' => array_map('present_rule_run', $rows)]);
}
render_screen($r['name'] . ' — runs', view('automation/runs.php', ['r' => $r, 'rows' => $rows, 'page' => $page, 'more' => $more, 'tz' => member_timezone(), 'here' => here_url()]), ['activeNav' => 'automation', 'screen' => 'rule-runs', 'entity' => 'automation_rule', 'recordId' => (string) $id]);
