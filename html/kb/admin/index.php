<?php
declare(strict_types=1);
/** /kb/admin/ — every article by status with its counts, and the sections (screen `kb-admin`; kb.edit): ?status= published|draft|archived, ?section= (0 = no section), ?page=. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_right('kb.edit');
require_human();
$pdo = db();
$filter = kb_visible_filter(current_member());
$status = request_string('status', 'published');
$status = isset(ARTICLE_STATUSES[$status]) ? $status : 'published';
$sectionRaw = trim(request_string('section'));
$sectionId = $sectionRaw === '' ? null : max(0, (int) $sectionRaw);
$page = max(1, request_integer('page') ?? 1);
$rows = find_articles($pdo, $filter, $sectionId, $status, $page);
$more = count($rows) > 50;
$rows = array_slice($rows, 0, 50);
$allSections = find_sections($pdo, $filter);
log_screen_view($pdo, 'kb-admin');
if (wants_json()) {
    respond_screen(['status' => $status, 'section_id' => $sectionId, 'page' => $page, 'more' => $more, 'articles' => array_map('present_article', $rows), 'sections' => array_map('present_section', $allSections)]);
}
render_screen('Articles', view('kb/admin.php', ['rows' => $rows, 'status' => $status, 'sectionId' => $sectionId, 'sections' => $allSections, 'allSections' => $allSections, 'canDelete' => has_right('settings.manage'), 'page' => $page, 'more' => $more, 'tz' => member_timezone(),
    'notice' => hd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'archived' => ['success', 'Archived.'], 'deleted' => ['success', 'The article was deleted.'], 'section_saved' => ['success', 'The section was saved.'], 'section_archived' => ['success', 'The section was archived; its articles are under No section.']])]),
    ['activeNav' => 'kb', 'screen' => 'kb-admin', 'entity' => 'article']);
