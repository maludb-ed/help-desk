<?php
declare(strict_types=1);
/** /kb/s/{slug} — one section's published articles (screen `kb-section`); a visitor sees a public section bare. A section the caller may not see: 404 "Section not found." */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
$pdo = db();
$filter = kb_visible_filter(current_member());
$public = !$filter['member'];
if ($public) {
    $GLOBALS['__public_door'] = 'portal';
}
$s = find_section_by_slug($pdo, (string) ($_GET['slug'] ?? ''), $filter) ?? refuse(404, 'Section not found.');
$all = find_sections($pdo, $filter);
$parent = null;
$children = [];
foreach ($all as $o) {
    if ($o['section_id'] === $s['parent_id']) { $parent = $o; }
    if ($o['parent_id'] === $s['section_id']) { $children[] = $o; }
}
$rows = find_articles($pdo, $filter, $s['section_id']);
log_activity($pdo, 'screen.view', null, null, ['screen' => 'kb-section', 'after' => ['section_id' => $s['section_id']]]);
if (!$public && wants_json()) {
    respond_screen(['section' => present_section($s), 'parent' => $parent ? present_section($parent) : null, 'children' => array_map('present_section', $children), 'articles' => array_map('present_article', $rows)]);
}
$html = view('kb/section.php', ['s' => $s, 'parent' => $parent, 'children' => $children, 'rows' => $rows, 'public' => $public, 'canEdit' => $filter['drafts']]);
if ($public) {
    render_public($s['name'], $html, 'kb-section');
}
render_screen($s['name'], $html, ['activeNav' => 'kb', 'screen' => 'kb-section', 'entity' => 'kb_section', 'recordId' => (string) $s['section_id']]);
