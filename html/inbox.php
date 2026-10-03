<?php
declare(strict_types=1);
/** /inbox — the tickets of my queues (screen `inbox`; tickets.view_queue): the seeded views as tabs (?view=), ad-hoc filters on top, 50 a page, #ticket-list swapped alone. */
require_once dirname(__DIR__) . '/app/features/tickets/handler.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$me = (int) current_member_id();
$views = find_system_views($pdo);
$viewKey = request_string('view');
$view = view_from_param($pdo, $viewKey);                                                   // slice 4: an id or a system key; a view I may not see is none
if ($view !== null && $view['system_key'] === 'team_board') {
    $view = null;
}
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'status' => isset(STATUS_CATEGORIES[request_string('status')]) ? request_string('status') : '', 'queue' => request_integer('queue'),
            'assignee' => request_string('assignee'), 'priority' => isset(PRIORITY_COLORS[request_string('priority')]) ? request_string('priority') : '', 'sort' => isset(TICKET_SORTS[request_string('sort')]) ? request_string('sort') : 'last_activity'];
$adhoc = view_filters_from_request($_GET);                                                 // slice 4: the one translator reads the inbox's keys
$f = array_merge($view['filters'] ?? [], $adhoc);
if ($view !== null && request_string('sort') === '') {
    $f['sort_by'] = $view['sort_by'];
    $f['sort_dir'] = $view['sort_dir'];
} else {
    $f['sort'] = $filters['sort'];
}
$page = max(1, request_integer('page') ?? 1);
$rows = find_tickets_filtered($pdo, $f, $me, 51, ($page - 1) * 50);
$more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);
$query = array_filter(['view' => $view === null ? null : ($view['system_key'] ?? (string) $view['view_id']), 'q' => $filters['q'], 'status' => $filters['status'], 'queue' => $filters['queue'], 'assignee' => $filters['assignee'], 'priority' => $filters['priority'], 'sort' => $filters['sort'] === 'last_activity' ? null : $filters['sort']], static fn ($v) => $v !== null && $v !== '');
log_screen_view($pdo, 'inbox');
if (wants_json()) {
    respond_screen(['view' => $view['system_key'] ?? ($view['view_id'] ?? null), 'view_name' => $view['name'] ?? null, 'filters' => $query, 'effective_filters' => (object) $f, 'page' => $page, 'more' => $more, 'tickets' => array_map('present_ticket_brief', $rows),
        'views' => array_map(static fn (array $v): array => ['system_key' => $v['system_key'], 'name' => $v['name'], 'layout' => $v['layout']], $views)]);
}
$data = ['views' => $views, 'view' => $view['system_key'] ?? '', 'saved' => $view, 'filters' => $filters, 'adhoc' => $adhoc, 'queues' => find_queues($pdo, ['mine' => !has_right('tickets.view_all')]), 'assignees' => find_assignable_members($pdo),
         'rows' => $rows, 'page' => $page, 'more' => $more, 'query' => $query, 'here' => here_url(), 'tz' => member_timezone(), 'bulk' => has_right('tickets.manage'), 'notice' => hd_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The ticket was deleted.']])];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'ticket-list') {
    header('Vary: HX-Request');
    echo view('tickets/partials/ticket-list.php', $data + ['base' => '/inbox', 'bulk' => has_right('tickets.manage')]);
    exit;
}
render_screen('Inbox', view('tickets/inbox.php', $data), ['activeNav' => 'inbox', 'screen' => 'inbox', 'entity' => 'ticket']);
