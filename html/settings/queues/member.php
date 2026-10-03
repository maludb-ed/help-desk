<?php
declare(strict_types=1);
/** Action `queue_member_add` (log `queue.member_add`: after.member_id, after.is_lead): queues.members, or the queue's lead. Also flips the lead flag of a named member. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$qid = request_integer('queue') ?? request_integer('queue_id') ?? refuse(422, 'Say which queue.');
$q = find_queue($pdo, $qid) ?? refuse(404, 'Queue not found.');
if (!has_right('queues.members') && !db_bool($pdo, 'SELECT hd_is_queue_lead(:q)', ['q' => $qid])) {
    refuse(403, 'You may not change who is in a queue.');
}
$mid = request_integer('member') ?? request_integer('member_id') ?? refuse(422, 'Say which person or agent.');
$lead = hd_yes('is_lead', false);
$was = null;
foreach (queue_members($pdo, $qid) as $m) {
    if ($m['member_id'] === $mid && $m['how'] === 'named') {
        $was = $m['is_lead'];
    }
}
hd_guard($pdo, static function () use ($pdo, $me, $qid, $mid, $lead, $was): void {
    $pdo->beginTransaction();
    add_queue_member($pdo, $qid, $mid, $lead, $me);
    log_activity($pdo, 'queue.member_add', 'queue', $qid, ['queue_id' => $qid, 'after' => ['member_id' => $mid, 'is_lead' => $lead]] + ($was === null ? [] : ['before' => ['member_id' => $mid, 'is_lead' => $was]]));
    $pdo->commit();
});
$name = (string) ($pdo->query('SELECT display_name FROM members WHERE id = ' . $mid)->fetchColumn() ?: 'the member');
hd_done($was === null ? 'Added ' . $name . ' to ' . $q['name'] . ($lead ? ' as lead' : '') : ($lead ? $name . ' now leads ' . $q['name'] : $name . ' no longer leads ' . $q['name']), $mid,
    hd_land(return_path('/settings/queues/' . $qid), $was === null ? 'member_added' : 'lead'), 'queueChanged', ['queue_id' => $qid, 'member_id' => $mid, 'is_lead' => $lead]);
