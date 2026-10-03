<?php
declare(strict_types=1);
/** /settings/vocabulary/?tab=types|categories|tags (screen `vocabulary`; settings.manage, the tags tab also for tickets.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/vocabulary/present.php';
require_human();
$tab = in_array(request_string('tab'), ['types', 'categories', 'tags'], true) ? request_string('tab') : 'types';
if ($tab === 'tags') {
    require_any_right('settings.manage|tickets.manage');
} else {
    require_right('settings.manage');
}
$pdo = db();
$may = ['settings' => has_right('settings.manage'), 'manage' => has_right('tickets.manage')];
$q = mb_substr(request_string('q'), 0, 50);
$data = ['tab' => $tab, 'types' => $tab === 'types' ? find_types($pdo) : [], 'tree' => $tab === 'categories' ? find_category_tree($pdo) : [], 'tags' => $tab === 'tags' ? find_tags($pdo, $q) : [], 'may' => $may, 'q' => $q, 'here' => here_url()];
log_screen_view($pdo, 'vocabulary');
if (wants_json()) {
    respond_screen(['tab' => $tab, 'types' => array_map('present_type', $data['types']), 'categories' => array_map('present_category', $data['tree']), 'tags' => array_map('present_tag', $data['tags']), 'may' => $may]);
}
$notices = ['type_saved' => ['success', 'Saved the type.'], 'type_archived' => ['success', 'Archived the type.'], 'category_saved' => ['success', 'Saved the category.'], 'category_archived' => ['success', 'Archived the category.'],
            'tag_saved' => ['success', 'Saved the tag.'], 'tag_deleted' => ['success', 'Deleted the tag.']];
render_screen('Types, categories, tags', view('vocabulary/index.php', $data + ['notice' => hd_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'vocabulary', 'screen' => 'vocabulary', 'entity' => $tab === 'types' ? 'ticket_type' : ($tab === 'categories' ? 'category' : 'tag')]);
