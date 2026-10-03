<?php
declare(strict_types=1);
/** /settings/automation/new?kind= and /settings/automation/{id}/edit (screens `rule-add`, `rule-edit`; settings.manage): rows over the bounded vocabulary, a JSON tab, "Would match N" (?preview=1 with the rule's own document: nothing saved). */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('settings.manage')) {
    refuse(403, 'You may not change Help Desk\'s settings.');
}
require_human();
$pdo = db();
$id = request_integer('id');
$cur = $id === null ? null : (find_rule($pdo, $id) ?? refuse(404, 'Rule not found.'));
$kind = $cur['kind'] ?? (isset(RULE_KINDS[request_string('kind')]) ? request_string('kind') : 'trigger');
$lists = ['statuses' => array_map(static fn (array $s): array => [$s['key'], $s['label']], find_statuses($pdo)), 'queues' => array_map(static fn (array $q): array => [$q['queue_id'], $q['name']], find_queues($pdo)),
          'categories' => array_map(static fn (array $c): array => [$c['category_id'], $c['name']], find_category_tree($pdo)), 'types' => array_map(static fn (array $t): array => [$t['type_id'], $t['label']], find_types($pdo)),
          'organizations' => array_map(static fn (array $o): array => [$o['organization_id'], $o['name']], find_organizations($pdo)), 'tags' => array_map(static fn (array $g): array => [$g['slug'], $g['label'] ?? $g['slug']], find_tags($pdo))];
$preview = $cur !== null && in_array($cur['kind'], ['time_based', 'trigger'], true) ? rule_preview_count($pdo, $cur) : null;
log_screen_view($pdo, $cur === null ? 'rule-add' : 'rule-edit');
if (wants_json()) {
    respond_screen(['rule' => $cur === null ? null : present_rule($cur), 'kind' => $kind, 'vocabulary' => rule_vocabulary(), 'events' => RULE_EVENTS, 'preview' => $preview]);
}
render_screen($cur === null ? 'New rule' : 'Change ' . $cur['name'], view('automation/form.php', ['cur' => $cur, 'kind' => $kind, 'voc' => rule_vocabulary(), 'lists' => $lists, 'preview' => $preview, 'here' => here_url()]),
    ['activeNav' => 'automation', 'screen' => $cur === null ? 'rule-add' : 'rule-edit', 'entity' => 'automation_rule', 'recordId' => (string) ($id ?? '')]);
