<?php
declare(strict_types=1);
/** /views/new and /views/{id}/edit (screens `view-add`, `view-edit`; views.personal or views.shared): ?from= carries a query string of filters (the inbox's, the board's) as the starting point, ?view= a view to copy, ?layout=. A standard view is read-only with "Save a copy". */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/queries.php';
require_any_right('views.personal|views.shared');
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id');
$cur = $id === null ? null : (find_view($pdo, $id) ?? refuse(404, 'View not found.'));
if ($cur !== null && !$cur['is_system'] && !view_may_change($cur)) {
    refuse(403, $cur['scope'] === 'personal' ? 'That view is not yours.' : 'You may not change shared views.');
}
$start = $cur;
if ($cur === null) {
    $from = view_from_param($pdo, request_string('view'));
    parse_str(request_string('from'), $fromGet);
    $filters = view_filters_from_request(is_array($fromGet) ? $fromGet : []);
    $start = ['name' => $from !== null ? 'Copy of ' . $from['name'] : '', 'scope' => 'personal', 'queue_id' => null, 'filters' => $filters !== [] ? $filters : ($from['filters'] ?? []),
              'sort_by' => $from['sort_by'] ?? 'last_activity_at', 'sort_dir' => $from['sort_dir'] ?? 'desc', 'columns' => $from['columns'] ?? ['number', 'subject', 'requester', 'status', 'priority', 'assignee', 'updated'],
              'layout' => isset(VIEW_LAYOUTS[request_string('layout')]) ? request_string('layout') : ($from['layout'] ?? 'list'), 'board' => $from['board'] ?? board_settings([])];
}
$cats = [];
$walk = static function (array $nodes) use (&$walk, &$cats): void {
    foreach ($nodes as $n) {
        $cats[] = $n;
        $walk($n['children'] ?? []);
    }
};
$walk(find_category_tree($pdo));
log_screen_view($pdo, $cur === null ? 'view-add' : 'view-edit');
if (wants_json()) {
    respond_screen(['view' => $cur === null ? null : present_view($cur), 'start' => ['filters' => (object) $start['filters'], 'layout' => $start['layout']], 'filter_keys' => VIEW_FILTER_KEYS, 'sorts' => array_keys(VIEW_SORTS), 'columns' => VIEW_COLUMNS]);
}
render_screen($cur === null ? 'Save a view' : 'Change ' . $cur['name'], view('views/form.php', ['cur' => $cur, 'start' => $start, 'readOnly' => $cur !== null && $cur['is_system'], 'statuses' => find_statuses($pdo),
    'queues' => find_queues($pdo, ['mine' => true]), 'assignees' => find_assignable_members($pdo), 'types' => find_types($pdo), 'categories' => $cats, 'organizations' => find_organizations($pdo), 'here' => here_url()]),
    ['activeNav' => 'views', 'screen' => $cur === null ? 'view-add' : 'view-edit', 'entity' => 'saved_view', 'recordId' => (string) ($id ?? '')]);
