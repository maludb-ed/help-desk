<?php
declare(strict_types=1);
/** Action `article_delete` (confirm; agents pause — deletion; log `article.delete`): settings.manage. Refused while the article was sent on tickets: "This article was sent on N tickets — archive it instead." */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$a = find_article_any($pdo, request_integer('article') ?? request_integer('id') ?? 0) ?? refuse(404, 'Article not found.');
$sent = (int) one_value($pdo, "SELECT count(*) FROM article_links WHERE article_id = :a AND kind = 'sent'", ['a' => $a['article_id']]);
if ($sent > 0) {
    refuse(422, 'This article was sent on ' . $sent . ' ticket' . ($sent === 1 ? '' : 's') . ' — archive it instead.');
}
hd_guard($pdo, static function () use ($pdo, $me, $a): void {
    $pdo->beginTransaction();
    log_activity($pdo, 'article.delete', 'article', $a['article_id'], ['before' => article_log_state($a)]);
    delete_article($pdo, $a['article_id'], $me);
    $pdo->commit();
});
hd_done('Deleted ' . $a['title'], $a['article_id'], hd_land('/kb/admin/', 'deleted'), 'articleChanged');
