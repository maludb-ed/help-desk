<?php
declare(strict_types=1);
/**
 * Action `transition_save` (log `transition.save`: from, to, allowed, requires, needs_right): settings.manage. from/to in the six, not equal, from never closed; `allowed = no` DELETES
 * the row (the table has no allowed column — absence is refusal); requires[] ⊆ {resolution_code, hold_reason}; may_carry[] ⊆ {pending_until, assignee}; needs_right ∈ {tickets.work, tickets.manage}.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/statuses/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$errors = [];
$from = (string) req_val('from_category');
$to = (string) req_val('to_category');
if (!isset(STATUS_CATEGORIES[$from]) || !isset(STATUS_CATEGORIES[$to])) {
    $errors['from_category'] = 'The categories are new, open, pending, on_hold, solved and closed.';
} elseif ($from === $to) {
    $errors['to_category'] = 'A move inside one category is always allowed; choose two.';
} elseif ($from === 'closed') {
    $errors['from_category'] = 'Closed is terminal: nothing moves out of it.';
}
$allowed = hd_yes('allowed', true);
$cur = $errors === [] ? find_transition($pdo, $from, $to) : null;
$cell = null;
if ($allowed) {
    $requires = request_list('requires');
    $requires = $requires === null ? ($cur['requires'] ?? []) : array_map(static fn (string $k): string => $k === 'assignee' ? 'assignee_member_id' : $k, $requires);
    if (array_diff($requires, array_keys(TRANSITION_REQUIRES)) !== []) {
        $errors['requires'] = 'A move may require resolution_code or hold_reason.';
    }
    $may = request_list('may_carry');
    $may = $may === null ? ($cur['may_carry'] ?? []) : array_map(static fn (string $k): string => $k === 'assignee' ? 'assignee_member_id' : $k, $may);
    if (array_diff($may, array_keys(TRANSITION_MAY_CARRY)) !== []) {
        $errors['may_carry'] = 'A move may carry pending_until or assignee.';
    }
    $right = req_has('needs_right') ? (string) req_val('needs_right') : ($cur['needs_right'] ?? 'tickets.work');
    if (!isset(TRANSITION_RIGHTS[$right])) {
        $errors['needs_right'] = 'The right is tickets.work or tickets.manage.';
    }
    $label = req_has('label') && (string) req_val('label') !== '' ? mb_substr((string) req_val('label'), 0, 40) : ($cur['label'] ?? (STATUS_CATEGORIES[$to] ?? ''));
    $cell = ['requires' => array_values(array_unique($requires)), 'may_carry' => array_values(array_unique($may)), 'needs_right' => $right, 'label' => $label];
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
hd_guard($pdo, static function () use ($pdo, $from, $to, $cur, $cell): void {
    $pdo->beginTransaction();
    save_transition($pdo, $from, $to, $cell);
    $after = ['from_category' => $from, 'to_category' => $to, 'allowed' => $cell !== null] + ($cell === null ? [] : ['requires' => $cell['requires'], 'may_carry' => $cell['may_carry'], 'needs_right' => $cell['needs_right'], 'label' => $cell['label']]);
    $before = $cur === null ? ['allowed' => false] : ['allowed' => true, 'requires' => $cur['requires'], 'may_carry' => $cur['may_carry'], 'needs_right' => $cur['needs_right'], 'label' => $cur['label']];
    if ($before != array_intersect_key($after, $before) || ($cur === null) === ($cell !== null)) {
        log_activity($pdo, 'transition.save', 'status_transition', null, ['before' => $before, 'after' => $after]);
    }
    $pdo->commit();
});
hd_done($cell === null ? STATUS_CATEGORIES[$from] . ' → ' . STATUS_CATEGORIES[$to] . ' is no longer allowed' : 'Saved ' . STATUS_CATEGORIES[$from] . ' → ' . STATUS_CATEGORIES[$to] . ' (' . $cell['label'] . ')', null,
    hd_land(return_path('/settings/statuses/transitions'), $cell === null ? 'removed' : 'saved', 'transition-' . $from . '-' . $to), 'transitionChanged', ['from_category' => $from, 'to_category' => $to, 'allowed' => $cell !== null]);
