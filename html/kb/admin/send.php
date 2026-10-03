<?php
declare(strict_types=1);
/**
 * Action `article_send` (log `article.send`: after.article_id, after.message_id — plus the reply's own `ticket.reply` row; agents pause — external_send): work on the ticket. An internal
 * article to an external requester: 422 "That article is internal." The reply = body (optional) + the title as a link + a 300-character summary, as a public reply through add_message()
 * (so it mails, meets first response and pauses exactly as ticket_reply does); article_links kind sent.
 */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_can_work_ticket($t['ticket_id']);
if ($t['status_category'] === 'closed') {
    refuse(422, $t['number'] . ' is closed — a follow-up is a new ticket.');
}
$a = find_article($pdo, request_integer('article') ?? 0, kb_visible_filter(current_member())) ?? refuse(404, 'Article not found.');
if ($a['status'] !== 'published') {
    refuse(422, 'That article is not published.');
}
if ($a['visibility'] === 'internal' && $t['requester_kind'] === 'external') {
    refuse(422, 'That article is internal.');
}
$words = (string) req_val('body');
if (mb_strlen($words) > 20000) {
    hd_refuse_fields(['body' => 'Up to 20,000 characters before the link.']);
}
$body = ($words !== '' ? $words . "\n\n" : '') . $a['title'] . "\n" . app_url('/kb/' . $a['slug']) . "\n\n" . article_summary($a, 300);
$r = hd_guard($pdo, static function () use ($pdo, $me, $t, $a, $body): array {
    $pdo->beginTransaction();
    $mid = add_message($pdo, $t['ticket_id'], 'public_reply', $body, ['author_member_id' => $me, 'channel' => is_action_authed() && current_agent_run_id() !== null ? 'agent' : 'web']);
    $n = 0;
    foreach (reply_recipients($pdo, $t) as $rc) {
        if (isset($rc['member_id']) && $rc['member_id'] === $me) { continue; }
        $n += notify($pdo, $rc, 'replied', $t['ticket_id'], 'Re: ' . $t['number'] . ' ' . $t['subject'], $body . "\n\n" . requester_link_words($t, null), $mid, 'replied:' . $mid) > 0 ? 1 : 0;
    }
    link_article($pdo, $t['ticket_id'], $a['article_id'], 'sent', $me);
    ticket_log($pdo, $t, 'ticket.reply', ['after' => ['message_id' => $mid, 'kind' => 'public_reply', 'excerpt' => excerpt($body), 'attachments' => 0, 'recipients' => $n, 'article_id' => $a['article_id']]]);
    ticket_log($pdo, $t, 'article.send', ['after' => ['article_id' => $a['article_id'], 'message_id' => $mid]]);
    $pdo->commit();
    return ['message_id' => $mid, 'recipients' => $n];
});
automation_run_triggers($pdo, 'ticket.reply', $t['ticket_id']);
ticket_done($t, 'Sent ' . $a['title'] . ' on ' . $t['number'], ['message_id' => $r['message_id'], 'record_id' => $r['message_id'], 'article_id' => $a['article_id'], 'recipients' => $r['recipients']], 'sent');
