<?php
declare(strict_types=1);
/** Action `queue_member_remove` (log `queue.member_remove`; confirm): queues.members, or the queue's lead. A department member is not removable here. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
hd_handler_begin();
$pdo = db();
$qid = request_integer('queue') ?? request_integer('queue_id') ?? refuse(422, 'Say which queue.');
$q = find_queue($pdo, $qid) ?? refuse(404, 'Queue not found.');
if (!has_right('queues.members') && !db_bool($pdo, 'SELECT hd_is_queue_lead(:q)', ['q' => $qid])) {
    refuse(403, 'You may not change who is in a queue.');
}
$mid = request_integer('member') ?? request_integer('member_id') ?? refuse(422, 'Say which person or agent.');
$row = null;
foreach (queue_members($pdo, $qid) as $m) {
    if ($m['member_id'] === $mid) {
        $row = $m;
    }
}
if ($row === null) {
    refuse(404, 'They are not in this queue.');
}
if ($row['how'] !== 'named') {
    refuse(422, $row['display_name'] . ' is here through ' . ($q['department_name'] ?? 'the department') . '. Leave the department in the OS.');
}
hd_guard($pdo, static function () use ($pdo, $qid, $mid, $row): void {
    $pdo->beginTransaction();
    remove_queue_member($pdo, $qid, $mid);
    log_activity($pdo, 'queue.member_remove', 'queue', $qid, ['queue_id' => $qid, 'before' => ['member_id' => $mid, 'is_lead' => $row['is_lead']], 'after' => ['member_id' => $mid, 'removed' => true]]);
    $pdo->commit();
});
hd_done('Removed ' . $row['display_name'] . ' from ' . $q['name'], $mid, hd_land(return_path('/settings/queues/' . $qid), 'member_removed'), 'queueChanged', ['queue_id' => $qid, 'member_id' => $mid]);
