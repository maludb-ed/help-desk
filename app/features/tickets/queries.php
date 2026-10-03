<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/views/filters.php';     // slice 4: the one translator of filters to SQL

/**
 * Tickets — the reads (slice 3, THE EXEMPLAR). Every list and page reads mcp_tickets and its sibling views: the database decides what the
 * caller sees (db/014), so a screen and a tool can never disagree. The filter vocabulary is the saved views' (db/011).
 */

const PRIORITY_COLORS = ['urgent' => 'danger', 'high' => 'warning', 'normal' => 'info', 'low' => 'secondary'];
const RESOLUTION_CODES = ['resolved' => 'Resolved', 'workaround' => 'Workaround', 'no_action' => 'No action needed', 'duplicate' => 'Duplicate', 'spam' => 'Spam', 'cannot_reproduce' => 'Cannot reproduce'];
const HOLD_REASONS = ['awaiting_requester' => 'Waiting for the requester', 'awaiting_third_party' => 'Waiting for a third party', 'awaiting_internal' => 'Waiting on someone here', 'awaiting_problem' => 'Waiting for the problem to be solved'];
const LINK_KINDS = ['parent_child' => 'Parent of', 'related' => 'Related to', 'duplicate_of' => 'Duplicate of', 'blocks' => 'Blocks', 'follow_up_of' => 'Follow-up of', 'merged_into' => 'Merged into'];
const TICKET_SORTS = ['last_activity' => 't.last_activity_at DESC', 'sla_due' => 't.sla_next_due_at ASC NULLS LAST, t.last_activity_at DESC',
    'priority' => "CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END, t.last_activity_at DESC", 'created_at' => 't.created_at DESC', 'updated_at' => 't.updated_at DESC'];
const OPEN_CATEGORIES = ['new', 'open', 'pending', 'on_hold'];

function ticket_cast(array $t): array
{
    foreach (['ticket_id', 'requester_id', 'submitter_member_id', 'organization_id', 'queue_id', 'assignee_member_id', 'status_id', 'type_id', 'category_id', 'parent_id', 'merged_into_id', 'board_position', 'reopen_count', 'sla_policy_id', 'created_by', 'reply_count'] as $k) {
        if (array_key_exists($k, $t)) {
            $t[$k] = $t[$k] === null ? null : (int) $t[$k];
        }
    }
    $t['needs_reply'] = (bool) ($t['needs_reply'] ?? false);
    $t['tags'] = pg_text_array((string) ($t['tags'] ?? '{}'));
    $t['custom_values'] = is_array($t['custom_values'] ?? null) ? $t['custom_values'] : (json_decode((string) ($t['custom_values'] ?? '{}'), true) ?: []);
    return $t;
}

/** The WHERE of the saved-view vocabulary on mcp_tickets t: [sql, args]. `me` resolves to the caller. */
function ticket_where(array $f, int $memberId): array
{
    return view_filters_sql($f, $memberId);                      // slice 4: the ONE translator (app/features/views/filters.php)
}

/** One page of tickets the caller may see, filtered and sorted. */
function find_tickets_filtered(PDO $pdo, array $filters, int $memberId, int $limit = 50, int $offset = 0): array
{
    [$where, $args] = ticket_where($filters, $memberId);
    $sort = view_sort_sql((string) ($filters['sort_by'] ?? ($filters['sort'] ?? 'last_activity_at')), (string) ($filters['sort_dir'] ?? 'desc'));
    $st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE ' . $where . ' ORDER BY ' . $sort . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset);
    $st->execute($args);
    return array_map('ticket_cast', $st->fetchAll());
}

