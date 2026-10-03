<?php
declare(strict_types=1);
/** Action `holiday_remove` (log `business_hours.holiday_remove`): settings.manage; schedule, holiday (a date). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('schedule') ?? request_integer('schedule_id') ?? refuse(422, 'Say which schedule.');
$s = find_schedule($pdo, $id) ?? refuse(404, 'Schedule not found.');
$date = request_date('holiday');
if ($date === null || $date === false) {
    hd_refuse_fields(['holiday' => 'The holiday is a date (YYYY-MM-DD).']);
}
$pdo->beginTransaction();
$ok = remove_holiday($pdo, $id, $date);
if ($ok) {
    log_activity($pdo, 'business_hours.holiday_remove', 'business_hours', $id, ['before' => ['holiday' => $date], 'after' => ['holiday' => $date, 'removed' => true]]);
}
$pdo->commit();
if (!$ok) {
    refuse(404, 'That date is not a holiday of ' . $s['name'] . '.');
}
hd_done('Removed the holiday ' . $date . ' from ' . $s['name'], $id, hd_land(return_path('/settings/sla/hours?schedule=' . $id), 'hours'), 'scheduleChanged', ['holiday' => $date]);
