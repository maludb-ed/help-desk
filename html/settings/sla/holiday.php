<?php
declare(strict_types=1);
/** Action `holiday_add` (log `business_hours.holiday_add`): settings.manage; schedule, holiday (a date), name. */
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
$name = (string) req_val('name') === '' ? null : mb_substr((string) req_val('name'), 0, 60);
$pdo->beginTransaction();
add_holiday($pdo, $id, $date, $name);
log_activity($pdo, 'business_hours.holiday_add', 'business_hours', $id, ['after' => ['holiday' => $date, 'name' => $name]]);
$pdo->commit();
hd_done('Added the holiday ' . $date . ($name ? ' (' . $name . ')' : '') . ' to ' . $s['name'], $id, hd_land(return_path('/settings/sla/hours?schedule=' . $id), 'hours'), 'scheduleChanged', ['holiday' => $date]);
