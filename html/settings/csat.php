<?php
declare(strict_types=1);
/** /settings/csat — the survey (screen `csat-settings`, GET) and action `csat_settings_save` (POST; log `csat_settings.save`: before/after of the changed fields; nothing changed → "Nothing changed", no row; an agent pauses — other): settings.manage. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_login();
require_right('settings.manage');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    require_human();
    $cs = find_csat_settings($pdo);
    log_screen_view($pdo, 'csat-settings');
    if (wants_json()) {
        respond_screen(['settings' => present_csat_settings($cs), 'scales' => array_keys(CSAT_SCALES), 'queues' => array_map(static fn (array $q): array => ['queue_id' => $q['queue_id'], 'name' => $q['name']], find_queues($pdo))]);
    }
    render_screen('Satisfaction survey', view('settings/csat.php', ['cs' => $cs, 'queues' => find_queues($pdo), 'notice' => hd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'same' => ['info', 'Nothing changed.']])]), ['activeNav' => 'csat', 'screen' => 'csat-settings', 'entity' => 'csat_settings']);
}
hd_handler_begin();
$cur = find_csat_settings($pdo);
$errors = [];
$f = $cur;
$f['enabled'] = hd_yes('enabled', $cur['enabled']);
if (req_has('scale')) {
    $f['scale'] = (string) req_val('scale');
    if (!isset(CSAT_SCALES[$f['scale']])) {
        $errors['scale'] = 'The scale is good_bad or five_point.';
    }
}
if (req_has('question')) {
    $f['question'] = (string) req_val('question');
    if ($f['question'] === '' || mb_strlen($f['question']) > 200) {
        $errors['question'] = 'Ask the question in up to 200 characters.';
    }
}
$f['send_delay_minutes'] = hd_int('send_delay_minutes', $cur['send_delay_minutes'], 0, 10080, 'The delay', $errors);
$f['response_window_days'] = hd_int('response_window_days', $cur['response_window_days'], 1, 90, 'The window', $errors);
if (req_has('bad_reasons')) {
    $raw = is_array($_POST['bad_reasons']) ? $_POST['bad_reasons'] : preg_split('/\r?\n/', (string) $_POST['bad_reasons']);
    $reasons = array_values(array_unique(array_filter(array_map('trim', $raw))));
    if (count($reasons) > 20 || array_filter($reasons, static fn (string $r): bool => mb_strlen($r) > 80) !== []) {
        $errors['bad_reasons'] = 'Up to 20 reasons of up to 80 characters.';
    }
    $f['bad_reasons'] = $reasons;
}
if (req_has('queues')) {
    $ids = array_values(array_unique(array_filter(array_map('intval', request_list('queues') ?? []))));
    foreach ($ids as $q) {
        if (!one_value($pdo, 'SELECT 1 FROM queues WHERE id = :q AND archived_at IS NULL', ['q' => $q])) {
            $errors['queues'] = 'A queue chosen is not here.';
        }
    }
    sort($ids);
    $f['queue_ids'] = $ids;
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$d = hd_guard($pdo, static function () use ($pdo, $f): array {
    $pdo->beginTransaction();
    $d = save_csat_settings($pdo, $f, (int) current_member_id());
    if ($d['after'] !== []) {
        log_activity($pdo, 'csat_settings.save', 'csat_settings', 1, ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
});
hd_done($d['after'] === [] ? 'Nothing changed' : 'Saved the survey settings (' . implode(', ', array_keys($d['after'])) . ')', 1, hd_land('/settings/csat', $d['after'] === [] ? 'same' : 'saved'), 'csatChanged', ['changed' => array_keys($d['after'])]);
