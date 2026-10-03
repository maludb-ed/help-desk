<?php
declare(strict_types=1);
/** Action `article_publish` (log `article.publish`: after.published): kb.edit; article, published yes|no (no returns it to a draft; published_at is set once and kept). */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('kb.edit');
$pdo = db();
$me = (int) current_member_id();
$a = find_article_any($pdo, request_integer('article') ?? request_integer('id') ?? 0) ?? refuse(404, 'Article not found.');
$published = hd_yes('published', true);
hd_guard($pdo, static function () use ($pdo, $me, $a, $published): void {
    $pdo->beginTransaction();
    publish_article($pdo, $a['article_id'], $published, $me);
    log_activity($pdo, 'article.publish', 'article', $a['article_id'], ['before' => ['status' => $a['status']], 'after' => ['published' => $published, 'status' => $published ? 'published' : 'draft', 'slug' => $a['slug']]]);
    $pdo->commit();
});
hd_done(($published ? 'Published ' : 'Unpublished ') . $a['title'], $a['article_id'], hd_land(return_path('/kb/' . $a['slug']), $published ? 'published' : 'unpublished'), 'articleChanged', ['published' => $published, 'url' => '/kb/' . $a['slug']]);
