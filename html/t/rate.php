<?php
declare(strict_types=1);
/** GET and POST /t/{token}/rate — the survey: the same page as /t/{token} (?r= the mail's one-time token); POST writes the score, reason and comment once (a second says already received), logs rating.submit, tells the assignee (kind rated). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_GET['token'] = (string) ($_GET['token'] ?? '');
    require dirname(__DIR__) . '/t.php';
    exit;
}
[$tid, $T, $token] = secure_post_begin($pdo);
if (!secure_may($T)['rate']) {
    render_public('Not now', view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => secure_may($T), 'rating' => null, 'ratingToken' => null, 'notice' => ['warning', 'There is nothing to rate yet.']]), 'secure-ticket', 422);
}
[$rating] = ensure_rating_row($pdo, $tid, $T['requester_id']);
$r = (string) ($_POST['r'] ?? '');
if ($r !== '' && !rating_token_valid($rating, $r)) {
    secure_dead_link($pdo);
}
$score = filter_var($_POST['score'] ?? '', FILTER_VALIDATE_INT);
if ($score === false || $score < 1 || $score > 5) {
    render_public('Rate it', view('public/secure-ticket.php', ['T' => $T, 'token' => $token, 'may' => secure_may($T), 'rating' => $rating, 'ratingToken' => $r !== '' ? $r : null, 'notice' => ['danger', 'Choose a rating first.']]), 'secure-ticket', 422);
}
$reason = trim((string) ($_POST['bad_reason'] ?? ''));
$reason = $reason !== '' && in_array($reason, $T['csat']['bad_reasons'], true) ? $reason : null;
$comment = mb_substr(trim((string) ($_POST['comment'] ?? '')), 0, 2000) ?: null;
$t = ticket_facts($pdo, $tid);
$pdo->beginTransaction();
$ok = submit_rating($pdo, $rating['id'], $score, $reason, $comment);
if ($ok) {
    ticket_log($pdo, $t, 'rating.submit', ['actor_requester_id' => $T['requester_id'], 'after' => ['rating_id' => $rating['id'], 'score' => $score, 'bad_reason' => $reason, 'has_comment' => $comment !== null, 'via' => 'secure_link']]);
    if ($t['assignee_member_id'] !== null) {
        notify($pdo, ['member_id' => $t['assignee_member_id']], 'rated', $tid, $t['number'] . ' was rated ' . score_words($score, $T['csat']['scale']), 'The requester rated ' . $t['number'] . ' "' . $t['subject'] . '": ' . score_words($score, $T['csat']['scale']) . ($reason !== null ? ' — ' . $reason : '') . ($comment !== null ? "\n\n" . $comment : '') . "\n\nSee it here: " . app_url('/tickets/' . $tid), null, 'rated:' . $tid);
    }
}
$pdo->commit();
secure_back($token, $ok ? 'rated' : 'already');
