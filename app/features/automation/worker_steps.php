<?php
declare(strict_types=1);

/** The worker's nine steps (slice 8), each with the clock passed in ($now, ISO 8601; the proofs fake it), each reading the base tables as the writer with no acting member (source cron). */
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/assign.php';
require_once __DIR__ . '/queries.php';

/** (b) every active mailbox with polling on: handled messages. */
function mail_poll_due_mailboxes(PDO $pdo): int
{
    $n = 0;
    foreach ($pdo->query('SELECT id FROM mailboxes WHERE active AND poll_enabled ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $mb = find_mailbox($pdo, (int) $id);
        if ($mb === null) {
            continue;
        }
        try {
            $n += mail_poll_mailbox($pdo, $mb);
        } catch (Throwable $e) {
            error_log('worker mail ' . $mb['address'] . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/** The assignee and the queue's leads of a ticket. */
function sla_recipients(PDO $pdo, array $t): array
{
    $ids = $t['assignee_member_id'] !== null ? [(int) $t['assignee_member_id']] : [];
    foreach (queue_lead_ids($pdo, (int) $t['queue_id']) as $m) {
        $ids[] = $m;
    }
    return array_values(array_unique($ids));
}

/** (c) running targets past due: breached_at = due_at, ticket.sla_breach, the assignee and the leads told once. */
function sla_breach_pass(PDO $pdo, string $now): int
{
    $st = $pdo->prepare('UPDATE sla_targets SET breached_at = due_at WHERE met_at IS NULL AND breached_at IS NULL AND paused_at IS NULL AND due_at < CAST(:now AS timestamptz) RETURNING id, ticket_id, metric, due_at');
    $st->execute(['now' => $now]);
    $n = 0;
    foreach ($st->fetchAll() as $g) {
        $t = ticket_facts($pdo, (int) $g['ticket_id']);
        if ($t === null) {
            continue;
        }
        ticket_log($pdo, $t, 'ticket.sla_breach', ['after' => ['metric' => $g['metric'], 'due_at' => json_ts($g['due_at']), 'target_id' => (int) $g['id']]]);
        foreach (sla_recipients($pdo, $t) as $m) {
            notify($pdo, ['member_id' => $m], 'sla_breached', $t['ticket_id'], $t['number'] . ': ' . $g['metric'] . ' is late', 'The ' . str_replace('_', ' ', $g['metric']) . ' on ' . $t['number'] . ' "' . $t['subject'] . '" was due ' . format_ts($g['due_at'], 'UTC') . ' UTC and is late. See it here: ' . app_url('/tickets/' . $t['ticket_id']), null, 'sla_breached:' . $g['id']);
        }
        $n++;
    }
    return $n;
}

/** (c) running targets past the policy's warning percent of their span, not yet warned: warned_at, ticket.sla_warning, told once. */
function sla_warning_pass(PDO $pdo, string $now): int
{
    $st = $pdo->prepare("UPDATE sla_targets g SET warned_at = CAST(:now AS timestamptz) FROM sla_policies p
                          WHERE p.id = g.policy_id AND g.met_at IS NULL AND g.breached_at IS NULL AND g.paused_at IS NULL AND g.warned_at IS NULL AND g.due_at > CAST(:now AS timestamptz)
                            AND EXTRACT(EPOCH FROM (CAST(:now AS timestamptz) - g.started_at)) >= EXTRACT(EPOCH FROM (g.due_at - g.started_at)) * COALESCE(p.warning_percent, 80) / 100.0
                          RETURNING g.id, g.ticket_id, g.metric, g.due_at");
    $st->execute(['now' => $now]);
    $n = 0;
    foreach ($st->fetchAll() as $g) {
        $t = ticket_facts($pdo, (int) $g['ticket_id']);
        if ($t === null || in_array($t['status_category'], ['solved', 'closed'], true)) {
            continue;
        }
        ticket_log($pdo, $t, 'ticket.sla_warning', ['after' => ['metric' => $g['metric'], 'due_at' => json_ts($g['due_at']), 'target_id' => (int) $g['id']]]);
        foreach (sla_recipients($pdo, $t) as $m) {
            notify($pdo, ['member_id' => $m], 'sla_warning', $t['ticket_id'], $t['number'] . ': ' . $g['metric'] . ' is about to be late', 'The ' . str_replace('_', ' ', $g['metric']) . ' on ' . $t['number'] . ' "' . $t['subject'] . '" is due ' . format_ts($g['due_at'], 'UTC') . ' UTC. See it here: ' . app_url('/tickets/' . $t['ticket_id']), null, 'sla_warning:' . $g['id']);
        }
        $n++;
    }
    return $n;
}

/** (d) pending tickets whose wake-up passed: open, via auto, the assignee told. */
function snooze_pass(PDO $pdo, string $now): int
{
    $open = status_by_key($pdo, 'open');
    $st = $pdo->prepare("SELECT t.id FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE s.category = 'pending' AND t.pending_until IS NOT NULL AND t.pending_until <= CAST(:now AS timestamptz) AND t.merged_into_id IS NULL ORDER BY t.id LIMIT 500");
    $st->execute(['now' => $now]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $t = ticket_facts($pdo, (int) $id);
        if ($t === null || $open === null) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            $moved = transition_ticket($pdo, (int) $id, $open['status_id'], [], 0, 'auto', false);
            $pdo->prepare('UPDATE tickets SET pending_until = NULL WHERE id = :id')->execute(['id' => (int) $id]);
            ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after'] + ['reason' => 'pending_elapsed']]);
            if ($t['assignee_member_id'] !== null) {
                notify($pdo, ['member_id' => $t['assignee_member_id']], 'pending_elapsed', $t['ticket_id'], $t['number'] . ' waited long enough', $t['number'] . ' "' . $t['subject'] . '" was pending and its wait ran out; it is open again. See it here: ' . app_url('/tickets/' . $t['ticket_id']), null, 'pending_elapsed:' . $t['ticket_id'] . ':' . substr($now, 0, 16));
            }
            $pdo->commit();
            $n++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('worker snooze ' . $id . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/** (e) solved for auto_close_days (0 = never): closed, via auto (the trigger dates the secure link). */
function auto_close_pass(PDO $pdo, string $now): int
{
    $days = (int) $pdo->query('SELECT auto_close_days FROM app_settings WHERE id = 1')->fetchColumn();
    if ($days <= 0) {
        return 0;
    }
    $closed = status_by_key($pdo, 'closed');
    $st = $pdo->prepare("SELECT t.id FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE s.category = 'solved' AND t.solved_at IS NOT NULL AND t.solved_at <= CAST(:now AS timestamptz) - make_interval(days => :d) AND t.merged_into_id IS NULL ORDER BY t.id LIMIT 500");
    $st->execute(['now' => $now, 'd' => $days]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $t = ticket_facts($pdo, (int) $id);
        if ($t === null || $closed === null) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            $moved = transition_ticket($pdo, (int) $id, $closed['status_id'], [], 0, 'auto', false);
            ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after'] + ['reason' => 'auto_close']]);
            $pdo->commit();
            $n++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('worker auto_close ' . $id . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/** (f) every active time-based rule in order against its candidates; a ticket touched at most once per rule per pass. [rule_id => [applied, skipped, error]] */
function rules_pass(PDO $pdo, string $now, ?int $onlyRule = null): array
{
    $out = [];
    $ctx = rule_ctx($pdo, $now);
    foreach (find_rules($pdo, 'time_based', true) as $rule) {
        if (!$rule['active'] || ($onlyRule !== null && $rule['rule_id'] !== $onlyRule)) {
            continue;
        }
        $c = ['applied' => 0, 'skipped' => 0, 'error' => 0];
        foreach (rule_candidates($pdo, $rule, $now) as $t) {
            if (!rule_evaluate($rule, $t, $ctx)) {
                continue;
            }
            $r = rule_apply($pdo, $rule, $t, null, 'rule');
            $c[$r['outcome']]++;
        }
        $out[$rule['rule_id']] = $c;
    }
    return $out;
}

/** (g) the survey falling due: solved past the delay, surveyed queue, a requester with an address, no row yet. */
function survey_pass(PDO $pdo, string $now): int
{
    $cs = find_csat_settings($pdo);
    if (!$cs['enabled']) {
        return 0;
    }
    $st = $pdo->prepare("SELECT t.id FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN requesters r ON r.id = t.requester_id
                          WHERE s.category IN ('solved', 'closed') AND t.solved_at IS NOT NULL AND t.solved_at <= CAST(:now AS timestamptz) - make_interval(mins => :d) AND t.merged_into_id IS NULL
                            AND (r.primary_email IS NOT NULL OR r.member_id IS NOT NULL)
                            AND NOT EXISTS (SELECT 1 FROM satisfaction_ratings g WHERE g.ticket_id = t.id)
                            AND (CAST(:qs AS bigint[]) = '{}'::bigint[] OR t.queue_id = ANY (CAST(:qs AS bigint[])))
                          ORDER BY t.solved_at LIMIT 100");
    $st->execute(['now' => $now, 'd' => $cs['send_delay_minutes'], 'qs' => '{' . implode(',', $cs['queue_ids']) . '}']);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->beginTransaction();
        try {
            $r = send_survey($pdo, (int) $id, null);
            log_activity($pdo, 'survey.send', 'ticket', (int) $id, ['ticket_id' => (int) $id, 'after' => ['requester_id' => $r['requester_id'], 'via' => 'auto']]);
            $pdo->commit();
            $n++;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('worker survey ' . $id . ': ' . $e->getMessage());
        }
    }
    return $n;
}

/** (h) failed dispatches of the last hour: one more attempt per pass as the person who assigned; three failures → given_up, logged once. */
function dispatch_retry_pass(PDO $pdo, string $now): array
{
    $st = $pdo->prepare("SELECT ticket_id, agent_member_id, max(acting_member_id) AS acting, count(*) AS failures, max(created_at) AS last_at
                           FROM agent_dispatches WHERE status = 'failed' AND created_at > CAST(:now AS timestamptz) - interval '1 hour' GROUP BY ticket_id, agent_member_id");
    $st->execute(['now' => $now]);
    $out = ['retried' => 0, 'given_up' => 0];
    foreach ($st->fetchAll() as $d) {
        $ticketId = (int) $d['ticket_id'];
        $agent = (int) $d['agent_member_id'];
        $t = ticket_facts($pdo, $ticketId);
        if ($t === null || $t['assignee_member_id'] !== $agent) {
            continue;
        }
        $later = $pdo->prepare("SELECT 1 FROM agent_dispatches WHERE ticket_id = :t AND agent_member_id = :a AND status <> 'failed' AND created_at > :at");
        $later->execute(['t' => $ticketId, 'a' => $agent, 'at' => $d['last_at']]);
        if ($later->fetchColumn() !== false) {
            continue;
        }
        if ((int) $d['failures'] >= 3) {
            $gave = $pdo->prepare("SELECT 1 FROM activity_log WHERE action = 'ticket.dispatch' AND ticket_id = :t AND after->>'status' = 'given_up' AND occurred_at > CAST(:now AS timestamptz) - interval '1 hour'");
            $gave->execute(['t' => $ticketId, 'now' => $now]);
            if ($gave->fetchColumn() === false) {
                ticket_log($pdo, $t, 'ticket.dispatch', ['after' => ['agent_member_id' => $agent, 'via' => 'retry', 'run_id' => null, 'status' => 'given_up', 'failures' => (int) $d['failures']]]);
                $out['given_up']++;
            }
            continue;
        }
        $r = dispatch_to_agent($pdo, $ticketId, $agent, 'assigned', $d['acting'] === null ? null : (int) $d['acting']);
        ticket_log($pdo, $t, 'ticket.dispatch', ['after' => ['agent_member_id' => $agent, 'via' => $r['via'], 'run_id' => $r['run_id'], 'status' => $r['status'], 'retry' => true]]);
        $out['retried']++;
    }
    return $out;
}

/** (i) stale viewers and spent nonces. */
function housekeeping_pass(PDO $pdo, string $now): array
{
    $v = $pdo->prepare("DELETE FROM ticket_viewers WHERE last_seen_at < CAST(:now AS timestamptz) - interval '10 minutes'");
    $v->execute(['now' => $now]);
    $n = $pdo->prepare("DELETE FROM sso_nonces WHERE expires_at < CAST(:now AS timestamptz) - interval '1 hour'");
    $n->execute(['now' => $now]);
    return ['viewers' => $v->rowCount(), 'nonces' => $n->rowCount()];
}
