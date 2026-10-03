<?php
declare(strict_types=1);
/**
 * GET /tickets/{id}/merge — the target picker (screen `ticket-merge`; params into, q): similar tickets suggested, a number or words searched, then the confirm.
 * POST = action `ticket_merge` (log `ticket.merge` on both; confirm): tickets.manage, both workable, not the same, the target not closed.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_login();
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_human();
    require_right('tickets.manage');
    $facts = ticket_from_request($pdo, 'id', 'ticket');
    $intoId = request_integer('into');
    $into = $intoId === null ? null : ticket_load($pdo, $intoId);
    $q = mb_substr(request_string('q'), 0, 80);
    $found = $q === '' ? [] : find_tickets_filtered($pdo, ['q' => $q], (int) current_member_id(), 20);
    $suggestions = similar_tickets($pdo, $facts['ticket_id']);
    log_screen_view($pdo, 'ticket-merge');
    if (wants_json()) {
        respond_screen(['ticket' => ['ticket_id' => $facts['ticket_id'], 'number' => $facts['number']], 'into' => $into === null ? null : ['ticket_id' => $into['ticket_id'], 'number' => $into['number']],
            'suggestions' => array_map('present_ticket_brief', $suggestions), 'found' => array_map('present_ticket_brief', $found)]);
    }
    render_screen('Merge ' . $facts['number'], view('tickets/merge.php', ['facts' => $facts, 'suggestions' => $suggestions, 'into' => $into, 'q' => $q, 'found' => $found, 'here' => here_url()]),
        ['activeNav' => 'inbox', 'screen' => 'ticket-merge', 'entity' => 'ticket', 'recordId' => (string) $facts['ticket_id']]);
    exit;
}
hd_handler_begin();
require_right('tickets.manage');
$me = (int) current_member_id();
$source = ticket_from_request($pdo, 'ticket', 'ticket_id');
$targetId = request_integer('merge_into') ?? request_integer('into') ?? refuse(422, 'Say which ticket stays (merge_into).');
$target = ticket_load($pdo, $targetId);
require_work($source);
require_work($target);
$note = (string) req_val('note') === '' ? null : mb_substr((string) req_val('note'), 0, 1000);
$r = hd_guard($pdo, static function () use ($pdo, $me, $source, $target, $note): array {
    $pdo->beginTransaction();
    $r = merge_tickets($pdo, $source['ticket_id'], $target['ticket_id'], $me, $note);
    ticket_log($pdo, $source, 'ticket.merge', ['after' => ['into_ticket_id' => $target['ticket_id'], 'into_number' => $target['number'], 'messages_moved' => $r['messages']]]);
    ticket_log($pdo, $target, 'ticket.merge', ['after' => ['from_ticket_id' => $source['ticket_id'], 'from_number' => $source['number'], 'messages_moved' => $r['messages']]]);
    $pdo->commit();
    return $r;
});
hd_done('Merged ' . $source['number'] . ' into ' . $target['number'] . ' — ' . $r['messages'] . ' message' . ($r['messages'] === 1 ? '' : 's') . ' moved', $target['ticket_id'], hd_land('/tickets/' . $target['ticket_id'], 'merged'), 'ticketChanged',
    ['ticket_id' => $target['ticket_id'], 'into_ticket_id' => $target['ticket_id'], 'messages_moved' => $r['messages'], 'attachments_moved' => $r['attachments']]);
