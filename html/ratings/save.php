<?php
declare(strict_types=1);
/** Action `rating_submit` (log `rating.submit`: score, bad_reason): the ticket's own requester (by member id), on a solved or closed ticket of a surveyed queue; written once. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
if ($t['requester_member_id'] !== $me) {
    refuse(403, 'Only the requester rates a ticket.');
}
if (!in_array($t['status_category'], ['solved', 'closed'], true)) {
    refuse(422, $t['number'] . ' is not solved yet.');
}
$cs = find_csat_settings($pdo);
if (!queue_surveyed($cs, $t['queue_id'])) {
    refuse(422, 'That queue is not surveyed.');
}
$score = filter_var(req_val('score') ?? '', FILTER_VALIDATE_INT);
if ($score === false || $score < 1 || $score > 5) {
    hd_refuse_fields(['score' => 'The score is 1 to 5.']);
}
$reason = (string) req_val('bad_reason');
$reason = $reason !== '' && in_array($reason, $cs['bad_reasons'], true) ? $reason : null;
$comment = mb_substr((string) req_val('comment'), 0, 2000) ?: null;
$ok = hd_guard($pdo, static function () use ($pdo, $t, $score, $reason, $comment, $me): bool {
    $pdo->beginTransaction();
    [$rating] = ensure_rating_row($pdo, $t['ticket_id'], $t['requester_id']);
    $ok = submit_rating($pdo, $rating['id'], $score, $reason, $comment);
    if ($ok) {
        ticket_log($pdo, $t, 'rating.submit', ['after' => ['rating_id' => $rating['id'], 'score' => $score, 'bad_reason' => $reason, 'has_comment' => $comment !== null, 'via' => 'screen']]);
        if ($t['assignee_member_id'] !== null && $t['assignee_member_id'] !== $me) {
            notify($pdo, ['member_id' => $t['assignee_member_id']], 'rated', $t['ticket_id'], $t['number'] . ' was rated', 'The requester rated ' . $t['number'] . ' "' . $t['subject'] . '": ' . $score . ($reason !== null ? ' — ' . $reason : '') . "\n\nSee it here: " . app_url('/tickets/' . $t['ticket_id']), null, 'rated:' . $t['ticket_id']);
        }
    }
    $pdo->commit();
    return $ok;
});
if (!$ok) {
    refuse(422, 'Thank you — already received.');
}
ticket_done($t, 'Rated ' . $t['number'] . ': ' . score_words($score, $cs['scale']), ['score' => $score], 'rated');
