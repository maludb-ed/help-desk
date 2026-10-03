<?php
declare(strict_types=1);
/** Action `queue_archive` (log `queue.archive`, after.moved_tickets; confirm): settings.manage. Refused for the default queue; open tickets move to the default queue, one `ticket.assign` each. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('queue') ?? request_integer('queue_id') ?? refuse(422, 'Say which queue.');
$r = hd_guard($pdo, static function () use ($pdo, $me, $id): array {
    $pdo->beginTransaction();
    $r = archive_queue($pdo, $id, $me);
    foreach ($r['moved'] as $t) {
        log_activity($pdo, 'ticket.assign', 'ticket', $t, ['ticket_id' => $t, 'queue_id' => $r['to'], 'before' => ['queue_id' => $id], 'after' => ['queue_id' => $r['to'], 'via' => 'queue_archive']]);
    }
    log_activity($pdo, 'queue.archive', 'queue', $id, ['queue_id' => $id, 'after' => ['name' => $r['name'], 'archived' => true, 'moved_tickets' => count($r['moved']), 'to_queue_id' => $r['to']]]);
    $pdo->commit();
    return $r;
});
hd_done('Archived ' . $r['name'] . ($r['moved'] !== [] ? ' and moved ' . count($r['moved']) . ' open ticket' . (count($r['moved']) === 1 ? '' : 's') . ' to the default queue' : ''), $id, hd_land(return_path('/settings/queues/'), 'archived'), 'queueChanged', ['moved_tickets' => $r['moved']]);