function count_tickets_filtered(PDO $pdo, array $filters, int $memberId): int
{
    [$where, $args] = ticket_where($filters, $memberId);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_tickets t WHERE ' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** The ticket in full (what the page and get_ticket show), or null when the caller may not see it. */
function find_ticket(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT t.*, p.name AS policy_name FROM mcp_tickets t LEFT JOIN sla_policies p ON p.id = t.sla_policy_id WHERE t.ticket_id = :id');
    $st->execute(['id' => $id]);
    $t = $st->fetch();
    if ($t === false) {
        return null;
    }
    $t = ticket_cast($t);
    $f = $pdo->prepare('SELECT kind, member_id, member_name, requester_id, requester_name, created_at FROM mcp_ticket_followers WHERE ticket_id = :id ORDER BY kind, created_at');
    $f->execute(['id' => $id]);
    $t['followers'] = array_map(static fn (array $r): array => $r + ['member_id' => $r['member_id'] === null ? null : (int) $r['member_id'], 'requester_id' => $r['requester_id'] === null ? null : (int) $r['requester_id']], $f->fetchAll());
    $l = $pdo->prepare('SELECT link_id, from_ticket_id, to_ticket_id, kind, to_number, to_subject FROM mcp_ticket_links WHERE from_ticket_id = :id ORDER BY kind, link_id');
    $l->execute(['id' => $id]);
    $t['links'] = array_map(static fn (array $r): array => ['link_id' => (int) $r['link_id'], 'kind' => $r['kind'], 'ticket_id' => (int) $r['to_ticket_id'], 'number' => $r['to_number'], 'subject' => $r['to_subject'], 'direction' => 'to'], $l->fetchAll());
    $b = $pdo->prepare('SELECT l.link_id, l.from_ticket_id, l.kind, t2.number, t2.subject FROM mcp_ticket_links l JOIN mcp_tickets t2 ON t2.ticket_id = l.from_ticket_id WHERE l.to_ticket_id = :id ORDER BY l.kind, l.link_id');
    $b->execute(['id' => $id]);
    foreach ($b->fetchAll() as $r) {
        $t['links'][] = ['link_id' => (int) $r['link_id'], 'kind' => $r['kind'], 'ticket_id' => (int) $r['from_ticket_id'], 'number' => $r['number'], 'subject' => $r['subject'], 'direction' => 'from'];
    }
    $g = $pdo->prepare('SELECT target_id, metric, policy_name, target_minutes, started_at, due_at, paused_at, met_at, breached_at, state FROM mcp_sla_targets WHERE ticket_id = :id ORDER BY met_at NULLS FIRST, due_at');
    $g->execute(['id' => $id]);
    $t['targets'] = $g->fetchAll();
    $e = $pdo->prepare('SELECT entry_id, member_id, display_name, minutes, billable, note, worked_on, created_at FROM mcp_time_entries WHERE ticket_id = :id ORDER BY worked_on DESC, entry_id DESC');
    $e->execute(['id' => $id]);
    $t['time'] = array_map(static fn (array $r): array => $r + ['entry_id' => (int) $r['entry_id'], 'member_id' => (int) $r['member_id'], 'minutes' => (int) $r['minutes'], 'billable' => (bool) $r['billable']], $e->fetchAll());
    $t['time_total'] = array_sum(array_column($t['time'], 'minutes'));
    $v = $pdo->prepare('SELECT member_id, display_name, composing, last_seen_at FROM mcp_ticket_viewers WHERE ticket_id = :id AND member_id <> app_current_member_id() ORDER BY display_name');
    $v->execute(['id' => $id]);
    $t['viewers'] = array_map(static fn (array $r): array => $r + ['member_id' => (int) $r['member_id'], 'composing' => (bool) $r['composing']], $v->fetchAll());
    $s = $pdo->prepare('SELECT count(*) FILTER (WHERE rotated_at IS NULL) AS live, max(expires_at) FILTER (WHERE rotated_at IS NULL) AS expires_at, max(last_used_at) FILTER (WHERE rotated_at IS NULL) AS last_used_at, count(*) FILTER (WHERE rotated_at IS NOT NULL) AS rotated FROM ticket_links_secure WHERE ticket_id = :id');
    $s->execute(['id' => $id]);
    $sl = $s->fetch();
    $t['secure_link'] = ['live' => (int) $sl['live'] > 0, 'expires_at' => $sl['expires_at'], 'last_used_at' => $sl['last_used_at'], 'rotated' => (int) $sl['rotated']];
    return $t;
}

function find_ticket_by_number(PDO $pdo, string $number): ?array
{
    $st = $pdo->prepare('SELECT ticket_id FROM mcp_tickets WHERE upper(number) = upper(:n)');
    $st->execute(['n' => trim($number)]);
    $id = $st->fetchColumn();
    return $id === false ? null : find_ticket($pdo, (int) $id);
}

/** The ticket an agent's external_id names (the base table: the unique index is the backstop), or null. */
function find_ticket_by_external_id(PDO $pdo, string $externalId): ?int
{
    $st = $pdo->prepare('SELECT id FROM tickets WHERE external_id = :e');
    $st->execute(['e' => $externalId]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** The base facts of a ticket for a handler's gate (number, queue, status, requester) — the caller's visibility is checked by the caller. */
function ticket_facts(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT t.id AS ticket_id, t.number, t.subject, t.queue_id, t.status_id, s.key AS status, s.category AS status_category, t.requester_id, r.member_id AS requester_member_id, r.kind AS requester_kind,
                                r.display_name AS requester_name, r.primary_email::text AS requester_email, t.assignee_member_id, t.organization_id, t.solved_at, t.closed_at, t.merged_into_id, t.priority, t.type_id, t.category_id, t.due_at, t.external_id, t.custom_values
                           FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN requesters r ON r.id = t.requester_id WHERE t.id = :id');
    $st->execute(['id' => $id]);
    $t = $st->fetch();
    if ($t === false) {
        return null;
    }
    foreach (['ticket_id', 'queue_id', 'status_id', 'requester_id', 'requester_member_id', 'assignee_member_id', 'organization_id', 'merged_into_id', 'type_id', 'category_id'] as $k) {
        $t[$k] = $t[$k] === null ? null : (int) $t[$k];
    }
    $t['custom_values'] = json_decode((string) $t['custom_values'], true) ?: [];
    return $t;
}

/** The conversation: public replies, and the notes when the caller may see internals; each message with its attachments. */
function ticket_conversation(PDO $pdo, int $id, bool $withNotes): array
{
    $st = $pdo->prepare('SELECT message_id, ticket_id, kind, author_member_id, author_member_name, author_kind, author_requester_id, author_requester_name, body_text, channel, mentions, edited_at, created_at, attachment_count
                           FROM mcp_messages WHERE ticket_id = :id' . ($withNotes ? '' : " AND kind = 'public_reply'") . ' ORDER BY created_at, message_id');
    $st->execute(['id' => $id]);
    $rows = $st->fetchAll();
    $att = $pdo->prepare('SELECT attachment_id, message_id, filename, mime_type, byte_size FROM mcp_attachments WHERE ticket_id = :id AND message_id IS NOT NULL ORDER BY attachment_id');
    $att->execute(['id' => $id]);
    $byMessage = [];
    foreach ($att->fetchAll() as $a) {
        $byMessage[(int) $a['message_id']][] = ['attachment_id' => (int) $a['attachment_id'], 'filename' => $a['filename'], 'mime_type' => $a['mime_type'], 'byte_size' => (int) $a['byte_size']];
    }
    foreach ($rows as &$m) {
        $m['message_id'] = (int) $m['message_id'];
        $m['author_member_id'] = $m['author_member_id'] === null ? null : (int) $m['author_member_id'];
        $m['author_requester_id'] = $m['author_requester_id'] === null ? null : (int) $m['author_requester_id'];
        $m['mentions'] = pg_int_array((string) $m['mentions']);
        $m['attachments'] = $byMessage[$m['message_id']] ?? [];
    }
    unset($m);
    return $rows;
}

/** The ticket's history from the activity log, newest first (the view decides which rows; present.php words them). */
function ticket_history(PDO $pdo, int $id, int $limit = 100): array
{
    $st = $pdo->prepare('SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_requester_id, source, action, entity_type, entity_id, before, after, agent_run_id
                           FROM mcp_activity_log WHERE ticket_id = :id AND action <> \'screen.view\' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (int) $limit);
    $st->execute(['id' => $id]);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['before'] = $r['before'] === null ? null : json_decode((string) $r['before'], true);
        $r['after'] = $r['after'] === null ? null : json_decode((string) $r['after'], true);
    }
    unset($r);
    return $rows;
}

/** Every live status the ticket may move to from where it is, with what the move requires and whether the caller may make it. */
function ticket_allowed_moves(PDO $pdo, int $ticketId): array
{
    $t = ticket_facts($pdo, $ticketId);
    if ($t === null) {
        return [];
    }
    $statuses = $pdo->query('SELECT status_id, key, label, category, color FROM mcp_ticket_statuses WHERE archived_at IS NULL ORDER BY sort_order')->fetchAll();
    $out = [];
    $work = can_work_ticket($ticketId);
    foreach ($statuses as $s) {
        if ((int) $s['status_id'] === $t['status_id']) {
            continue;
        }
        $st = $pdo->prepare('SELECT * FROM hd_transition(:a, :b)');
        $st->execute(['a' => $t['status_id'], 'b' => (int) $s['status_id']]);
        $r = $st->fetch();
        if ($r === false) {
            continue;
        }
        $out[] = ['status_id' => (int) $s['status_id'], 'key' => $s['key'], 'label' => $s['label'], 'category' => $s['category'], 'color' => $s['color'], 'move_label' => $r['label'],
                  'requires' => pg_text_array((string) $r['requires']), 'may_carry' => pg_text_array((string) $r['may_carry']), 'needs_right' => $r['needs_right'], 'may' => $work && has_right($r['needs_right'])];
    }
    return $out;
}

/** Tickets like this one: the same requester or organization, or words of the subject; open first. */
function similar_tickets(PDO $pdo, int $ticketId, int $limit = 8): array
{
    $t = ticket_facts($pdo, $ticketId);
    if ($t === null) {
        return [];
    }
    $words = array_values(array_filter(preg_split('/\W+/', strtolower($t['subject'])) ?: [], static fn (string $w): bool => mb_strlen($w) >= 4));
    $clauses = ['t.requester_id = :r'];
    $args = ['r' => $t['requester_id'], 'id' => $ticketId];
    if ($t['organization_id'] !== null) {
        $clauses[] = 't.organization_id = :o';
        $args['o'] = $t['organization_id'];
    }
    foreach (array_slice($words, 0, 3) as $i => $w) {
        $clauses[] = "t.subject ILIKE :w$i";
        $args["w$i"] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $w) . '%';
    }
    $st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE t.ticket_id <> :id AND t.merged_into_id IS NULL AND (' . implode(' OR ', $clauses) . ')
                         ORDER BY (t.status_category IN (\'solved\', \'closed\')), t.last_activity_at DESC LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map('ticket_cast', $st->fetchAll());
}

/** A requester as the card shows them, with their last five tickets. */
function requester_summary(PDO $pdo, int $requesterId): ?array
{
    $st = $pdo->prepare('SELECT r.requester_id, r.kind, r.member_id, r.display_name, r.organization_id, r.organization_name, r.email, r.phone, r.notes, r.ticket_count, r.last_seen_at, o.support_tier
                           FROM mcp_requesters r LEFT JOIN organizations o ON o.id = r.organization_id WHERE r.requester_id = :id');
    $st->execute(['id' => $requesterId]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['requester_id', 'member_id', 'organization_id', 'ticket_count'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $l = $pdo->prepare('SELECT ticket_id, number, subject, status, status_label, status_color, created_at FROM mcp_tickets WHERE requester_id = :id ORDER BY created_at DESC LIMIT 5');
    $l->execute(['id' => $requesterId]);
    $r['last_tickets'] = array_map(static fn (array $x): array => $x + ['ticket_id' => (int) $x['ticket_id']], $l->fetchAll());
    return $r;
}

/** Who may be assigned in a queue: its members, people and agents (the view's rule). [{member_id, display_name, member_kind, is_lead}] */
function queue_assignees(PDO $pdo, int $queueId): array
{
    $st = $pdo->prepare("SELECT DISTINCT member_id, display_name, member_kind, bool_or(is_lead) AS is_lead FROM mcp_queue_members WHERE queue_id = :q GROUP BY member_id, display_name, member_kind ORDER BY member_kind DESC, display_name");
    $st->execute(['q' => $queueId]);
    return array_map(static fn (array $r): array => $r + ['member_id' => (int) $r['member_id'], 'is_lead' => (bool) $r['is_lead']], $st->fetchAll());
}

/** Is the member in the queue (named, or through its department)? The base tables: a writer's check, not the caller's view. */
function member_in_queue(PDO $pdo, int $memberId, int $queueId): bool
{
    $st = $pdo->prepare("SELECT 1 FROM queues q WHERE q.id = :q AND q.archived_at IS NULL
                           AND (EXISTS (SELECT 1 FROM queue_members qm WHERE qm.queue_id = q.id AND qm.member_id = :m)
                                OR (q.include_department AND EXISTS (SELECT 1 FROM department_members dm JOIN members m ON m.id = dm.member_id
                                        WHERE dm.department_id = q.department_id AND dm.member_id = :m2 AND dm.left_at IS NULL AND m.status = 'active' AND m.capability IS NOT NULL)))");
    $st->execute(['q' => $queueId, 'm' => $memberId, 'm2' => $memberId]);
    return $st->fetchColumn() !== false;
}

/** The seeded (system) views for the inbox's tabs. */
function find_system_views(PDO $pdo): array
{
    $rows = $pdo->query('SELECT view_id, name, system_key, filters, layout, sort_order FROM mcp_saved_views WHERE is_system ORDER BY sort_order')->fetchAll();
    foreach ($rows as &$v) {
        $v['view_id'] = (int) $v['view_id'];
        $v['filters'] = json_decode((string) $v['filters'], true) ?: [];
    }
    unset($v);
    return $rows;
}

/** What needs the caller (my_queue's shape): their open tickets — needs reply first, then by SLA due — then the unassigned of their queues. */
function home_needs_me(PDO $pdo, int $memberId, int $limit = 10): array
{
    $mine = find_tickets_filtered($pdo, ['assignee' => 'me', 'status_category' => OPEN_CATEGORIES, 'sort' => 'sla_due'], $memberId, $limit);
    usort($mine, static fn (array $a, array $b): int => [$b['needs_reply'], $a['sla_next_due_at'] ?? '9'] <=> [$a['needs_reply'], $b['sla_next_due_at'] ?? '9']);
    foreach ($mine as &$t) {
        $t['reason'] = $t['needs_reply'] ? 'needs your reply' : 'assigned to you';
    }
    unset($t);
    $rest = $limit - count($mine);
    if ($rest > 0) {
        $un = find_tickets_filtered($pdo, ['assignee' => 'none', 'status_category' => ['new', 'open'], 'sort' => 'sla_due'], $memberId, $rest);
        foreach ($un as $t) {
            $t['reason'] = 'unassigned in ' . $t['queue_name'];
            $mine[] = $t;
        }
    }
    return $mine;
}

/** The caller's own open tickets, newest change first. */
function home_my_tickets(PDO $pdo, int $memberId, int $limit = 5): array
{
    return find_tickets_filtered($pdo, ['requester' => 'me', 'status_category' => OPEN_CATEGORIES], $memberId, $limit);
}
