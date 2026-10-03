<?php
declare(strict_types=1);
/** Action `ticket_reopen` (log `ticket.reopen`): work, or the requester within reopen_window_days of solved_at ("This request was solved more than 14 days ago — file a new one and mention HD-1042."); solved → open; closed → 422. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
$work = can_work_ticket($t['ticket_id']);
if (!$work && !is_own_ticket($pdo, $t)) {
    refuse(403, 'You are not in this ticket\'s queue.');
}
if ($t['status_category'] === 'closed') {
    refuse(422, $t['number'] . ' cannot move from Closed.');
}
if ($t['status_category'] !== 'solved') {
    refuse(422, $t['number'] . ' is not solved.');
}
$window = (int) $pdo->query('SELECT reopen_window_days FROM app_settings WHERE id = 1')->fetchColumn();
if (!$work && $t['solved_at'] !== null && strtotime((string) $t['solved_at']) < time() - $window * 86400) {
    refuse(422, 'This request was solved more than ' . $window . ' days ago — file a new one and mention ' . $t['number'] . '.');
}
$open = status_by_key($pdo, 'open') ?? refuse(422, 'No open status.');
$note = (string) req_val('note');
hd_guard($pdo, static function () use ($pdo, $me, $t, $open, $note, $work): void {
    $pdo->beginTransaction();
    $moved = transition_ticket($pdo, $t['ticket_id'], $open['status_id'], [], $me, $work ? 'screen' : 'requester', $work);
    if ($note !== '') {
        add_message($pdo, $t['ticket_id'], $work ? 'internal_note' : 'public_reply', $note, ['author_member_id' => $me]);
    }
    ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
    ticket_log($pdo, $t, 'ticket.reopen', ['after' => ['via' => $work ? 'screen' : 'requester', 'reason' => $note === '' ? null : excerpt($note)]]);
    $pdo->commit();
});
ticket_done($t, 'Reopened ' . $t['number'], ['status' => 'open'], 'saved');
