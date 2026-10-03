<?php
declare(strict_types=1);
/** /settings/automation/ — the rules as cards (screen `rules-list`; settings.manage changes, tickets.view_queue reads): Triggers · Time-based · Assignment. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('settings.manage') && !has_right('tickets.view_queue')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
require_human();
$pdo = db();
$rules = find_rules($pdo);
$groups = [];
foreach (['trigger' => 'Triggers', 'time_based' => 'Time-based', 'assignment' => 'Assignment'] as $k => $label) {
    $groups[] = [$k, $label, array_values(array_filter($rules, static fn (array $r): bool => $r['kind'] === $k))];
}
log_screen_view($pdo, 'rules-list');
if (wants_json()) {
    respond_screen(['rules' => array_map('present_rule', $rules), 'may' => ['manage' => has_right('settings.manage')]]);
}
render_screen('Automation', view('automation/list.php', ['groups' => $groups, 'may' => has_right('settings.manage'), 'notice' => hd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'deleted' => ['success', 'The rule was deleted.'], 'ran' => ['success', 'Ran.']])]),
    ['activeNav' => 'automation', 'screen' => 'rules-list', 'entity' => 'automation_rule']);
