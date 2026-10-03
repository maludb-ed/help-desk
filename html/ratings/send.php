<?php
declare(strict_types=1);
/** Action `survey_send` (log `survey.send`: requester_id; an agent pauses — external_send): work. The survey mailed now for a solved ticket of a surveyed queue; refuses an unsolved, an unsurveyed, a surveyed-already one. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$r = hd_guard($pdo, static function () use ($pdo, $t, $me): array {
    $pdo->beginTransaction();
    $r = send_survey($pdo, $t['ticket_id'], $me);
    ticket_log($pdo, $t, 'survey.send', ['after' => ['requester_id' => $r['requester_id'], 'rating_id' => $r['rating_id'], 'via' => 'manual']]);
    $pdo->commit();
    return $r;
});
ticket_done($t, 'Sent the survey for ' . $t['number'], ['rating_id' => $r['rating_id']], 'saved');
