<?php
declare(strict_types=1);
/** Action `ticket_log_time` (log `ticket.log_time`: after.minutes, billable): work; minutes 1–1440, billable, note, worked_on (a date, default today). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$errors = [];
$minutes = hd_int('minutes', null, 1, 1440, 'Minutes', $errors) ?? 0;
if ($minutes < 1) {
    $errors['minutes'] = 'Minutes are a whole number from 1 to 1440.';
}
$billable = hd_yes('billable', false);
$note = (string) req_val('note') === '' ? null : mb_substr((string) req_val('note'), 0, 200);
$day = request_date('worked_on');
if ($day === false) {
    $errors['worked_on'] = 'The day is a date (YYYY-MM-DD).';
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$day ??= (new DateTimeImmutable('now', new DateTimeZone(member_timezone())))->format('Y-m-d');
$id = hd_guard($pdo, static function () use ($pdo, $me, $t, $minutes, $billable, $note, $day): int {
    $pdo->beginTransaction();
    $id = log_time($pdo, $t['ticket_id'], $me, $minutes, $billable, $note, $day);
    ticket_log($pdo, $t, 'ticket.log_time', ['after' => ['entry_id' => $id, 'minutes' => $minutes, 'billable' => $billable, 'worked_on' => $day]]);
    $pdo->commit();
    return $id;
});
ticket_done($t, 'Logged ' . $minutes . ' minutes on ' . $t['number'], ['entry_id' => $id, 'record_id' => $id], 'saved');
