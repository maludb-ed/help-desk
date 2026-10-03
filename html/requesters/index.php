<?php
declare(strict_types=1);
/** /requesters/ — the people who ask, as cards (screen `requesters-list`; tickets.view_queue; params q, kind, organization). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/present.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'kind' => request_string('kind'), 'organization' => request_integer('organization')];
$rows = find_requesters($pdo, $filters);
log_screen_view($pdo, 'requesters-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'requesters' => array_map('present_requester_brief', $rows)]);
}
render_screen('Requesters', view('requesters/list.php', ['rows' => $rows, 'organizations' => find_organizations_for_select($pdo), 'filters' => $filters, 'here' => here_url(),
    'notice' => hd_notice($_GET['notice'] ?? null, ['merged' => ['success', 'Merged.']])]), ['activeNav' => 'requesters', 'screen' => 'requesters-list', 'entity' => 'requester']);
