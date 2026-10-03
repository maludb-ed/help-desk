<?php
declare(strict_types=1);
/** /canned/ — the canned responses I may use (screen `canned-list`; tickets.work): Mine · {Queue} · Everyone; ?q=, ?folder=; ?picker=1&ticket= answers the composer's picker (a partial, no log). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_login();
if (!has_right('tickets.work')) {
    refuse(403, 'You may not work tickets here.');
}
require_human();
$pdo = db();
$me = (int) current_member_id();
$q = mb_substr(request_string('q'), 0, 80);
$folder = mb_substr(request_string('folder'), 0, 60);
$rows = find_canned($pdo, $me, $q !== '' ? $q : null, $folder !== '' ? $folder : null);
if (request_string('picker') === '1') {
    $ticket = request_integer('ticket') ?? 0;
    header('Vary: HX-Request');
    echo view('canned/partials/picker.php', ['rows' => $rows, 'ticket' => $ticket, 'q' => $q]);
    exit;
}
$groups = [['mine', 'Mine', array_values(array_filter($rows, static fn (array $c): bool => $c['scope'] === 'personal'))]];
$byQueue = [];
foreach ($rows as $c) {
    if ($c['scope'] === 'queue') {
        $byQueue[$c['queue_id']] ??= [$c['queue_name'] ?? 'Queue', []];
        $byQueue[$c['queue_id']][1][] = $c;
    }
}
foreach ($byQueue as $qid => [$name, $list]) {
    $groups[] = ['queue-' . $qid, $name, $list];
}
$groups[] = ['global', 'Everyone', array_values(array_filter($rows, static fn (array $c): bool => $c['scope'] === 'global'))];
log_screen_view($pdo, 'canned-list');
if (wants_json()) {
    respond_screen(['q' => $q, 'folder' => $folder, 'folders' => canned_folders($pdo, $me), 'responses' => array_map('present_canned', $rows)]);
}
render_screen('Canned responses', view('canned/list.php', ['groups' => $groups, 'q' => $q, 'folder' => $folder, 'folders' => canned_folders($pdo, $me),
    'notice' => hd_notice($_GET['notice'] ?? null, ['deleted' => ['success', 'The canned response was deleted.'], 'saved' => ['success', 'Saved.']])]), ['activeNav' => 'canned', 'screen' => 'canned-list', 'entity' => 'canned_response']);
