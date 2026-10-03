<?php
declare(strict_types=1);
/** /settings/statuses/new and /settings/statuses/{id}/edit (screens `status-add`, `status-edit`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('status');
$cur = $id === null ? null : (find_status($pdo, $id) ?? refuse(404, 'Status not found.'));
$screen = $cur === null ? 'status-add' : 'status-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['status' => $cur === null ? null : present_status($cur), 'categories' => CUSTOM_STATUS_CATEGORIES, 'colors' => STATUS_COLORS]);
}
render_screen($cur === null ? 'Add a status' : 'Change ' . $cur['label'], view('statuses/form.php', ['cur' => $cur, 'here' => here_url()]), ['activeNav' => 'statuses', 'screen' => $screen, 'entity' => 'ticket_status', 'recordId' => $id === null ? '' : (string) $id]);
