<?php
declare(strict_types=1);
/** Action `requester_email_add` (log `requester.email_add`): tickets.view_queue; an external's addresses only (a member's are the mirror's). A known address is refused with its owner. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/queries.php';
hd_handler_begin();
require_right('tickets.view_queue');
$pdo = db();
$id = request_integer('requester') ?? request_integer('requester_id') ?? refuse(422, 'Say which requester.');
$r = find_requester($pdo, $id) ?? refuse(404, 'Requester not found.');
if ($r['kind'] !== 'external') {
    refuse(422, 'A member\'s addresses are the OS\'s.');
}
$email = strtolower((string) req_val('email'));
hd_guard($pdo, static function () use ($pdo, $id, $email): void {
    $pdo->beginTransaction();
    add_requester_email($pdo, $id, $email);
    log_activity($pdo, 'requester.email_add', 'requester', $id, ['after' => ['email' => $email]]);
    $pdo->commit();
});
hd_done('Added ' . $email . ' to ' . $r['display_name'], $id, hd_land(return_path('/requesters/' . $id), 'email_added'), 'requesterChanged', ['email' => $email]);
