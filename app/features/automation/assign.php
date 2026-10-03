<?php
declare(strict_types=1);

/** Assignment methods (slice 8): on arrival in a queue — manual, the default assignee, round-robin, least-loaded — from the base tables (presence and capacity from queue_presence). */

/** The queue's members: named plus the department's when the queue includes it; active, admitted; with presence and capacity. [{member_id, member_kind, availability, capacity}] */
function queue_member_rows(PDO $pdo, int $queueId): array
{
    $st = $pdo->prepare("SELECT DISTINCT m.id AS member_id, m.member_kind, COALESCE(p.availability, 'online') AS availability, p.capacity
                           FROM members m
                           LEFT JOIN queue_presence p ON p.member_id = m.id
                          WHERE m.status = 'active' AND m.capability IS NOT NULL
                            AND (EXISTS (SELECT 1 FROM queue_members qm WHERE qm.queue_id = :q AND qm.member_id = m.id)
                                 OR EXISTS (SELECT 1 FROM queues q JOIN department_members dm ON dm.department_id = q.department_id AND dm.left_at IS NULL WHERE q.id = :q AND q.include_department AND dm.member_id = m.id))
                          ORDER BY m.id");
    $st->execute(['q' => $queueId]);
    return array_map(static fn (array $r): array => ['member_id' => (int) $r['member_id'], 'member_kind' => $r['member_kind'], 'availability' => $r['availability'], 'capacity' => $r['capacity'] === null ? null : (int) $r['capacity']], $st->fetchAll());
}

function member_open_count(PDO $pdo, int $memberId): int
{
    $st = $pdo->prepare("SELECT count(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.assignee_member_id = :m AND s.category IN ('new', 'open', 'pending', 'on_hold') AND t.merged_into_id IS NULL");
    $st->execute(['m' => $memberId]);
    return (int) $st->fetchColumn();
}

/** Not offline and under capacity. */
function queue_eligible_members(PDO $pdo, int $queueId): array
{
    $out = [];
    foreach (queue_member_rows($pdo, $queueId) as $m) {
        if ($m['availability'] === 'offline') {
            continue;
        }
        $open = member_open_count($pdo, $m['member_id']);
        if ($m['capacity'] !== null && $open >= $m['capacity']) {
            continue;
        }
        $out[] = $m + ['open' => $open];
    }
    return $out;
}

/** The next eligible member after the last one assigned by round-robin in this queue. */
function pick_round_robin(PDO $pdo, int $queueId): ?int
{
    $members = queue_eligible_members($pdo, $queueId);
    if ($members === []) {
        return null;
    }
    $st = $pdo->prepare("SELECT (after->>'assignee_member_id')::bigint FROM activity_log WHERE action = 'ticket.assign' AND queue_id = :q AND after->>'via' = 'round_robin' AND after->>'assignee_member_id' IS NOT NULL ORDER BY id DESC LIMIT 1");
    $st->execute(['q' => $queueId]);
    $last = $st->fetchColumn();
    $ids = array_column($members, 'member_id');
    if ($last === false || $last === null) {
        return $ids[0];
    }
    foreach ($ids as $id) {
        if ($id > (int) $last) {
            return $id;
        }
    }
    return $ids[0];
}

/** The eligible member with the fewest open tickets (the lowest id on a tie). */
function pick_least_loaded(PDO $pdo, int $queueId): ?int
{
    $members = queue_eligible_members($pdo, $queueId);
    if ($members === []) {
        return null;
    }
    usort($members, static fn (array $a, array $b): int => $a['open'] <=> $b['open'] ?: $a['member_id'] <=> $b['member_id']);
    return $members[0]['member_id'];
}

function queue_default_assignee(PDO $pdo, int $queueId): ?int
{
    $st = $pdo->prepare("SELECT q.default_assignee_member_id FROM queues q JOIN members m ON m.id = q.default_assignee_member_id AND m.status = 'active' WHERE q.id = :q");
    $st->execute(['q' => $queueId]);
    $v = $st->fetchColumn();
    return $v === false || $v === null ? null : (int) $v;
}

/**
 * On a ticket's arrival in a queue (create, a queue change with no assignee named): the queue's method decides. Writes the assignment and its ticket.assign row (after.via = the method).
 * Answers the assignee's id, or null. The caller dispatches an agent assignee once its transaction is committed.
 */
function assign_on_arrival(PDO $pdo, int $ticketId, string $why): ?int
{
    $t = ticket_facts($pdo, $ticketId);
    if ($t === null || $t['assignee_member_id'] !== null) {
        return $t['assignee_member_id'] ?? null;
    }
    $method = (string) one_value($pdo, 'SELECT assignment_method FROM queues WHERE id = :q', ['q' => $t['queue_id']]);
    $pick = match ($method) { 'default_assignee' => queue_default_assignee($pdo, (int) $t['queue_id']), 'round_robin' => pick_round_robin($pdo, (int) $t['queue_id']), 'least_loaded' => pick_least_loaded($pdo, (int) $t['queue_id']), default => null };
    if ($pick === null) {
        return null;
    }
    $via = $method === 'default_assignee' ? 'default' : $method;
    $r = assign_ticket($pdo, $ticketId, $pick, null, 0, $via);
    if ($r['changed']) {
        ticket_log($pdo, $t, 'ticket.assign', ['before' => $r['before'], 'after' => $r['after'] + ['why' => $why]]);
        if ($r['after']['assignee_kind'] !== 'agent') {
            notify($pdo, ['member_id' => $pick], 'assigned', $ticketId, $t['number'] . ' is yours: ' . $t['subject'], $t['number'] . ' "' . $t['subject'] . '" was assigned to you. See it here: ' . app_url('/tickets/' . $ticketId), null, 'assigned:' . $ticketId . ':' . $pick);
        }
    }
    return $pick;
}

/** The current assignment as assign_ticket() describes it (after an arrival pick). */
function assign_ticket_state(PDO $pdo, int $ticketId): array
{
    $st = $pdo->prepare('SELECT t.assignee_member_id, m.display_name AS assignee_name, m.member_kind AS assignee_kind, t.queue_id, q.name AS queue_name FROM tickets t LEFT JOIN members m ON m.id = t.assignee_member_id JOIN queues q ON q.id = t.queue_id WHERE t.id = :t');
    $st->execute(['t' => $ticketId]);
    $r = $st->fetch() ?: [];
    return ['assignee_member_id' => isset($r['assignee_member_id']) ? (int) $r['assignee_member_id'] : null, 'assignee_name' => $r['assignee_name'] ?? null, 'assignee_kind' => $r['assignee_kind'] ?? null, 'queue_id' => (int) ($r['queue_id'] ?? 0), 'queue_name' => $r['queue_name'] ?? null, 'via' => 'arrival'];
}
