<?php
declare(strict_types=1);
/**
 * Action `ticket_assign` (log `ticket.assign`: before/after assignee with names and kind, before/after queue, via): work; a queue move needs work in the target queue too or tickets.manage;
 * an assignee must be in the (new) queue — "Dana is not in the IT help queue." An agent assignee is dispatched AFTER commit (the assignment never waits for the dispatch); a person gets the `assigned` notice.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$queue = request_integer('queue') ?? request_integer('queue_id');
if ($queue !== null && $queue !== $t['queue_id'] && !has_right('tickets.manage') && one_value($pdo, "SELECT 1 FROM hd_queue_ids_with_right('tickets.work') q WHERE q = :q", ['q' => $queue]) === false) {
    refuse(403, 'You may not move a ticket into that queue.');
}
$given = req_has('assignee') || req_has('assignee_member_id');
$a = (string) (req_val('assignee') ?? req_val('assignee_member_id'));
$assignee = null;
if ($a === 'me') {
    $assignee = $me;
} elseif ($a !== '' && $a !== 'none' && $a !== 'nobody') {
    if (filter_var($a, FILTER_VALIDATE_INT) === false) {
        hd_refuse_fields(['assignee' => 'The assignee is a person, an agent, me or none.']);
    }
    $assignee = (int) $a;
}
$note = (string) req_val('note');
$r = hd_guard($pdo, static function () use ($pdo, $me, $t, $assignee, $queue, $given, $note): array {
    $pdo->beginTransaction();
    $r = assign_ticket($pdo, $t['ticket_id'], $assignee, $queue, $me, 'manual', $given);
    if ($note !== '') {
        $mid = add_message($pdo, $t['ticket_id'], 'internal_note', $note, ['author_member_id' => $me]);
        ticket_log($pdo, $t, 'ticket.note', ['after' => ['message_id' => $mid, 'kind' => 'internal_note', 'excerpt' => excerpt($note), 'attachments' => 0]]);
    }
    if ($r['changed']) {
        ticket_log($pdo, $t, 'ticket.assign', ['queue_id' => $r['after']['queue_id'], 'before' => $r['before'], 'after' => $r['after']]);
        if ($r['after']['assignee_member_id'] !== null && $r['after']['assignee_member_id'] !== $r['before']['assignee_member_id'] && $r['after']['assignee_kind'] !== 'agent' && $r['after']['assignee_member_id'] !== $me) {
            notify($pdo, ['member_id' => $r['after']['assignee_member_id']], 'assigned', $t['ticket_id'], $t['number'] . ' is yours: ' . $t['subject'], $t['number'] . ' "' . $t['subject'] . '" was assigned to you. See it here: ' . app_url('/tickets/' . $t['ticket_id']), null, 'assigned:' . $t['ticket_id'] . ':' . $r['after']['assignee_member_id'] . ':' . time());
        }
    }
    $pdo->commit();
    return $r;
});
$dispatch = null;
if ($r['changed'] && $r['after']['assignee_kind'] === 'agent' && $r['after']['assignee_member_id'] !== $r['before']['assignee_member_id']) {
    $dispatch = dispatch_to_agent($pdo, $t['ticket_id'], $r['after']['assignee_member_id'], 'assigned', is_action_authed() && current_agent_run_id() !== null ? null : $me);
    ticket_log($pdo, $t, 'ticket.dispatch', ['queue_id' => $r['after']['queue_id'], 'after' => ['agent_member_id' => $r['after']['assignee_member_id'], 'agent_name' => $r['after']['assignee_name'], 'via' => $dispatch['via'], 'run_id' => $dispatch['run_id'], 'status' => $dispatch['status']]]);
}
$did = !$r['changed'] ? 'Nothing changed on ' . $t['number'] : ($r['after']['assignee_member_id'] === null ? 'Unassigned ' . $t['number'] : 'Assigned ' . $t['number'] . ' to ' . $r['after']['assignee_name']) . ($r['after']['queue_id'] !== $r['before']['queue_id'] ? ' in ' . $r['after']['queue_name'] : '');
ticket_done($t, $did, ['assignee_member_id' => $r['after']['assignee_member_id'], 'queue_id' => $r['after']['queue_id'], 'dispatch' => $dispatch === null ? null : ['status' => $dispatch['status'], 'via' => $dispatch['via'], 'run_id' => $dispatch['run_id']]], 'saved');
