<?php
declare(strict_types=1);

/**
 * Handing a ticket to an agent (design §5): a dispatch row, then ONE turn of the agent through the kernel's chat endpoint as the acting person.
 * Called AFTER the assignment is committed — the assignment never waits for or fails on the dispatch. Under a run token (no acting person)
 * no call is made: via = duty, the agent's own duty picks it up (my_queue).
 */
function dispatch_to_agent(PDO $pdo, int $ticketId, int $agentId, string $kind, ?int $actingMemberId): array
{
    $t = ticket_facts($pdo, $ticketId);
    $via = $actingMemberId === null ? 'duty' : 'chat';
    $st = $pdo->prepare('INSERT INTO agent_dispatches (ticket_id, agent_member_id, kind, via, acting_member_id, status) VALUES (:t, :a, :k, :v, :by, :s) RETURNING id');
    $st->execute(['t' => $ticketId, 'a' => $agentId, 'k' => $kind, 'v' => $via, 'by' => $actingMemberId, 's' => 'sent']);
    $id = (int) $st->fetchColumn();
    $row = ['dispatch_id' => $id, 'agent_member_id' => $agentId, 'via' => $via, 'status' => 'sent', 'run_id' => null, 'reply_excerpt' => null, 'detail' => null];
    if ($via === 'duty' || $t === null) {
        return $row;
    }
    $utterance = 'Ticket ' . $t['number'] . ' was assigned to you: ' . $t['subject'] . '. Work it with my_queue and get_ticket.';
    $answer = kernel_call('POST', '/api/v1/agents/chat.php?agent=' . $agentId, ['utterance' => $utterance, 'screen' => 'ticket-view', 'context' => ['application' => app_key(), 'entity' => 'ticket', 'record_id' => $ticketId], 'wait' => 5],
        ['X-Acting-Member: ' . $actingMemberId], 12);
    if ($answer === null) {
        $row['status'] = 'failed';
        $row['detail'] = 'The kernel could not be reached.';
    } elseif ($answer['status'] >= 400) {
        $row['status'] = $answer['status'] === 403 ? 'refused' : 'failed';
        $row['detail'] = (string) ($answer['body']['error']['message'] ?? ('The kernel answered ' . $answer['status'] . '.'));
    } else {
        $b = $answer['body'] ?? [];
        $row['run_id'] = isset($b['run_id']) ? (int) $b['run_id'] : null;
        $row['status'] = ($b['status'] ?? '') === 'awaiting_approval' ? 'awaiting_approval' : (!empty($b['finished']) ? 'answered' : 'sent');
        $row['reply_excerpt'] = isset($b['reply']) ? excerpt((string) $b['reply'], 200) : null;
    }
    $pdo->prepare('UPDATE agent_dispatches SET status = :s, run_id = :r, request_id = :rq, reply_excerpt = :e, detail = :d, answered_at = CASE WHEN :s2 IN (\'answered\', \'refused\', \'failed\') THEN now() END WHERE id = :id')
        ->execute(['s' => $row['status'], 'r' => $row['run_id'], 'rq' => $answer['request_id'] ?? null, 'e' => $row['reply_excerpt'], 'd' => $row['detail'], 's2' => $row['status'], 'id' => $id]);
    return $row;
}
