<?php
declare(strict_types=1);
/**
 * GET /settings/general — the business's Help Desk settings (screen `general-settings`). POST = action `settings_save` (log `settings.save`: before/after of the changed keys only;
 * "Nothing changed" logs nothing): settings.manage. Fields: ticket_prefix, auto_close_days, reopen_window_days, secure_link_days, default_queue, request_form_enabled, org_visibility_default, timezone.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_once dirname(__DIR__, 2) . '/app/features/queues/queries.php';
require_right('settings.manage');
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    $s = find_settings($pdo);
    log_screen_view($pdo, 'general-settings');
    if (wants_json()) {
        respond_screen(['settings' => present_settings($s)]);
    }
    render_screen('Help Desk settings', view('settings/general.php', ['s' => $s, 'queues' => find_queues($pdo), 'notice' => hd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved the settings.'], 'same' => ['info', 'Nothing changed.']])]),
        ['activeNav' => 'my-settings', 'screen' => 'general-settings', 'entity' => 'settings']);
    exit;
}
hd_handler_begin();
$cur = find_settings($pdo);
$errors = [];
$f = [];
if (req_has('ticket_prefix')) {
    $f['ticket_prefix'] = strtoupper((string) req_val('ticket_prefix'));
    if (!preg_match('/^[A-Z][A-Z0-9]{0,7}$/', $f['ticket_prefix'])) {
        $errors['ticket_prefix'] = 'The prefix is capital letters and digits, up to 8, starting with a letter.';
    }
}
$f['auto_close_days'] = hd_int('auto_close_days', $cur['auto_close_days'], 0, 365, 'Auto-close days', $errors);
$f['reopen_window_days'] = hd_int('reopen_window_days', $cur['reopen_window_days'], 0, 365, 'The reopen window', $errors);
$f['secure_link_days'] = hd_int('secure_link_days', $cur['secure_link_days'], 1, 3650, 'The secure-link life', $errors);
$qf = req_has('default_queue') ? 'default_queue' : 'default_queue_id';
$f['default_queue_id'] = hd_ref($pdo, $qf, $cur['default_queue_id'], 'SELECT 1 FROM queues WHERE id = :id AND archived_at IS NULL', 'queue', $errors, false);
$f['request_form_enabled'] = hd_yes('request_form_enabled', $cur['request_form_enabled']);
$f['org_visibility_default'] = hd_yes('org_visibility_default', $cur['org_visibility_default']);
if (req_has('timezone')) {
    $f['timezone'] = (string) req_val('timezone');
    if (!in_array($f['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $errors['timezone'] = 'The time zone is an IANA name like America/New_York.';
    }
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$d = hd_guard($pdo, static function () use ($pdo, $f): array {
    $pdo->beginTransaction();
    $d = save_settings($pdo, $f);
    if ($d['after'] !== []) {
        log_activity($pdo, 'settings.save', 'app_settings', 1, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
});
hd_done($d['after'] === [] ? 'Nothing changed' : 'Saved the settings (' . implode(', ', array_keys($d['after'])) . ')', 1, hd_land(return_path('/settings/general'), $d['after'] === [] ? 'same' : 'saved'), 'settingsChanged', ['changed' => array_keys($d['after'])]);
