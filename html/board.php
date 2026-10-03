<?php
declare(strict_types=1);
/** /board — a saved view as columns (screen `board`; tickets.view_queue): ?view= an id or a system key (default team_board), a list view opened here renders as a status board; ?swimlane=, ?assignee=, ?priority[]=, ?q= on top through the one translator; ?expand=&to= shows a card's required field. */
require_once dirname(__DIR__) . '/app/features/tickets/handler.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$me = (int) current_member_id();
$param = request_string('view');
$view = ($param === '' ? null : view_from_param($pdo, $param)) ?? ($param === '' ? find_view_by_key($pdo, 'team_board') : null) ?? refuse(404, 'View not found.');
if ($view['layout'] !== 'board') {
    $view['board'] = board_settings([]);
}
$swim = request_string('swimlane');
if ($swim === 'none') {
    $view['board']['swimlane'] = null;
} elseif ($swim !== '' && isset(BOARD_SWIMLANES[$swim])) {
    $view['board']['swimlane'] = $swim;
}
$extra = array_intersect_key(view_filters_from_request($_GET), ['assignee' => 1, 'priority' => 1, 'text' => 1]);
$filters = ['q' => (string) ($extra['text'] ?? ''), 'assignee' => isset($extra['assignee']) ? (string) $extra['assignee'] : '', 'priority' => $extra['priority'] ?? [], 'swimlane' => $swim === 'none' ? '' : ($view['board']['swimlane'] ?? '')];
$board = board_columns($pdo, $view, $extra, $me);
$moves = board_move_table($pdo, $board['column_field'], $board['columns']);
$expand = null;
$expandTicket = request_integer('expand');
if ($expandTicket !== null && $board['column_field'] === 'status' && can_see_ticket($expandTicket)) {
    $cur = ticket_facts($pdo, $expandTicket);
    $to = status_by_key($pdo, request_string('to'));
    if ($cur !== null && $to !== null) {
        foreach ($moves[$cur['status_id']] ?? [] as $m) {
            if ($m['status_id'] === $to['status_id'] && $m['requires'] !== []) {
                $expand = ['ticket' => $expandTicket, 'to' => $to, 'requires' => $m['requires'], 'label' => $m['move_label']];
            }
        }
    }
}
$viewParam = $view['system_key'] ?? (string) $view['view_id'];
$query = array_filter(['view' => $viewParam, 'swimlane' => $swim !== '' ? $swim : null, 'assignee' => $filters['assignee'], 'priority' => $filters['priority'], 'q' => $filters['q']], static fn ($v) => $v !== null && $v !== '' && $v !== []);
if (!wants_json() || ($_SERVER['HTTP_X_SCREEN_VIEW'] ?? '') === '1') {
    log_activity($pdo, 'screen.view', 'saved_view', $view['view_id'], ['screen' => 'board', 'after' => ['view_id' => $view['view_id']]]);
}
if (wants_json()) {
    respond_screen(['view' => present_view($view), 'filters' => $query, 'board' => present_board($board)]);
}
$data = ['view' => $view, 'board' => $board, 'filters' => $filters, 'assignees' => find_assignable_members($pdo), 'moves' => $moves, 'expand' => $expand, 'query' => $query, 'here' => here_url(), 'tz' => member_timezone(), 'capped' => $board['capped'],
         'notice' => hd_notice($_GET['notice'] ?? null, ['moved' => ['success', 'The card was moved.'], 'saved' => ['success', 'Saved.']])];
render_screen($view['name'] . ' · Board', view('board/board.php', $data), ['activeNav' => 'board', 'screen' => 'board', 'entity' => 'saved_view', 'recordId' => (string) $view['view_id']]);
