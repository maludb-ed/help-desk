<?php
declare(strict_types=1);
/** Action `article_suggest` (log `article.suggest`: after.article_id): work on the ticket; an article the caller may read. An article_links row kind suggested and an internal note "Suggested article: {title}". remove=1 dismisses (the row goes; logged with removed). */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_can_work_ticket($t['ticket_id']);
$a = find_article($pdo, request_integer('article') ?? 0, kb_visible_filter(current_member())) ?? refuse(404, 'Article not found.');
$remove = hd_yes('remove', false);
hd_guard($pdo, static function () use ($pdo, $me, $t, $a, $remove): void {
    $pdo->beginTransaction();
    if ($remove) {
        unlink_article($pdo, $t['ticket_id'], $a['article_id'], 'suggested');
        ticket_log($pdo, $t, 'article.suggest', ['after' => ['article_id' => $a['article_id'], 'removed' => true]]);
    } else {
        link_article($pdo, $t['ticket_id'], $a['article_id'], 'suggested', $me);
        $mid = add_message($pdo, $t['ticket_id'], 'internal_note', 'Suggested article: ' . $a['title'] . ' — ' . app_url('/kb/admin/' . $a['article_id'] . '/edit'), ['author_member_id' => $me, 'channel' => is_action_authed() && current_agent_run_id() !== null ? 'agent' : 'web']);
        ticket_log($pdo, $t, 'article.suggest', ['after' => ['article_id' => $a['article_id'], 'message_id' => $mid]]);
    }
    $pdo->commit();
});
ticket_done($t, ($remove ? 'Dismissed ' : 'Suggested ') . $a['title'] . ' on ' . $t['number'], ['article_id' => $a['article_id'], 'record_id' => $a['article_id']], $remove ? null : 'noted');
