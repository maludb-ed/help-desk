<?php
declare(strict_types=1);
/** Action `view_delete` (log `view.delete`: the view's state before): own, or views.shared for a shared one; never a standard one (422). Lands on /views/. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('view') ?? request_integer('view_id') ?? refuse(422, 'Say which view.');
$cur = find_view($pdo, $id) ?? refuse(404, 'View not found.');
if ($cur['is_system']) {
    refuse(422, 'A standard view cannot be changed — save a copy.');
}
if (!view_may_change($cur)) {
    refuse(403, $cur['scope'] === 'personal' ? 'That view is not yours.' : 'You may not change shared views.');
}
hd_guard($pdo, static function () use ($pdo, $cur, $me): void {
    $pdo->beginTransaction();
    log_activity($pdo, 'view.delete', 'saved_view', $cur['view_id'], ['before' => view_log_state($cur)]);
    delete_view($pdo, $cur['view_id'], $me);
    $pdo->commit();
});
hd_done('Deleted the view ' . $cur['name'], $cur['view_id'], hd_land('/views/', 'deleted'), 'viewChanged', ['view_id' => $cur['view_id']]);
