<?php
declare(strict_types=1);
/** /my-tickets — the tickets I asked for, filed, am cc'd on or follow (screen `my-tickets`; params: state = open | waiting | solved | closed | all). */
require_once dirname(__DIR__) . '/app/features/tickets/handler.php';
require_login();
require_human();
require_right('tickets.view_own');
$pdo = db();
$me = (int) current_member_id();
$state = in_array(request_string('state'), ['open', 'waiting', 'solved', 'closed', 'all'], true) ? request_string('state') : 'open';
$cats = ['open' => ['new', 'open', 'on_hold'], 'waiting' => ['pending', 'solved'], 'solved' => ['solved'], 'closed' => ['closed'], 'all' => []][$state];
$page = max(1, request_integer('page') ?? 1);
$st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE t.ticket_id IN (SELECT hd_own_ticket_ids())' . ($cats === [] ? '' : ' AND t.status_category = ANY (CAST(:cats AS text[]))') . ' ORDER BY t.last_activity_at DESC, t.ticket_id DESC LIMIT 51 OFFSET ' . (($page - 1) * 50));
$st->execute($cats === [] ? [] : ['cats' => pg_array_literal($cats)]);
$rows = array_map('ticket_cast', $st->fetchAll());
$more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);
log_screen_view($pdo, 'my-tickets');
if (wants_json()) {
    respond_screen(['state' => $state, 'page' => $page, 'more' => $more, 'tickets' => array_map('present_ticket_brief', $rows), 'can_create' => has_right('tickets.create')]);
}
$data = ['state' => $state, 'canCreate' => has_right('tickets.create'), 'rows' => $rows, 'page' => $page, 'more' => $more, 'query' => ['state' => $state], 'here' => here_url(), 'tz' => member_timezone()];
if (is_htmx_request() && ($_SERVER['HTTP_HX_TARGET'] ?? '') === 'ticket-list') {
    header('Vary: HX-Request');
    echo view('tickets/partials/ticket-list.php', $data + ['base' => '/my-tickets']);
    exit;
}
render_screen('My tickets', view('my-tickets/page.php', $data), ['activeNav' => 'my-tickets', 'screen' => 'my-tickets', 'entity' => 'ticket']);
