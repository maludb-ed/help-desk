<?php
declare(strict_types=1);
/** Action `ticket_cc_remove` (log `ticket.cc_remove`; confirm): work; cc = a requester id (or m<member id>). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$t = ticket_from_request($pdo);
require_work($t);
$cc = (string) req_val('cc');
$member = str_starts_with($cc, 'm') && ctype_digit(substr($cc, 1)) ? (int) substr($cc, 1) : null;
$rid = $member === null && filter_var($cc, FILTER_VALIDATE_INT) !== false ? (int) $cc : null;
if ($member === null && $rid === null) {
    hd_refuse_fields(['cc' => 'Say which CC.']);
}
$ok = hd_guard($pdo, static function () use ($pdo, $t, $member, $rid): bool {
    $pdo->beginTransaction();
    $ok = remove_follower($pdo, $t['ticket_id'], 'cc', $member, $rid);
    if ($ok) {
        ticket_log($pdo, $t, 'ticket.cc_remove', ['after' => ['member_id' => $member, 'requester_id' => $rid]]);
    }
    $pdo->commit();
    return $ok;
});
if (!$ok) {
    refuse(404, 'They were not copied.');
}
ticket_done($t, 'Removed a CC from ' . $t['number'], [], 'saved');
