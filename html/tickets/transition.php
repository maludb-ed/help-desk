<?php
declare(strict_types=1);
/**
 * Action `ticket_transition` (log `ticket.transition`: before/after status, category, resolution_code, hold_reason, pending_until, via): work. The table says what is allowed and needed;
 * the refusals are sentences: "HD-1042 cannot move from Closed." · "Solving needs a resolution code." · "Putting a ticket on hold needs a reason." · "Closing early needs the Lead role."
 * `assignee=me` with the move (Accept); `note` adds an internal note first (Decline needs one).
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$statusParam = (string) req_val('status');
$to = filter_var($statusParam, FILTER_VALIDATE_INT) !== false ? status_by_id($pdo, (int) $statusParam) : status_by_key($pdo, $statusParam);
if ($to === null) {
    hd_refuse_fields(['status' => 'Say which status (a key like open, pending, solved).']);
}
$errors = [];
$carry = ['resolution_code' => (string) req_val('resolution_code'), 'hold_reason' => (string) req_val('hold_reason'), 'pending_until' => (string) req_val('pending_until')];
$assignee = (string) req_val('assignee');
if ($assignee === 'me') {
    $carry['assignee_member_id'] = $me;
} elseif ($assignee !== '' && filter_var($assignee, FILTER_VALIDATE_INT) !== false) {
    $carry['assignee_member_id'] = (int) $assignee;
}
$note = (string) req_val('note');
if ($t['status_category'] === 'new' && $to['category'] === 'solved' && $carry['resolution_code'] === 'no_action' && $note === '') {
    hd_refuse_fields(['note' => 'Say why in a note.']);
}
$moved = hd_guard($pdo, static function () use ($pdo, $me, $t, $to, $carry, $note): array {
    $pdo->beginTransaction();
    if ($note !== '') {
        $mid = add_message($pdo, $t['ticket_id'], 'internal_note', $note, ['author_member_id' => $me]);
        ticket_log($pdo, $t, 'ticket.note', ['after' => ['message_id' => $mid, 'kind' => 'internal_note', 'excerpt' => excerpt($note), 'attachments' => 0]]);
    }
    $moved = transition_ticket($pdo, $t['ticket_id'], $to['status_id'], $carry, $me, $t['status_category'] === 'new' ? 'triage' : 'screen');
    if (isset($carry['assignee_member_id']) && $carry['assignee_member_id'] !== $t['assignee_member_id']) {
        $a = assign_ticket($pdo, $t['ticket_id'], $carry['assignee_member_id'], null, $me, 'triage');
        ticket_log($pdo, $t, 'ticket.assign', ['before' => $a['before'], 'after' => $a['after']]);
    }
    ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
    if (in_array($to['category'], ['solved', 'closed'], true)) {
        notify($pdo, ['requester_id' => $t['requester_id']], 'replied', $t['ticket_id'], $t['number'] . ' is ' . strtolower($to['label']), 'Your request ' . $t['number'] . ' "' . $t['subject'] . '" was marked ' . strtolower($to['label']) . '. If it is not, reopen it or reply. ' . requester_link_words($t, null), null, 'solved:' . $t['ticket_id'] . ':' . time());
    }
    $pdo->commit();
    return $moved;
});
automation_run_triggers($pdo, 'ticket.update', $t['ticket_id']);
ticket_done($t, $t['number'] . ': ' . $moved['label'] . ' — now ' . $to['label'], ['status' => $to['key'], 'category' => $to['category']], 'saved');
