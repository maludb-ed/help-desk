<?php
declare(strict_types=1);
/** / — the dashboard (screen `dashboard`): what needs me (support), my open tickets, New request, the knowledge-base search. */
require_once dirname(__DIR__) . '/app/features/tickets/handler.php';
require_login();
require_human();
$pdo = db();
$me = (int) current_member_id();
log_screen_view($pdo, 'dashboard');
$data = ['needsMe' => has_right('tickets.view_queue') ? home_needs_me($pdo, $me) : [], 'myTickets' => home_my_tickets($pdo, $me),
         'canCreate' => has_right('tickets.create'), 'canQueue' => has_right('tickets.view_queue'), 'canKb' => has_right('kb.read'), 'tz' => member_timezone()];
if (wants_json()) {
    respond_screen(['needs_me' => array_map(static fn (array $t): array => present_ticket_brief($t) + ['reason' => $t['reason']], $data['needsMe']), 'my_tickets' => array_map('present_ticket_brief', $data['myTickets']),
        'can_create' => $data['canCreate'], 'sees_queues' => $data['canQueue'], 'knowledge_base' => $data['canKb']]);
}
render_screen('Home', view('home/dashboard.php', $data), ['activeNav' => 'home', 'screen' => 'dashboard']);
