<?php
declare(strict_types=1);
/**
 * Actions `article_create` / `article_update` (log `article.create` / `article.update`: title, slug, section_id, visibility, tags, from_ticket, status): kb.edit. title 1–200, body ≤ 200,000,
 * a slug unique (-2, -3 when derived; 422 when an edited one clashes), a live section, tags (a list or "a, b"), status draft|published (the two buttons; an update only moves TO published here —
 * unpublishing is article_publish). from_ticket writes article_links (created_from) and a note on the ticket. Lands on the edit page.
 */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('kb.edit');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('article') ?? request_integer('id');
$cur = $id === null ? null : (find_article_any($pdo, $id) ?? refuse(404, 'Article not found.'));
$errors = [];
$title = $cur !== null && !req_has('title') ? $cur['title'] : (string) req_val('title');
if ($title === '' || mb_strlen($title) > 200) {
    $errors['title'] = 'Give the article a title (up to 200 characters).';
}
$body = $cur !== null && !req_has('body') ? $cur['body_text'] : (string) req_val('body');
if (mb_strlen($body) > 200000) {
    $errors['body'] = 'The body is at most 200,000 characters.';
}
$sectionId = $cur !== null && !req_has('section') ? $cur['section_id'] : (($v = req_val('section')) === '' || $v === null ? null : (int) $v);
if ($sectionId !== null && one_value($pdo, 'SELECT 1 FROM kb_sections WHERE id = :s AND archived_at IS NULL', ['s' => $sectionId]) === false) {
    $errors['section'] = 'That section is not here.';
}
$visibility = $cur !== null && !req_has('visibility') ? $cur['visibility'] : (string) req_val('visibility');
if (!isset(KB_VISIBILITIES[$visibility])) {
    $errors['visibility'] = 'Public or internal.';
}
$tags = $cur !== null && !req_has('tags') ? $cur['tags'] : array_values(array_unique(array_filter(array_map(static fn (string $t): string => mb_substr(trim($t), 0, 40), is_array($_POST['tags'] ?? null) ? $_POST['tags'] : explode(',', (string) req_val('tags'))), static fn (string $t): bool => $t !== '')));
if (count($tags) > 20) {
    $errors['tags'] = 'At most 20 tags.';
}
$wantedSlug = strtolower(trim((string) req_val('slug')));
if ($wantedSlug !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $wantedSlug)) {
    $errors['slug'] = 'An address is lower-case letters, digits and dashes.';
} elseif ($wantedSlug !== '' && ($cur === null || $wantedSlug !== $cur['slug']) && one_value($pdo, 'SELECT 1 FROM articles WHERE slug = :s AND id <> :id', ['s' => $wantedSlug, 'id' => $id ?? 0]) !== false) {
    $errors['slug'] = 'That address is taken.';
}
$status = (string) req_val('status');
if ($status !== '' && !in_array($status, ['draft', 'published'], true)) {
    $errors['status'] = 'draft or published.';
}
$fromTicket = null;
if (($fv = trim((string) req_val('from_ticket'))) !== '') {
    $fromTicket = ctype_digit($fv) ? (int) $fv : (int) (one_value($pdo, 'SELECT id FROM tickets WHERE number = :n', ['n' => strtoupper($fv)]) ?: 0);
    if ($fromTicket <= 0 || !can_see_ticket($fromTicket)) {
        $errors['from_ticket'] = 'That ticket is not here.';
    }
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['title' => $title, 'body_text' => $body, 'section_id' => $sectionId, 'visibility' => $visibility, 'tags' => $tags, 'slug' => $wantedSlug !== '' ? $wantedSlug : ($cur !== null ? $cur['slug'] : null), 'from_ticket' => $fromTicket, 'status' => $status !== '' ? $status : 'draft'];
$r = hd_guard($pdo, static function () use ($pdo, $me, $cur, $f, $id, $status, $fromTicket): array {
    $pdo->beginTransaction();
    $newId = save_article($pdo, $id, $f, $me);
    if ($cur !== null && $status === 'published' && $cur['status'] !== 'published') {
        publish_article($pdo, $newId, true, $me);
    }
    $after = find_article_any($pdo, $newId);
    $ticket = $fromTicket !== null ? ticket_facts($pdo, $fromTicket) : null;
    if ($ticket !== null) {
        add_message($pdo, $fromTicket, 'internal_note', 'Article written from this ticket: ' . $after['title'] . ' — ' . app_url('/kb/admin/' . $newId . '/edit'), ['author_member_id' => $me, 'channel' => 'web']);
    }
    $state = article_log_state($after) + ['from_ticket' => $fromTicket];
    if ($cur === null) {
        log_activity($pdo, 'article.create', 'article', $newId, ['after' => $state] + ($ticket !== null ? ['ticket_id' => $fromTicket, 'queue_id' => $ticket['queue_id']] : []));
    } else {
        $d = hd_diff(article_log_state($cur), article_log_state($after));
        log_activity($pdo, 'article.update', 'article', $newId, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return ['id' => $newId, 'after' => $after];
});
hd_done(($cur === null ? 'Wrote ' : 'Saved ') . $r['after']['title'] . ($r['after']['status'] === 'published' ? ' (published)' : ''), $r['id'], hd_land(return_path('/kb/admin/' . $r['id'] . '/edit'), 'saved'), 'articleChanged', ['slug' => $r['after']['slug'], 'status' => $r['after']['status'], 'url' => '/kb/' . $r['after']['slug']]);
