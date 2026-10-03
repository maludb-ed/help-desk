<?php
declare(strict_types=1);
/** /settings/queues/ — the queues as cards (screen `queues-list`; settings.manage, or queues.members for a lead): department, members, leads, open count, assignment. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/present.php';
require_any_right('settings.manage|queues.members');
require_human();
$pdo = db();
$rows = find_queues($pdo, ['q' => request_string('q')]);
$may = ['manage' => has_right('settings.manage')];
log_screen_view($pdo, 'queues-list');
if (wants_json()) {
    respond_screen(['queues' => array_map('present_queue', $rows), 'may' => $may]);
}
render_screen('Queues', view('queues/list.php', ['rows' => $rows, 'may' => $may, 'here' => here_url(),
    'notice' => hd_notice($_GET['notice'] ?? null, ['archived' => ['success', 'The queue was archived; its open tickets are in the default queue.']])]),
    ['activeNav' => 'queues', 'screen' => 'queues-list', 'entity' => 'queue']);
