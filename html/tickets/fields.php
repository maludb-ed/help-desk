<?php
declare(strict_types=1);
/** Action `ticket_set_fields` (log `ticket.set_fields`, changed only): work; priority, type, category, due_at, custom (validated for the ticket's type). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$t = ticket_from_request($pdo);
require_work($t);
$errors = [];
$fields = [];
if (req_has('priority')) {
    $fields['priority'] = (string) req_val('priority');
    if (!isset(PRIORITY_COLORS[$fields['priority']])) {
        $errors['priority'] = 'The priority is low, normal, high or urgent.';
    }
}
$fields['type_id'] = hd_ref($pdo, req_has('type') ? 'type' : 'type_id', $t['type_id'], 'SELECT 1 FROM ticket_types WHERE id = :id AND archived_at IS NULL', 'type', $errors);
$fields['category_id'] = hd_ref($pdo, req_has('category') ? 'category' : 'category_id', $t['category_id'], 'SELECT 1 FROM categories WHERE id = :id AND archived_at IS NULL', 'category', $errors);
if (req_has('due_at')) {
    $fields['due_at'] = request_when('due_at', $errors, 'The due date');
}
$custom = request_custom();
if ($custom !== null) {
    $v = custom_values_validate($pdo, 'ticket', $custom, $fields['type_id']);
    if ($v['errors'] !== []) {
        hd_refuse_fields($v['errors']);
    }
    $fields['custom'] = $v['values'];
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$d = hd_guard($pdo, static function () use ($pdo, $me, $t, $fields): array {
    $pdo->beginTransaction();
    $d = set_ticket_fields($pdo, $t['ticket_id'], $fields, $me);
    if ($d['after'] !== []) {
        ticket_log($pdo, $t, 'ticket.set_fields', ['before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
    return $d;
});
ticket_done($t, $d['after'] === [] ? 'Nothing changed on ' . $t['number'] : 'Changed ' . implode(', ', array_map(static fn (string $k): string => str_replace(['_id', '_', 'custom.'], ['', ' ', ''], $k), array_keys($d['after']))) . ' on ' . $t['number'], ['changed' => array_keys($d['after'])], 'saved');
