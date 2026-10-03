<?php
declare(strict_types=1);
/** Action `secure_link_rotate` (log `ticket.secure_link_rotate`; confirm): work. The new link goes to the outbox for an external requester; the token is never in the response, the log or the page. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$t = ticket_from_request($pdo);
require_work($t);
hd_guard($pdo, static function () use ($pdo, $t): void {
    $pdo->beginTransaction();
    $raw = rotate_secure_link($pdo, $t['ticket_id']);
    notify($pdo, ['requester_id' => $t['requester_id']], 'replied', $t['ticket_id'], 'A new link for ' . $t['number'], 'The link to your request ' . $t['number'] . ' "' . $t['subject'] . '" was renewed; the old one no longer works. ' . requester_link_words($t, $raw), null, 'link:' . $t['ticket_id'] . ':' . time());
    ticket_log($pdo, $t, 'ticket.secure_link_rotate', ['after' => ['rotated' => true]]);
    $pdo->commit();
});
ticket_done($t, 'Rotated the secure link of ' . $t['number'], [], 'saved');
