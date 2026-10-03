<?php
declare(strict_types=1);
/** Action `ticket_note` (log `ticket.note`): tickets.note in the queue, or work. A mention queues `mentioned` to each named member who can see the ticket (others silently dropped). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
if (!can_note_ticket($pdo, $t)) {
    refuse(403, 'You may not add notes here.');
}
$body = (string) req_val('body');
if ($body === '' || mb_strlen($body) > 20000) {
    hd_refuse_fields(['body' => 'Write the note (up to 20,000 characters).']);
}
$attachments = array_values(array_filter(array_map('intval', request_list('attachments') ?? [])));
$mentions = members_who_see($pdo, $t['ticket_id'], request_list('mentions') ?? []);
$mid = hd_guard($pdo, static function () use ($pdo, $me, $t, $body, $attachments, $mentions): int {
    $pdo->beginTransaction();
    $mid = add_message($pdo, $t['ticket_id'], 'internal_note', $body, ['author_member_id' => $me, 'channel' => is_action_authed() && current_agent_run_id() !== null ? 'agent' : 'web', 'mentions' => $mentions, 'attachment_ids' => $attachments]);
    foreach ($mentions as $m) {
        if ($m !== $me) {
            notify($pdo, ['member_id' => $m], 'mentioned', $t['ticket_id'], 'You were mentioned on ' . $t['number'], 'A note on ' . $t['number'] . ' "' . $t['subject'] . '" mentions you. See it here: ' . app_url('/tickets/' . $t['ticket_id']), $mid, 'mentioned:' . $mid);
        }
    }
    ticket_log($pdo, $t, 'ticket.note', ['after' => ['message_id' => $mid, 'kind' => 'internal_note', 'excerpt' => excerpt($body), 'attachments' => count($attachments), 'mentions' => count($mentions)]]);
    $pdo->commit();
    return $mid;
});
automation_run_triggers($pdo, 'ticket.note', $t['ticket_id']);
ticket_done($t, 'Noted on ' . $t['number'], ['message_id' => $mid, 'record_id' => $mid, 'mentions' => $mentions], 'noted');
