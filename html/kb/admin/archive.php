<?php
declare(strict_types=1);
/** Action `article_archive` (confirm; log `article.archive`): kb.edit. The article stays by slug for editors only. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('kb.edit');
$pdo = db();
$me = (int) current_member_id();
$a = find_article_any($pdo, request_integer('article') ?? request_integer('id') ?? 0) ?? refuse(404, 'Article not found.');
hd_guard($pdo, static function () use ($pdo, $me, $a): void {
    $pdo->beginTransaction();
    archive_article($pdo, $a['article_id'], $me);
    log_activity($pdo, 'article.archive', 'article', $a['article_id'], ['before' => ['status' => $a['status']], 'after' => ['status' => 'archived', 'slug' => $a['slug']]]);
    $pdo->commit();
});
hd_done('Archived ' . $a['title'], $a['article_id'], hd_land('/kb/admin/?status=archived', 'archived'), 'articleChanged');
