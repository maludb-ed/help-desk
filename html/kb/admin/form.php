<?php
declare(strict_types=1);
/**
 * /kb/admin/new and /kb/admin/{id}/edit — write or change an article (screens `article-add`, `article-edit`; kb.edit). ?section= preselects; ?from_ticket=HD-1042 (or an id) prefills the
 * title from the subject and the body from the public replies (first names, never a note). ?preview=1 with body renders the Markdown and saves nothing (the Preview tab).
 */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_right('kb.edit');
require_human();
$pdo = db();
if (request_string('preview') === '1') {
    header('Vary: HX-Request');
    echo '<div id="article-preview"><div class="article-body border rounded p-3 bg-light">' . md_render(mb_substr((string) ($_GET['body'] ?? $_POST['body'] ?? ''), 0, 200000)) . '</div></div>';
    exit;
}
$id = request_integer('id') ?? request_integer('article');
$cur = $id === null ? null : (find_article_any($pdo, $id) ?? refuse(404, 'Article not found.'));
$start = $cur === null ? ['title' => '', 'body' => '', 'section_id' => request_integer('section'), 'slug' => '', 'visibility' => 'internal', 'tags' => [], 'from_ticket' => null]
    : ['title' => $cur['title'], 'body' => $cur['body_text'], 'section_id' => $cur['section_id'], 'slug' => $cur['slug'], 'visibility' => $cur['visibility'], 'tags' => $cur['tags'], 'from_ticket' => null];
$from = trim(request_string('from_ticket'));
if ($cur === null && $from !== '') {
    $tid = ctype_digit($from) ? (int) $from : (int) (one_value($pdo, 'SELECT id FROM tickets WHERE number = :n', ['n' => strtoupper($from)]) ?: 0);
    if ($tid > 0) {
        require_can_see_ticket($tid);
        $draft = article_from_ticket($pdo, $tid);
        $start['title'] = $draft['title'];
        $start['body'] = $draft['body'];
        $start['from_ticket'] = $tid;
    }
}
log_screen_view($pdo, $cur === null ? 'article-add' : 'article-edit');
if (wants_json()) {
    respond_screen(['article' => $cur === null ? null : present_article($cur, true), 'start' => $start, 'sections' => array_map('present_section', find_sections($pdo, kb_visible_filter(current_member()), false)), 'visibilities' => KB_VISIBILITIES]);
}
render_screen($cur === null ? 'Write an article' : 'Change ' . $cur['title'], view('kb/form.php', ['cur' => $cur, 'start' => $start, 'sections' => find_sections($pdo, kb_visible_filter(current_member()), false), 'preview' => null, 'canDelete' => has_right('settings.manage'), 'here' => here_url()]),
    ['activeNav' => 'kb', 'screen' => $cur === null ? 'article-add' : 'article-edit', 'entity' => 'article', 'recordId' => $id === null ? '' : (string) $id]);
