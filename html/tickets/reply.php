<?php
declare(strict_types=1);
/**
 * Action `ticket_reply` (log `ticket.reply`: message_id, kind, excerpt, attachments, recipients; pauses for an agent — external_send): work, or the ticket's own requester (or a cc).
 * A requester's reply is a requester-side reply (the trigger reopens pending / solved in the window). then_status / resolution_code are honoured for work only. Queues `replied` notices.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
$work = can_work_ticket($t['ticket_id']);
$own = is_own_ticket($pdo, $t);
if (!$work && !$own) {
    refuse(403, 'You are not in this ticket\'s queue.');
}
if ($t['status_category'] === 'closed') {
    refuse(422, $t['number'] . ' is closed — a follow-up is a new ticket.');
}
$body = (string) req_val('body');
if ($body === '' || mb_strlen($body) > 20000) {
    hd_refuse_fields(['body' => 'Write the reply (up to 20,000 characters).']);
}
$attachments = array_values(array_filter(array_map('intval', request_list('attachments') ?? [])));
$ccs = $work ? (request_list('cc') ?? []) : [];
$then = $work ? (string) req_val('then_status') : '';
$code = (string) req_val('resolution_code');
$supportReply = $work && $t['requester_member_id'] !== $me;
$canned = $work && request_integer('canned_id') !== null ? find_canned_one($pdo, (int) request_integer('canned_id')) : null;        // slice 5: the composer's canned pick
$applyEffects = $canned !== null && hd_yes('apply_side_effects', false);
$r = hd_guard($pdo, static function () use ($pdo, $me, $t, $body, $attachments, $ccs, $then, $code, $supportReply, $canned, $applyEffects): array {
    $pdo->beginTransaction();
    foreach ($ccs as $cc) {
        $rid = filter_var($cc, FILTER_VALIDATE_INT) !== false ? (int) $cc : (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $cc) ? (int) one_value($pdo, 'SELECT hd_requester_for_email(:e)', ['e' => strtolower($cc)]) : null);
        if ($rid !== null) {
            $row = $pdo->prepare('SELECT member_id FROM requesters WHERE id = :r'); $row->execute(['r' => $rid]); $mid = $row->fetchColumn();
            add_follower($pdo, $t['ticket_id'], 'cc', $mid === null || $mid === false ? null : (int) $mid, $mid === null || $mid === false ? $rid : null, $me);
            ticket_log($pdo, $t, 'ticket.cc_add', ['after' => ['requester_id' => $rid]]);
        }
    }
    $mid = add_message($pdo, $t['ticket_id'], 'public_reply', $body, ['author_member_id' => $me, 'channel' => is_action_authed() && current_agent_run_id() !== null ? 'agent' : 'web', 'attachment_ids' => $attachments]);
    $recipients = $supportReply ? reply_recipients($pdo, $t) : [];
    if (!$supportReply) {
        $recipients = $t['assignee_member_id'] !== null ? [['member_id' => $t['assignee_member_id']]] : array_map(static fn (int $m): array => ['member_id' => $m], queue_lead_ids($pdo, $t['queue_id']));
    }
    $n = 0;
    foreach ($recipients as $rc) {
        if (isset($rc['member_id']) && $rc['member_id'] === $me) { continue; }
        $n += notify($pdo, $rc, 'replied', $t['ticket_id'], 'Re: ' . $t['number'] . ' ' . $t['subject'], $body . "\n\n" . ($supportReply ? requester_link_words($t, null) : 'See it here: ' . app_url('/tickets/' . $t['ticket_id'])), $mid, 'replied:' . $mid) > 0 ? 1 : 0;
    }
    ticket_log($pdo, $t, 'ticket.reply', ['after' => ['message_id' => $mid, 'kind' => 'public_reply', 'excerpt' => excerpt($body), 'attachments' => count($attachments), 'recipients' => $n] + ($canned !== null ? ['canned_id' => $canned['canned_id']] : [])]);
    $effects = null;
    if ($canned !== null) {
        $effects = $applyEffects ? canned_side_effects($pdo, $canned, $t['ticket_id'], $me) : ['applied' => [], 'skipped' => []];
        bump_canned_usage($pdo, $canned['canned_id']);
        ticket_log($pdo, $t, 'canned.apply', ['after' => ['canned_id' => $canned['canned_id'], 'sent' => true, 'message_id' => $mid, 'side_effects' => $effects]]);
    }
    $moved = null;
    if ($then !== '') {
        $to = status_by_key($pdo, $then) ?? throw new DomainException('That status is not here.');
        $moved = transition_ticket($pdo, $t['ticket_id'], $to['status_id'], ['resolution_code' => $code], $me, 'screen');
        ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
    }
    $pdo->commit();
    return ['message_id' => $mid, 'recipients' => $n, 'moved' => $moved, 'effects' => $effects];
});
$effectWords = $r['effects'] === null ? '' : ($r['effects']['applied'] !== [] ? ' (' . implode(', ', $r['effects']['applied']) . ')' : '') . ($r['effects']['skipped'] !== [] ? ' — skipped ' . implode('; ', array_map(static fn (array $s): string => $s['effect'] . ': ' . $s['why'], $r['effects']['skipped'])) : '');
ticket_done($t, 'Replied on ' . $t['number'] . ($r['moved'] !== null ? ' and set it ' . $r['moved']['after']['status'] : '') . $effectWords, ['message_id' => $r['message_id'], 'record_id' => $r['message_id'], 'recipients' => $r['recipients']] + ($r['effects'] !== null ? ['side_effects' => $r['effects']] : []), 'sent');
