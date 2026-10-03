<?php
declare(strict_types=1);
/** POST /t/{token}/reopen — a solved ticket within the window: open (via requester) with the reason as a public reply; after the window the page says when it was closed (422). Logs ticket.reopen beside ticket.transition. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
[$tid, $T, $token] = secure_post_begin($pdo);
if (!secure_may($T)['reopen']) {
    if ($T['status_category'] === 'solved' || $T['status_category'] === 'closed') {
        render_public('Closed', view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => secure_may($T), 'rating' => null, 'ratingToken' => null, 'notice' => ['warning', 'This was closed on ' . format_ts($T['solved_at'] ?? $T['closed_at'], $T['timezone'], 'M j, Y') . '. Start a new request.']]), 'secure-ticket', 422);
    }
    secure_back($token, 'closed');
}
$reason = trim((string) ($_POST['reason'] ?? ''));
$t = ticket_facts($pdo, $tid);
$to = status_by_key($pdo, 'open');
$pdo->beginTransaction();
try {
    $moved = transition_ticket($pdo, $tid, $to['status_id'], [], 0, 'requester', false);
    ticket_log($pdo, $t, 'ticket.transition', ['actor_requester_id' => $T['requester_id'], 'before' => $moved['before'], 'after' => $moved['after']]);
    if ($reason !== '') {
        $mid = add_message($pdo, $tid, 'public_reply', mb_substr($reason, 0, 20000), ['author_requester_id' => $T['requester_id'], 'channel' => 'portal']);
        ticket_log($pdo, $t, 'ticket.reply', ['actor_requester_id' => $T['requester_id'], 'after' => ['message_id' => $mid, 'kind' => 'public_reply', 'excerpt' => excerpt($reason), 'attachments' => 0, 'channel' => 'portal']]);
    }
    ticket_log($pdo, $t, 'ticket.reopen', ['actor_requester_id' => $T['requester_id'], 'after' => ['via' => 'requester', 'reason' => $reason === '' ? null : excerpt($reason)]]);
    $recipients = $t['assignee_member_id'] !== null ? [['member_id' => $t['assignee_member_id']]] : array_map(static fn (int $m): array => ['member_id' => $m], queue_lead_ids($pdo, $t['queue_id']));
    foreach ($recipients as $rc) {
        notify($pdo, $rc, 'replied', $tid, $t['number'] . ' was reopened by the requester', ($reason !== '' ? $reason . "\n\n" : '') . 'See it here: ' . app_url('/tickets/' . $tid), null, 'reopened:' . $tid . ':' . time());
    }
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack();
    render_public('Not now', view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => secure_may($T), 'rating' => null, 'ratingToken' => null, 'notice' => ['danger', $e->getMessage()]]), 'secure-ticket', 422);
}
secure_back($token, 'reopened');
