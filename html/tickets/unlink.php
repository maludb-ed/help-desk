<?php
declare(strict_types=1);
/** Action `ticket_unlink` (log `ticket.unlink`): work on both; other_ticket, kind. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$t = ticket_from_request($pdo);
require_work($t);
$o = (string) req_val('other_ticket');
$otherId = filter_var($o, FILTER_VALIDATE_INT) !== false ? (int) $o : (int) (one_value($pdo, 'SELECT id FROM tickets WHERE upper(number) = upper(:n)', ['n' => trim($o)]) ?: 0);
if ($otherId < 1) {
    refuse(404, 'Ticket not found.');
}
$other = ticket_load($pdo, $otherId);
require_work($other);
$kind = (string) req_val('kind');
$ok = hd_guard($pdo, static function () use ($pdo, $t, $other, $kind): bool {
    $pdo->beginTransaction();
    $ok = unlink_tickets($pdo, $t['ticket_id'], $other['ticket_id'], $kind);
    if ($ok) {
        ticket_log($pdo, $t, 'ticket.unlink', ['after' => ['kind' => $kind, 'to_ticket_id' => $other['ticket_id']]]);
    }
    $pdo->commit();
    return $ok;
});
if (!$ok) {
    refuse(404, 'There is no such link.');
}
ticket_done($t, 'Unlinked ' . $t['number'] . ' and ' . $other['number'], ['to_ticket_id' => $other['ticket_id']], 'saved');
