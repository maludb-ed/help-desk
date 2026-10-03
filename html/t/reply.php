<?php
declare(strict_types=1);
/** POST /t/{token}/reply — the requester's reply through the secure link: a public reply (channel portal; the trigger reopens pending or a solved ticket within the window), up to three attachments, the assignee told. A closed ticket answers the page's closed notice, nothing written. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
[$tid, $T, $token] = secure_post_begin($pdo);
if ($T['status_category'] === 'closed') {
    secure_back($token, 'closed');
}
$body = trim((string) ($_POST['body'] ?? ''));
if ($body === '' || mb_strlen($body) > 20000) {
    secure_back($token, 'empty');
}
$files = public_files('files', 3);
$t = ticket_facts($pdo, $tid);
$pdo->beginTransaction();
try {
    $mid = add_message($pdo, $tid, 'public_reply', $body, ['author_requester_id' => $T['requester_id'], 'channel' => 'portal']);
    foreach ($files as $file) {
        store_attachment($pdo, $tid, $mid, $file, null, $T['requester_id']);
    }
    $recipients = $t['assignee_member_id'] !== null ? [['member_id' => $t['assignee_member_id']]] : array_map(static fn (int $m): array => ['member_id' => $m], queue_lead_ids($pdo, $t['queue_id']));
    $n = 0;
    foreach ($recipients as $rc) {
        $n += notify($pdo, $rc, 'replied', $tid, 'Re: ' . $t['number'] . ' ' . $t['subject'], $body . "\n\nSee it here: " . app_url('/tickets/' . $tid), $mid, 'replied:' . $mid) > 0 ? 1 : 0;
    }
    ticket_log($pdo, $t, 'ticket.reply', ['actor_requester_id' => $T['requester_id'], 'after' => ['message_id' => $mid, 'kind' => 'public_reply', 'excerpt' => excerpt($body), 'attachments' => count($files), 'recipients' => $n, 'channel' => 'portal']]);
    $pdo->commit();
} catch (DomainException $e) {
    $pdo->rollBack();
    render_public('Add to it', view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => secure_may($T), 'rating' => null, 'ratingToken' => null, 'notice' => ['danger', $e->getMessage()]]), 'secure-ticket', 422);
}
secure_back($token, 'replied');
