<?php
declare(strict_types=1);
/** /tickets/{id} — one ticket (screen `ticket-view`): the conversation and composer, the fields, the requester card, followers, links, time, SLA, the timeline. A ticket the caller may not see is "Ticket not found." */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_login();
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('ticket') ?? refuse(404, 'Ticket not found.');
$facts = ticket_load($pdo, $id);
$t = find_ticket($pdo, $id) ?? refuse(404, 'Ticket not found.');
$internals = can_see_internals($id);
$work = can_work_ticket($id);
$own = is_own_ticket($pdo, $facts);
$note = can_note_ticket($pdo, $facts);
$conversation = ticket_conversation($pdo, $id, $internals);
$moves = ticket_allowed_moves($pdo, $id);
$requester = requester_summary($pdo, $facts['requester_id']) ?? ['requester_id' => $facts['requester_id'], 'kind' => $facts['requester_kind'], 'display_name' => $facts['requester_name'], 'organization_id' => null, 'organization_name' => null, 'email' => null, 'phone' => null, 'notes' => null, 'support_tier' => null, 'last_tickets' => []];
$history = ticket_history($pdo, $id);
log_activity($pdo, 'screen.view', null, null, ['screen' => 'ticket-view', 'ticket_id' => $id, 'queue_id' => $facts['queue_id'], 'after' => ['ticket_id' => $id]]);
$pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND ticket_id = :t AND read_at IS NULL')->execute(['m' => (int) current_member_id(), 't' => $id]);   // slice 7: opening the ticket reads its notices
if (wants_json()) {
    respond_screen(['ticket' => present_ticket($t, $internals), 'conversation' => array_map('present_message', $conversation), 'moves' => array_map('present_move', $moves),
        'requester' => present_requester_brief(($internals ? $requester : ['email' => null, 'phone' => null] + $requester) + ['ticket_count' => $requester['ticket_count'] ?? 0]) + ['organization' => $requester['organization_name'], 'notes' => $internals ? ($requester['notes'] ?? null) : null],   // the address and notes: this queue's holders only
        'history' => array_values(array_map(static fn (array $r): array => ['at' => json_ts($r['occurred_at']), 'action' => $r['action'], 'words' => history_sentence($r), 'source' => $r['source']], array_filter($history, static fn (array $r): bool => $internals || !history_is_internal($r)))),
        'may' => ['work' => $work, 'note' => $note, 'own' => $own, 'internals' => $internals]]);
}
$data = ['t' => $t, 'facts' => $facts, 'conversation' => $conversation, 'moves' => $moves, 'requester' => $requester, 'history' => $history, 'internals' => $internals, 'work' => $work, 'own' => $own, 'note' => $note,
         'assignees' => $work ? queue_assignees($pdo, $facts['queue_id']) : [], 'queues' => $work ? find_queues($pdo) : [], 'pending' => pending_attachments($pdo, $id, (int) current_member_id()),
         'members' => $note ? find_assignable_members($pdo) : [], 'types' => $work ? find_types($pdo) : [], 'categories' => $work ? find_category_tree($pdo) : [], 'fieldDefs' => $work ? fields_for($pdo, 'ticket', $facts['type_id']) : [],
         'tz' => member_timezone(), 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, ['saved' => ['success', 'Saved.'], 'sent' => ['success', 'Sent.'], 'noted' => ['success', 'Noted.'], 'merged' => ['success', 'Merged.'], 'attached' => ['success', 'Attached — it goes with your next message.']])];
render_screen($t['number'] . ' ' . $t['subject'], view('tickets/view.php', $data), ['activeNav' => $internals ? 'inbox' : 'my-tickets', 'screen' => 'ticket-view', 'entity' => 'ticket', 'recordId' => (string) $id]);
