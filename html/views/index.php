<?php
declare(strict_types=1);
/** /views/ — the views I may open (screen `views-list`; tickets.view_own): standard, mine, shared (global and my queues'), each with a count through the translator (50 at most). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_right('tickets.view_own');
require_human();
$pdo = db();
$me = (int) current_member_id();
$views = find_views($pdo, $me, request_string('q') ?: null, request_string('layout') ?: null);
foreach ($views as &$v) {
    $v['count'] = view_count($pdo, $v, $me);
}
unset($v);
$standard = array_values(array_filter($views, static fn (array $v): bool => $v['is_system']));
$mine = array_values(array_filter($views, static fn (array $v): bool => !$v['is_system'] && $v['scope'] === 'personal'));
$shared = array_values(array_filter($views, static fn (array $v): bool => !$v['is_system'] && $v['scope'] !== 'personal'));
log_screen_view($pdo, 'views-list');
if (wants_json()) {
    respond_screen(['views' => array_map('present_view', $views)]);
}
render_screen('Views', view('views/list.php', ['standard' => $standard, 'mine' => $mine, 'shared' => $shared, 'canSave' => has_right('views.personal') || has_right('views.shared'),
    'notice' => hd_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The view was deleted.'], 'saved' => ['success', 'Saved.']])]), ['activeNav' => 'views', 'screen' => 'views-list', 'entity' => 'saved_view']);
