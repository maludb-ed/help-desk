<?php
declare(strict_types=1);
/** /settings/queues/{id} — one queue (screen `queue-view`): facts, members and leads, presence, the add-member form; Edit and Archive for settings.manage. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/present.php';
require_any_right('settings.manage|queues.members');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('queue') ?? refuse(404, 'Queue not found.');
$q = find_queue($pdo, $id) ?? refuse(404, 'Queue not found.');
$members = queue_members($pdo, $id);
$presence = find_queue_presence($pdo, $id);
$may = ['manage' => has_right('settings.manage'), 'members' => has_right('queues.members') || db_bool($pdo, 'SELECT hd_is_queue_lead(:q)', ['q' => $id])];
log_screen_view($pdo, 'queue-view');
if (wants_json()) {
    respond_screen(['queue' => present_queue($q), 'members' => array_map('present_queue_member', $members), 'presence' => $presence, 'may' => $may]);
}
$notices = ['saved' => ['success', 'Saved the queue.'], 'member_added' => ['success', 'Added to the queue.'], 'member_removed' => ['success', 'Removed from the queue.'], 'lead' => ['success', 'Changed who leads.']];
render_screen($q['name'], view('queues/view.php', ['q' => $q, 'members' => $members, 'presence' => $presence, 'may' => $may, 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'queues', 'screen' => 'queue-view', 'entity' => 'queue', 'recordId' => (string) $id]);
