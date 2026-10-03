<?php
declare(strict_types=1);
/** Action `canned_delete` (log `canned.delete`; an agent pauses — deletion): own, or tickets.manage. Archives the row (its usage history stays). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('tickets.work');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('canned') ?? request_integer('canned_id') ?? refuse(422, 'Say which canned response.');
$cur = find_canned_one($pdo, $id) ?? refuse(404, 'Canned response not found.');
if (!canned_may_change($cur)) {
    refuse(403, 'That canned response is not yours to delete.');
}
hd_guard($pdo, static function () use ($pdo, $cur, $me): void {
    $pdo->beginTransaction();
    log_activity($pdo, 'canned.delete', 'canned_response', $cur['canned_id'], ['before' => canned_log_state($cur)]);
    delete_canned($pdo, $cur['canned_id'], $me);
    $pdo->commit();
});
hd_done('Deleted the canned response ' . $cur['title'], $cur['canned_id'], hd_land('/canned/', 'deleted'), 'cannedChanged', ['canned_id' => $cur['canned_id']]);
