<?php
declare(strict_types=1);
/**
 * Action `tickets_bulk_update` (log ONE `ticket.bulk_update`: ticket_ids, the change keys, changed, refused with reasons — plus the per-ticket rows the functions write; an agent pauses — other): tickets.manage.
 * `confirmed` is required ("Confirm the change to N tickets."). Each ticket in its own transaction: a refusal is reported by its sentence and never undoes the others.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('tickets.manage');
$pdo = db();
$me = (int) current_member_id();
$ids = array_values(array_unique(array_filter(array_map('intval', request_list('tickets') ?? []))));
if ($ids === []) {
    hd_refuse_fields(['tickets' => 'Say which tickets.']);
}
$ids = array_slice($ids, 0, 200);
if (!hd_yes('confirmed', false)) {
    refuse(422, 'Confirm the change to ' . count($ids) . ' ticket' . (count($ids) === 1 ? '' : 's') . '.');
}
$errors = [];
$changes = [];
$status = (string) req_val('status');
if ($status !== '') {
    if (status_by_key($pdo, $status) === null) {
        $errors['status'] = 'That status is not here.';
    }
    $changes['status'] = $status;
    $changes['resolution_code'] = (string) req_val('resolution_code');
    $changes['hold_reason'] = (string) req_val('hold_reason');
    $changes['pending_until'] = (string) req_val('pending_until');
}
$assignee = (string) (req_val('assignee') ?? 'keep');
if ($assignee === '') {
    $assignee = 'keep';
}
if (!in_array($assignee, ['keep', 'none', 'me'], true) && filter_var($assignee, FILTER_VALIDATE_INT) === false) {
    $errors['assignee'] = 'The assignee is keep, none, me or a member id.';
}
$changes['assignee'] = $assignee;
$changes['queue'] = hd_ref($pdo, 'queue', null, 'SELECT 1 FROM queues WHERE id = :id AND archived_at IS NULL', 'queue', $errors);
$priority = (string) req_val('priority');
if ($priority !== '' && !in_array($priority, VIEW_PRIORITIES, true)) {
    $errors['priority'] = 'The priority is low, normal, high or urgent.';
}
$changes['priority'] = $priority;
$changes['add_tags'] = array_values(array_filter(array_map(static fn ($g): string => slugify_tag(trim((string) $g)), request_list('add_tags') ?? [])));
$changes['remove_tags'] = array_values(array_filter(array_map(static fn ($g): string => slugify_tag(trim((string) $g)), request_list('remove_tags') ?? [])));
$changes['note'] = mb_substr((string) req_val('note'), 0, 5000);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$keys = array_keys(array_filter($changes, static fn ($v) => $v !== '' && $v !== null && $v !== [] && $v !== 'keep'));
if ($keys === []) {
    refuse(422, 'Say what to change.');
}
$r = bulk_apply($pdo, $ids, $changes, $me);
foreach ($r['dispatch'] as $ticketId => $agentId) {
    dispatch_to_agent($pdo, $ticketId, $agentId, 'assigned', is_action_authed() && current_agent_run_id() !== null ? null : $me);
}
log_activity($pdo, 'ticket.bulk_update', 'ticket', null, ['after' => ['ticket_ids' => $ids, 'changes' => $keys, 'changed' => $r['changed'], 'refused' => $r['refused']]]);
$did = count($r['changed']) . ' changed' . ($r['refused'] !== [] ? ', ' . count($r['refused']) . ' refused' : '');
emit_action_status(true, ['did' => $did, 'record_id' => null, 'changed' => $r['changed'], 'refused' => $r['refused'], 'refresh' => 'ticketChanged']);
if (wants_json()) {
    respond_saved(['did' => $did, 'changed' => $r['changed'], 'refused' => $r['refused'], 'location' => '/inbox']);
}
hx_trigger('ticketChanged');
render_screen($did, view('bulk/result.php', ['targets' => bulk_targets($pdo, $ids, $me), 'changed' => $r['changed'], 'refused' => $r['refused'], 'here' => '/inbox']), ['activeNav' => 'inbox', 'screen' => 'bulk-update', 'entity' => 'ticket']);
