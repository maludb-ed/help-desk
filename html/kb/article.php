<?php
declare(strict_types=1);
/**
 * /kb/{slug} — one article (screen `kb-article`; the public door for a public published one). An internal article to a visitor, a draft to a reader, an archived one: 404 "Article not found."
 * — the same sentence. A view counts once per session (never an editor's own). Editors see Edit · Unpublish · Archive.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
$pdo = db();
$filter = kb_visible_filter(current_member());
$public = !$filter['member'];
if ($public) {
    $GLOBALS['__public_door'] = 'portal';
}
$a = find_article_by_slug($pdo, (string) ($_GET['slug'] ?? ''), $filter) ?? refuse(404, 'Article not found.');
if ($a['status'] === 'archived' && !$filter['drafts']) {
    refuse(404, 'Article not found.');
}
$canEdit = $filter['drafts'];
if (!$canEdit) {
    record_view($pdo, $a['article_id'], $public ? (string) (public_ip() ?? 'ip') : 'm' . (int) current_member_id());
}
$voted = $_SESSION['kb_votes'][$a['article_id'] . ':' . date('Y-m-d')] ?? null;
log_activity($pdo, 'screen.view', 'article', $a['article_id'], ['screen' => 'kb-article', 'after' => ['article_id' => $a['article_id']]]);
if (!$public && wants_json()) {
    respond_screen(['article' => present_article($a, true), 'attachments' => article_attachments($pdo, $a['article_id']), 'may' => ['edit' => $canEdit]]);
}
$html = view('kb/article.php', ['a' => $a, 'attachments' => article_attachments($pdo, $a['article_id']), 'public' => $public, 'canEdit' => $canEdit, 'voted' => $voted,
    'notice' => hd_notice($_GET['notice'] ?? null, ['thanks' => ['success', 'Thanks for telling us.'], 'already' => ['info', 'Thanks — you already told us.'], 'published' => ['success', 'Published.'], 'unpublished' => ['info', 'Back to a draft.']])]);
if ($public) {
    render_public($a['title'], $html, 'kb-article');
}
render_screen($a['title'], $html, ['activeNav' => 'kb', 'screen' => 'kb-article', 'entity' => 'article', 'recordId' => (string) $a['article_id']]);
