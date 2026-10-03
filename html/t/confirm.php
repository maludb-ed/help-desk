<?php
declare(strict_types=1);
/** POST /t/{token}/confirm — "Yes, resolved": solved with resolution_code resolved, via requester (the right check skipped for the requester's own confirm; only → solved). Logs ticket.confirm beside ticket.transition. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
[$tid, $T, $token] = secure_post_begin($pdo);
if (!secure_may($T)['confirm']) {
    render_public('Not now', view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => secure_may($T), 'rating' => null, 'ratingToken' => null, 'notice' => ['warning', 'There is nothing to confirm yet.']]), 'secure-ticket', 422);
}
$t = ticket_facts($pdo, $tid);
$to = status_by_key($pdo, 'solved');
$pdo->beginTransaction();
try {
    $moved = transition_ticket($pdo, $tid, $to['status_id'], ['resolution_code' => 'resolved'], 0, 'requester', false);
    ticket_log($pdo, $t, 'ticket.transition', ['actor_requester_id' => $T['requester_id'], 'before' => $moved['before'], 'after' => $moved['after']]);
    ticket_log($pdo, $t, 'ticket.confirm', ['actor_requester_id' => $T['requester_id'], 'after' => ['via' => 'requester']]);
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack();
    render_public('Not now', view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => secure_may($T), 'rating' => null, 'ratingToken' => null, 'notice' => ['danger', $e->getMessage()]]), 'secure-ticket', 422);
}
secure_back($token, 'confirmed');
