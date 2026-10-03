<?php
declare(strict_types=1);
/** Action `queue_presence_set` (log `queue.presence`): a person's own availability (online, away, offline) and capacity (max open tickets, or empty for no limit). Always about oneself. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$cur = find_my_presence($pdo, $me);
$errors = [];
$availability = req_has('availability') ? (string) req_val('availability') : $cur['availability'];
if (!isset(PRESENCE_STATES[$availability])) {
    $errors['availability'] = 'Availability is online, away or offline.';
}
$capacity = hd_int('capacity', $cur['capacity'], 0, 500, 'Capacity', $errors, true);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$pdo->beginTransaction();
set_presence($pdo, $me, $availability, $capacity);
$d = hd_diff(['availability' => $cur['availability'], 'capacity' => $cur['capacity']], ['availability' => $availability, 'capacity' => $capacity]);
if ($d['after'] !== []) {
    log_activity($pdo, 'queue.presence', 'member', $me, ['before' => $d['before'], 'after' => $d['after']]);
}
$pdo->commit();
hd_done('You are ' . strtolower(PRESENCE_STATES[$availability]) . ($capacity !== null ? ', up to ' . $capacity . ' open tickets' : ''), $me, hd_land(return_path('/settings/'), 'presence'), 'presenceChanged', ['availability' => $availability, 'capacity' => $capacity]);
