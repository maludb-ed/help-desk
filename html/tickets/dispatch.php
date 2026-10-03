<?php
declare(strict_types=1);
/** Action `ticket_dispatch` (log `ticket.dispatch`): work; the assignee must be an agent ("HD-1042 is not assigned to an agent."); one turn of the agent through the kernel, repeated by hand. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$a = $t['assignee_member_id'] === null ? null : find_member_by_id($pdo, $t['assignee_member_id']);
if ($a === null || $a['member_kind'] !== 'agent') {
    refuse(422, $t['number'] . ' is not assigned to an agent.');
}
$d = dispatch_to_agent($pdo, $t['ticket_id'], (int) $a['id'], 'assigned', is_action_authed() && current_agent_run_id() !== null ? null : $me);
ticket_log($pdo, $t, 'ticket.dispatch', ['after' => ['agent_member_id' => (int) $a['id'], 'agent_name' => $a['display_name'], 'via' => $d['via'], 'run_id' => $d['run_id'], 'status' => $d['status']]]);
ticket_done($t, 'Handed ' . $t['number'] . ' to ' . $a['display_name'] . ' — ' . $d['status'] . ($d['detail'] ? ' (' . $d['detail'] . ')' : ''), ['dispatch' => ['status' => $d['status'], 'via' => $d['via'], 'run_id' => $d['run_id'], 'reply_excerpt' => $d['reply_excerpt']]], 'saved');
