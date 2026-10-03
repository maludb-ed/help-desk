<?php
declare(strict_types=1);
/** /canned/new and /canned/{id}/edit (screens `canned-add`, `canned-edit`; tickets.work): one I may change, else 403; a missing or hidden one is "Canned response not found." */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('tickets.work')) {
    refuse(403, 'You may not work tickets here.');
}
require_human();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('id');
$cur = $id === null ? null : (find_canned_one($pdo, $id) ?? refuse(404, 'Canned response not found.'));
if ($cur !== null && !canned_may_change($cur)) {
    refuse(403, 'That canned response is not yours to change.');
}
$held = find_queues($pdo, ['mine' => true]);
$shareable = has_right('tickets.manage') ? $held : array_values(array_filter($held, static fn (array $q): bool => (bool) $q['i_lead']));
log_screen_view($pdo, $cur === null ? 'canned-add' : 'canned-edit');
if (wants_json()) {
    respond_screen(['canned' => $cur === null ? null : present_canned($cur), 'placeholders' => canned_placeholders(), 'queues' => array_map(static fn (array $q): array => ['queue_id' => $q['queue_id'], 'name' => $q['name']], $shareable), 'can_global' => has_right('tickets.manage')]);
}
render_screen($cur === null ? 'New canned response' : 'Change ' . $cur['title'], view('canned/form.php', ['cur' => $cur, 'statuses' => find_statuses($pdo), 'assignees' => find_assignable_members($pdo), 'queues' => $shareable,
    'folders' => canned_folders($pdo, $me), 'canGlobal' => has_right('tickets.manage'), 'here' => here_url()]), ['activeNav' => 'canned', 'screen' => $cur === null ? 'canned-add' : 'canned-edit', 'entity' => 'canned_response', 'recordId' => (string) ($id ?? '')]);
