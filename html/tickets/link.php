<?php
declare(strict_types=1);
/** Action `ticket_link` (log `ticket.link`: after.kind, after.to_ticket_id): work on both tickets; the other must be visible (404 otherwise); other_ticket is an id or a number. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
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
$linkId = hd_guard($pdo, static function () use ($pdo, $me, $t, $other, $kind): int {
    $pdo->beginTransaction();
    $id = link_tickets($pdo, $t['ticket_id'], $other['ticket_id'], $kind, $me);
    ticket_log($pdo, $t, 'ticket.link', ['after' => ['kind' => $kind, 'to_ticket_id' => $other['ticket_id'], 'to_number' => $other['number']]]);
    ticket_log($pdo, $other, 'ticket.link', ['after' => ['kind' => $kind, 'to_ticket_id' => $t['ticket_id'], 'to_number' => $t['number'], 'direction' => 'from']]);
    $pdo->commit();
    return $id;
});
ticket_done($t, 'Linked ' . $t['number'] . ' (' . strtolower(LINK_KINDS[$kind] ?? $kind) . ' ' . $other['number'] . ')', ['link_id' => $linkId, 'record_id' => $linkId, 'to_ticket_id' => $other['ticket_id']], 'saved');
