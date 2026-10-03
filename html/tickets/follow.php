<?php
declare(strict_types=1);
/** Action `ticket_follow` (log `ticket.follow`): a member follows a ticket they can see; naming another member needs tickets.note in the queue. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
$member = request_integer('member') ?? request_integer('member_id') ?? $me;
if ($member !== $me && !can_note_ticket($pdo, $t)) {
    refuse(403, 'You may not add followers here.');
}
if ($member !== $me && (find_member_by_id($pdo, $member)['status'] ?? '') !== 'active') {
    refuse(422, 'That member is not here.');
}
hd_guard($pdo, static function () use ($pdo, $me, $t, $member): void {
    $pdo->beginTransaction();
    add_follower($pdo, $t['ticket_id'], 'follower', $member, null, $me);
    ticket_log($pdo, $t, 'ticket.follow', ['after' => ['member_id' => $member]]);
    $pdo->commit();
});
ticket_done($t, ($member === $me ? 'You follow ' : 'Added a follower on ') . $t['number'], ['member_id' => $member], 'saved');
