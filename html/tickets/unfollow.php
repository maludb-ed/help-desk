<?php
declare(strict_types=1);
/** Action `ticket_unfollow` (log `ticket.unfollow`): oneself; another member needs work. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
$member = request_integer('member') ?? request_integer('member_id') ?? $me;
if ($member !== $me) {
    require_work($t);
}
$ok = hd_guard($pdo, static function () use ($pdo, $t, $member): bool {
    $pdo->beginTransaction();
    $ok = remove_follower($pdo, $t['ticket_id'], 'follower', $member, null);
    if ($ok) {
        ticket_log($pdo, $t, 'ticket.unfollow', ['after' => ['member_id' => $member]]);
    }
    $pdo->commit();
    return $ok;
});
if (!$ok) {
    refuse(404, 'They were not following it.');
}
ticket_done($t, 'Stopped following ' . $t['number'], ['member_id' => $member], 'saved');
