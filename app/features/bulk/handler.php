<?php
declare(strict_types=1);

/** bulk_apply(): the changes to each ticket in its own transaction through slice 3's functions — one refusal never rolls the others back. */
require_once __DIR__ . '/queries.php';

/**
 * $changes: status, resolution_code, hold_reason, pending_until, assignee (keep|none|me|<id>), queue (id|null), priority, add_tags[], remove_tags[], note.
 * Answers [changed: [ids], refused: [{ticket_id, number, why}], dispatch: [agent ids to dispatch per ticket]].
 */
function bulk_apply(PDO $pdo, array $ids, array $changes, int $by): array
{
    $changed = [];
    $refused = [];
    $dispatch = [];
    foreach (array_values(array_unique(array_map('intval', $ids))) as $id) {
        $t = can_see_ticket($id) ? ticket_facts($pdo, $id) : null;
        if ($t === null) {
            $refused[] = ['ticket_id' => $id, 'number' => '#' . $id, 'why' => 'Ticket not found.'];
            continue;
        }
        if (!can_work_ticket($id)) {
            $refused[] = ['ticket_id' => $id, 'number' => $t['number'], 'why' => 'You are not in this ticket\'s queue.'];
            continue;
        }
        $pdo->beginTransaction();
        try {
            if (($changes['note'] ?? '') !== '') {
                $mid = add_message($pdo, $id, 'internal_note', (string) $changes['note'], ['author_member_id' => $by]);
                ticket_log($pdo, $t, 'ticket.note', ['after' => ['message_id' => $mid, 'kind' => 'internal_note', 'excerpt' => excerpt((string) $changes['note']), 'attachments' => 0, 'via' => 'bulk']]);
            }
            if (!empty($changes['add_tags']) || !empty($changes['remove_tags'])) {
                $tags = set_ticket_tags($pdo, $id, $changes['add_tags'] ?? [], $changes['remove_tags'] ?? []);
                ticket_log($pdo, $t, !empty($changes['add_tags']) ? 'ticket.tag' : 'ticket.untag', ['after' => ['added' => $changes['add_tags'] ?? [], 'removed' => $changes['remove_tags'] ?? [], 'tags' => $tags, 'via' => 'bulk']]);
            }
            if (($changes['priority'] ?? '') !== '') {
                $r = set_ticket_fields($pdo, $id, ['priority' => $changes['priority']], $by);
                if ($r['after'] !== []) {
                    ticket_log($pdo, $t, 'ticket.set_fields', ['before' => $r['before'], 'after' => $r['after'] + ['via' => 'bulk']]);
                }
            }
            $assignee = (string) ($changes['assignee'] ?? 'keep');
            $queue = $changes['queue'] ?? null;
            if ($assignee !== 'keep' || $queue !== null) {
                $who = $assignee === 'keep' ? $t['assignee_member_id'] : ($assignee === 'none' ? null : ($assignee === 'me' ? $by : (int) $assignee));
                $r = assign_ticket($pdo, $id, $who, $queue === null ? null : (int) $queue, $by, 'bulk');
                if ($r['changed']) {
                    ticket_log($pdo, $t, 'ticket.assign', ['before' => $r['before'], 'after' => $r['after']]);
                    if ($r['after']['assignee_kind'] === 'agent' && $r['after']['assignee_member_id'] !== $r['before']['assignee_member_id']) {
                        $dispatch[$id] = (int) $r['after']['assignee_member_id'];
                    }
                }
            }
            if (($changes['status'] ?? '') !== '') {
                $to = status_by_key($pdo, (string) $changes['status']) ?? throw new DomainException('That status is not here.');
                $moved = transition_ticket($pdo, $id, $to['status_id'], ['resolution_code' => (string) ($changes['resolution_code'] ?? ''), 'hold_reason' => (string) ($changes['hold_reason'] ?? ''), 'pending_until' => (string) ($changes['pending_until'] ?? '')], $by, 'bulk');
                ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
            }
            $pdo->commit();
            $changed[] = $id;
        } catch (DomainException $e) {
            $pdo->rollBack();
            $refused[] = ['ticket_id' => $id, 'number' => $t['number'], 'why' => $e->getMessage()];
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((string) $e->getCode() !== 'P0001') {
                throw $e;
            }
            $refused[] = ['ticket_id' => $id, 'number' => $t['number'], 'why' => db_message($e, 'That could not be done.')];
        }
    }
    return ['changed' => $changed, 'refused' => $refused, 'dispatch' => $dispatch];
}
