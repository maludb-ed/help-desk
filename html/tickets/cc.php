<?php
declare(strict_types=1);
/** Action `ticket_cc_add` (log `ticket.cc_add`): work, or the requester on their own ticket; cc = an email (a new one makes the external) or a requester id. Queues `cc_added`. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
if (!can_work_ticket($t['ticket_id']) && !is_own_ticket($pdo, $t)) {
    refuse(403, 'You are not in this ticket\'s queue.');
}
$cc = (string) req_val('cc');
$rid = null;
if (filter_var($cc, FILTER_VALIDATE_INT) !== false && find_requester($pdo, (int) $cc) !== null) {
    $rid = (int) $cc;
} elseif (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $cc)) {
    $rid = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e)', ['e' => strtolower($cc)]);
} else {
    hd_refuse_fields(['cc' => 'A CC is an email address or a requester.']);
}
if ($rid === $t['requester_id']) {
    refuse(422, 'That is the requester.');
}
$row = $pdo->prepare('SELECT member_id, display_name FROM requesters WHERE id = :r'); $row->execute(['r' => $rid]); $rr = $row->fetch();
hd_guard($pdo, static function () use ($pdo, $me, $t, $rid, $rr): void {
    $pdo->beginTransaction();
    add_follower($pdo, $t['ticket_id'], 'cc', $rr['member_id'] === null ? null : (int) $rr['member_id'], $rr['member_id'] === null ? $rid : null, $me);
    ticket_log($pdo, $t, 'ticket.cc_add', ['after' => ['requester_id' => $rid]]);
    notify($pdo, ['requester_id' => $rid], 'cc_added', $t['ticket_id'], 'You were copied on ' . $t['number'], 'You were added to ' . $t['number'] . ' "' . $t['subject'] . '". Replies reach you by email.', null, 'cc:' . $t['ticket_id'] . ':' . $rid);
    $pdo->commit();
});
ticket_done($t, 'Copied ' . $rr['display_name'] . ' on ' . $t['number'], ['requester_id' => $rid], 'saved');
