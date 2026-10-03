<?php
declare(strict_types=1);
/**
 * GET /settings/sla/hours?schedule= — a schedule's weekly hours and holidays (screen `business-hours-edit`; a new one without `schedule`); `?preview=1&from=&minutes=`
 * answers the preview line. POST = action `business_hours_save` (log `business_hours.save`): name, timezone, intervals (the grid, or JSON [{weekday, opens, closes}]), is_default
 * (a default needs at least one open day). settings.manage.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/present.php';
require_right('settings.manage');
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    $id = request_integer('schedule') ?? request_integer('id');
    $cur = $id === null ? null : (find_schedule($pdo, $id) ?? refuse(404, 'Schedule not found.'));
    if (request_bool('preview') && $cur !== null) {
        $from = request_string('from');
        $minutes = max(1, min(525600, request_integer('minutes') ?? 60));
        $sentence = $from === '' ? 'Pick a moment and a number of minutes.' : (business_preview($pdo, $id, $from, $minutes) ?? 'That moment could not be read.');
        if (wants_json()) {
            respond_screen(['from' => $from, 'minutes' => $minutes, 'sentence' => $sentence]);
        }
        echo e($sentence);
        exit;
    }
    log_screen_view($pdo, 'business-hours-edit');
    if (wants_json()) {
        respond_screen(['schedule' => $cur === null ? null : present_schedule($cur), 'timezones' => DateTimeZone::listIdentifiers()]);
    }
    render_screen($cur === null ? 'New schedule' : $cur['name'] . ' — hours', view('sla/hours.php', ['cur' => $cur, 'here' => here_url(), 'preview' => null]),
        ['activeNav' => 'sla', 'screen' => 'business-hours-edit', 'entity' => 'business_hours', 'recordId' => $id === null ? '' : (string) $id]);
    exit;
}
hd_handler_begin();
$id = request_integer('schedule') ?? request_integer('schedule_id');
$cur = $id === null ? null : (schedule_state($pdo, $id) ?? refuse(404, 'Schedule not found.'));
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Give the schedule a name of up to 80 characters.';
}
$tz = req_has('timezone') ? (string) req_val('timezone') : (string) ($cur['timezone'] ?? 'UTC');
if (!in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    $errors['timezone'] = 'The time zone is an IANA name like America/New_York.';
}
$default = hd_yes('is_default', $cur['is_default'] ?? false);
$intervals = [];
if (req_has('intervals')) {
    $raw = $_POST['intervals'];
    if (!is_array($raw)) {
        $raw = json_decode((string) $raw, true);
        if (!is_array($raw)) {
            $errors['intervals'] = 'Intervals are JSON: [{"weekday": 1, "opens": "09:00", "closes": "17:00"}, …] (weekday 0 = Sunday).';
            $raw = [];
        }
    }
    $flat = [];
    foreach ($raw as $k => $v) {
        if (is_array($v) && isset($v['weekday'])) {
            $flat[] = $v;                                           // JSON: a list of {weekday, opens, closes}
        } elseif (is_array($v)) {
            foreach ($v as $iv) {                                   // the grid: intervals[weekday][i][opens|closes]
                if (is_array($iv)) {
                    $flat[] = ['weekday' => $k, 'opens' => $iv['opens'] ?? '', 'closes' => $iv['closes'] ?? ''];
                }
            }
        }
    }
    foreach ($flat as $iv) {
        $w = filter_var($iv['weekday'] ?? null, FILTER_VALIDATE_INT);
        $o = trim((string) ($iv['opens'] ?? ($iv['opens_at'] ?? '')));
        $c = trim((string) ($iv['closes'] ?? ($iv['closes_at'] ?? '')));
        if ($o === '' && $c === '') {
            continue;
        }
        if ($w === false || $w < 0 || $w > 6 || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $o) || !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $c) || $c <= $o) {
            $errors['intervals'] = 'Each open period is a weekday with an opening time before its closing time (HH:MM).';
            continue;
        }
        $intervals[] = [$w, substr($o, 0, 5), substr($c, 0, 5)];
    }
} elseif ($cur !== null) {
    foreach ($cur['intervals'] as $iv) {
        $intervals[] = [$iv['weekday'], $iv['opens'], $iv['closes']];
    }
}
if ($default && $intervals === []) {
    $errors['is_default'] = 'The default schedule needs at least one open day.';
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $name, $tz, $default, $intervals): int {
    $pdo->beginTransaction();
    $newId = save_schedule($pdo, $id, ['name' => $name, 'timezone' => $tz, 'is_default' => $default], $intervals);
    $after = schedule_state($pdo, $newId);
    if ($cur === null) {
        log_activity($pdo, 'business_hours.save', 'business_hours', $newId, ['after' => $after]);
    } else {
        $d = hd_diff($cur, $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'business_hours.save', 'business_hours', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done('Saved the hours of ' . $name, $newId, hd_land(return_path('/settings/sla/hours?schedule=' . $newId), 'hours'), 'scheduleChanged', ['schedule_id' => $newId]);
