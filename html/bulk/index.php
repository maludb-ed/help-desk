<?php
declare(strict_types=1);
/** /bulk?tickets=1,2,3 — change several tickets at once (screen `bulk-update`; tickets.manage): only the tickets the caller may see are listed, 200 at most. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_right('tickets.manage');
require_human();
$pdo = db();
$me = (int) current_member_id();
$raw = $_GET['tickets'] ?? '';
$ids = is_array($raw) ? $raw : explode(',', (string) $raw);
$targets = bulk_targets($pdo, $ids, $me);
log_screen_view($pdo, 'bulk-update');
if (wants_json()) {
    respond_screen(['tickets' => array_map('present_ticket_brief', $targets), 'statuses' => array_map(static fn (array $s): array => ['key' => $s['key'], 'label' => $s['label']], find_statuses($pdo)), 'priorities' => VIEW_PRIORITIES]);
}
render_screen('Change ' . count($targets) . ' tickets', view('bulk/form.php', ['targets' => $targets, 'statuses' => find_statuses($pdo), 'assignees' => find_assignable_members($pdo), 'queues' => find_queues($pdo), 'tz' => member_timezone(), 'here' => here_url()]),
    ['activeNav' => 'inbox', 'screen' => 'bulk-update', 'entity' => 'ticket']);
