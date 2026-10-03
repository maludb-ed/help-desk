<?php
declare(strict_types=1);
/** POST /kb/feedback.php — Was this helpful? (public; the bare layout's CSRF; one vote per visitor per article per day; counts only — not an action). Log `article.feedback` (after.helpful), source portal for a visitor. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_post();
verify_csrf();
$pdo = db();
$filter = kb_visible_filter(current_member());
if (!$filter['member']) {
    $GLOBALS['__public_door'] = 'portal';
}
$a = find_article($pdo, request_integer('article') ?? 0, $filter) ?? refuse(404, 'Article not found.');
$helpful = strtolower(trim((string) ($_POST['helpful'] ?? ''))) === 'yes';
$pdo->beginTransaction();
$counted = record_feedback($pdo, $a['article_id'], $helpful, (string) (public_ip() ?? ''));
if ($counted) {
    log_activity($pdo, 'article.feedback', 'article', $a['article_id'], ['after' => ['article_id' => $a['article_id'], 'helpful' => $helpful]]);
}
$pdo->commit();
emit_action_status(true, ['did' => $counted ? 'Thanks for telling us.' : 'Thanks — you already told us.', 'record_id' => $a['article_id']]);
redirect('/kb/' . $a['slug'] . '?notice=' . ($counted ? 'thanks' : 'already') . '#article-feedback');
