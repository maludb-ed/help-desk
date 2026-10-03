<?php
declare(strict_types=1);
/**
 * Action `status_save` (by the presence of `status`; log `status.save`, before/after of the changed fields, after.category on create): settings.manage. The database's guard answers a
 * bad category in its own sentence (422). `move=up|down` re-saves the board order instead. The key is derived from the label on create and never changes.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('status') ?? request_integer('status_id');
$cur = $id === null ? null : (find_status($pdo, $id) ?? refuse(404, 'Status not found.'));
if ($cur !== null && $cur['archived_at'] !== null) {
    refuse(422, 'That status is archived.');
}
$move = (string) req_val('move');
if ($cur !== null && in_array($move, ['up', 'down'], true)) {
    [$a, $b] = hd_guard($pdo, static function () use ($pdo, $id, $move): array {
        $pdo->beginTransaction();
        $r = reorder_status($pdo, $id, $move === 'up' ? -1 : 1);
        if ($r[0] !== $r[1]) {
            log_activity($pdo, 'status.save', 'ticket_status', $id, ['before' => ['sort_order' => $r[0]], 'after' => ['sort_order' => $r[1]]]);
        }
        $pdo->commit();
        return $r;
    });
    hd_done($a === $b ? $cur['label'] . ' is already at the ' . ($move === 'up' ? 'top' : 'bottom') : 'Moved ' . $cur['label'] . ' ' . $move, $id, hd_land(return_path('/settings/statuses/'), 'moved'), 'statusChanged', ['sort_order' => $b]);
}
$errors = [];
$label = req_has('label') ? (string) req_val('label') : (string) ($cur['label'] ?? '');
if ($label === '' || mb_strlen($label) > 60) {
    $errors['label'] = 'Give the status a label of up to 60 characters.';
}
$category = req_has('category') ? (string) req_val('category') : (string) ($cur['category'] ?? 'open');
if (!isset(STATUS_CATEGORIES[$category])) {
    $errors['category'] = 'The category is open, pending, on_hold or solved.';
}
$color = req_has('color') ? (string) req_val('color') : (string) ($cur['color'] ?? 'secondary');
if (!in_array($color, STATUS_COLORS, true)) {
    $errors['color'] = 'The colour is one of ' . implode(', ', STATUS_COLORS) . '.';
}
$order = hd_int('sort_order', $cur['sort_order'] ?? 100, 0, 1000, 'The order', $errors);
$onBoard = hd_yes('on_board', $cur['on_board'] ?? true);
$clock = hd_yes('clock_runs', $cur['clock_runs'] ?? !in_array($category, CLOCK_PAUSES_IN, true));
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['label' => $label, 'category' => $category, 'color' => $color, 'sort_order' => $order, 'on_board' => $onBoard, 'clock_runs' => $clock];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_status($pdo, $id, $f);
    $after = status_log_state(find_status($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'status.save', 'ticket_status', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(status_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'status.save', 'ticket_status', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the status ' : 'Saved the status ') . $label, $newId, hd_land(return_path('/settings/statuses/'), 'saved', 'status-row-' . $newId), 'statusChanged', ['status_id' => $newId]);
