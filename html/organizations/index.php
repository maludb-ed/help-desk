<?php
declare(strict_types=1);
/** /organizations/ — the customer companies as cards (screen `organizations-list`; tickets.view_queue; params q, tier). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/present.php';
require_right('tickets.view_queue');
require_human();
$pdo = db();
$filters = ['q' => mb_substr(request_string('q'), 0, 80), 'tier' => mb_substr(request_string('tier'), 0, 40)];
$rows = find_organizations($pdo, $filters);
log_screen_view($pdo, 'organizations-list');
if (wants_json()) {
    respond_screen(['filters' => $filters, 'organizations' => array_map('present_organization', $rows)]);
}
render_screen('Organizations', view('organizations/list.php', ['rows' => $rows, 'tiers' => find_tiers($pdo), 'filters' => $filters, 'here' => here_url(),
    'notice' => hd_notice($_GET['notice'] ?? null, ['archived' => ['success', 'The company was archived.']])]), ['activeNav' => 'organizations', 'screen' => 'organizations-list', 'entity' => 'organization']);
