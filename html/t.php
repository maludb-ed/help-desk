<?php
declare(strict_types=1);
/** /t/{token} — an external requester's ticket by its secure link (screen `secure-ticket`): the public conversation, reply, confirm, reopen, rate. A dead link is one page, 404. Logs screen.view with ticket_id and actor_requester_id. */
require_once dirname(__DIR__) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__) . '/app/features/public/secure.php';
$pdo = db();
$GLOBALS['__public_door'] = 'portal';
$token = (string) ($_GET['token'] ?? '');
$tid = secure_ticket_open($pdo, $token) ?? secure_dead_link($pdo);
$T = present_secure_ticket($pdo, $tid) ?? secure_dead_link($pdo);
$may = secure_may($T);
$rating = null;
$ratingToken = null;
if ($may['rate']) {
    [$rating, $raw] = ensure_rating_row($pdo, $tid, $T['requester_id']);
    $r = (string) ($_GET['r'] ?? '');
    if ($r !== '' && rating_token_valid($rating, $r)) {
        $ratingToken = $r;
    }
}
log_activity($pdo, 'screen.view', 'ticket', $tid, ['screen' => 'secure-ticket', 'ticket_id' => $tid, 'queue_id' => $T['queue_id'], 'actor_requester_id' => $T['requester_id']]);
$notices = ['replied' => ['success', 'Sent — we have it.'], 'confirmed' => ['success', 'Thank you — marked as resolved.'], 'reopened' => ['success', 'Reopened — we will look again.'], 'rated' => ['success', 'Thank you for the rating.'],
            'already' => ['info', 'Thank you — already received.'], 'late' => ['warning', 'This was solved too long ago to reopen — start a new request.'], 'empty' => ['warning', 'Write something first.'], 'closed' => ['warning', 'This request is closed — start a new request.']];
render_public($T['number'] . ' · ' . $T['subject'], view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => $may, 'rating' => $rating, 'ratingToken' => $ratingToken, 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]), 'secure-ticket');
