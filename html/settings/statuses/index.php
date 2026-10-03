<?php
declare(strict_types=1);
/** /settings/statuses/ — the statuses in board order (screen `statuses-list`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$rows = find_statuses($pdo);
log_screen_view($pdo, 'statuses-list');
if (wants_json()) {
    respond_screen(['statuses' => array_map('present_status', $rows)]);
}
$notices = ['saved' => ['success', 'Saved the status.'], 'moved' => ['success', 'Moved.'], 'archived' => ['success', 'Archived the status.']];
render_screen('Statuses', view('statuses/list.php', ['rows' => $rows, 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'statuses', 'screen' => 'statuses-list', 'entity' => 'ticket_status']);
