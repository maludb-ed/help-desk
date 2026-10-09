<?php
declare(strict_types=1);
/** /tickets/new (screen `ticket-add`; tickets.create; params requester, queue, category, on_behalf_of) and /tickets/{id}/edit (screen `ticket-edit`; work). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id');
$cur = null;
if ($id !== null) {
    $cur = ticket_load($pdo, $id);
    require_work($cur);
} else {
    require_right('tickets.create');
}
$canOnBehalf = has_right('tickets.view_queue') || has_right('tickets.work');
$categories = array_values(array_filter(find_category_tree($pdo), static fn (array $c): bool => $canOnBehalf || $c['requester_visible']));
$data = ['cur' => $cur, 'queues' => $canOnBehalf ? find_queues($pdo) : [], 'categories' => $categories, 'types' => find_types($pdo), 'fieldDefs' => fields_for($pdo, 'ticket', $cur['type_id'] ?? null),
         'onBehalfOf' => request_integer('on_behalf_of'), 'forCustomer' => request_string('requester') !== '', 'canOnBehalf' => $canOnBehalf, 'here' => here_url(),
         'prefill' => ['subject' => mb_substr(request_string('subject'), 0, 300), 'body' => '', 'category' => request_integer('category'), 'queue' => request_integer('queue')]];
$screen = $cur === null ? 'ticket-add' : 'ticket-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['ticket' => $cur === null ? null : ['ticket_id' => $cur['ticket_id'], 'number' => $cur['number'], 'subject' => $cur['subject'], 'type_id' => $cur['type_id'], 'category_id' => $cur['category_id'], 'due_at' => json_ts($cur['due_at']), 'custom_values' => (object) $cur['custom_values']],
        'can_file_for_others' => $canOnBehalf, 'queues' => array_map(static fn (array $q): array => ['queue_id' => $q['queue_id'], 'name' => $q['name']], $data['queues']), 'categories' => array_map('present_category', $categories),
        'types' => array_map('present_type', $data['types']), 'fields' => array_map('present_field', $data['fieldDefs']), 'priorities' => array_keys(PRIORITY_COLORS)]);
}
render_screen($cur === null ? 'New request' : 'Change ' . $cur['number'], view('tickets/form.php', $data), ['activeNav' => $cur === null ? 'new-ticket' : 'inbox', 'screen' => $screen, 'entity' => 'ticket', 'recordId' => $id === null ? '' : (string) $id]);
