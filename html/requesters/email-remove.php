<?php
declare(strict_types=1);
/** Action `requester_email_remove` (log `requester.email_remove`; confirm): tickets.view_queue; never the last address. */
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
$ok = hd_guard($pdo, static function () use ($pdo, $id, $email): bool {
    $pdo->beginTransaction();
    $ok = remove_requester_email($pdo, $id, $email);
    if ($ok) {
        log_activity($pdo, 'requester.email_remove', 'requester', $id, ['before' => ['email' => $email], 'after' => ['email' => $email, 'removed' => true]]);
    }
    $pdo->commit();
    return $ok;
});
if (!$ok) {
    refuse(404, 'They do not have that address.');
}
hd_done('Removed ' . $email, $id, hd_land(return_path('/requesters/' . $id), 'email_removed'), 'requesterChanged', ['email' => $email]);
