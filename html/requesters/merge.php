<?php
declare(strict_types=1);
/**
 * GET: the merge page for a requester (a search, then the confirm page). POST = action `requester_merge` (log `requester.merge`: after.into_requester_id, tickets, emails; confirm):
 * tickets.manage. `requester` goes away, `into` stays. A member into an external is refused; a row into itself too.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/present.php';
require_right('tickets.manage');
$pdo = db();
$id = request_integer('requester') ?? request_integer('requester_id') ?? refuse(422, 'Say which requester.');
$r = find_requester($pdo, $id) ?? refuse(404, 'Requester not found.');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    $q = mb_substr(request_string('q'), 0, 80);
    $intoId = request_integer('into');
    $into = $intoId === null ? null : (find_requester($pdo, $intoId) ?? refuse(404, 'Requester not found.'));
    $candidates = $q === '' ? [] : find_requesters($pdo, ['q' => $q]);
    log_screen_view($pdo, 'requester-merge');
    if (wants_json()) {
        respond_screen(['requester' => present_requester($r), 'candidates' => array_map('present_requester_brief', $candidates), 'into' => $into === null ? null : present_requester_brief($into)]);
    }
    render_screen('Merge ' . $r['display_name'], view('requesters/merge.php', ['r' => $r, 'candidates' => $candidates, 'q' => $q, 'into' => $into, 'here' => here_url()]),
        ['activeNav' => 'requesters', 'screen' => 'requester-merge', 'entity' => 'requester', 'recordId' => (string) $id]);
    exit;
}
hd_handler_begin();
$me = (int) current_member_id();
$intoId = request_integer('into') ?? request_integer('into_requester') ?? refuse(422, 'Say who stays (into).');
$into = find_requester($pdo, $intoId) ?? refuse(404, 'Requester not found.');
$counts = hd_guard($pdo, static function () use ($pdo, $me, $id, $intoId): array {
    $pdo->beginTransaction();
    $counts = merge_requesters($pdo, $id, $intoId, $me);
    log_activity($pdo, 'requester.merge', 'requester', $id, ['after' => ['into_requester_id' => $intoId, 'tickets' => $counts['tickets'], 'emails' => $counts['emails'], 'followers' => $counts['followers'], 'ratings' => $counts['ratings']]]);
    $pdo->commit();
    return $counts;
});
hd_done('Merged ' . $r['display_name'] . ' into ' . $into['display_name'] . ' — ' . $counts['tickets'] . ' ticket' . ($counts['tickets'] === 1 ? '' : 's') . ' and ' . $counts['emails'] . ' address' . ($counts['emails'] === 1 ? '' : 'es') . ' moved', $intoId,
    hd_land('/requesters/' . $intoId, 'merged'), 'requesterChanged', ['into_requester_id' => $intoId] + $counts);
