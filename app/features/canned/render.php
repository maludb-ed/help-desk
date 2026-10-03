<?php
declare(strict_types=1);

/**
 * Rendering a canned response (slice 5): the placeholders, exactly these; the render reads the ticket's presenter row only — never a secure link, another ticket, a note or a third party's
 * address. The side-effects go through slice 3's functions, each under a savepoint: one the table forbids is skipped and named.
 */

/** placeholder → what it is. */
function canned_placeholders(): array
{
    return ['ticket.number' => 'The ticket number', 'ticket.subject' => 'Its subject', 'ticket.queue' => 'Its queue', 'ticket.status' => 'Its status', 'requester.name' => 'The requester\'s name', 'requester.first_name' => 'Their first name',
            'organization.name' => 'Their organization', 'agent.name' => 'Your name', 'agent.first_name' => 'Your first name', 'agent.signature' => 'The queue mailbox\'s signature, else your name', 'queue.name' => 'The queue', 'queue.email' => 'The queue\'s address', 'today' => 'Today\'s date'];
}

/** The placeholders in a body that are not in the list. */
function canned_unknown_placeholders(string $body): array
{
    preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $body, $m);
    return array_values(array_unique(array_filter($m[1], static fn (string $k): bool => !isset(canned_placeholders()[$k]))));
}

/** The values for a presented ticket (present_ticket / present_ticket_brief), the actor and the queue. */
function canned_values(array $ticket, array $actor, array $queue): array
{
    $first = static fn (?string $name): string => trim((string) strtok(trim((string) $name), ' '));
    $signature = trim((string) ($queue['signature'] ?? ''));
    return ['ticket.number' => (string) ($ticket['number'] ?? ''), 'ticket.subject' => (string) ($ticket['subject'] ?? ''), 'ticket.queue' => (string) ($ticket['queue']['name'] ?? ''), 'ticket.status' => (string) ($ticket['status']['label'] ?? ''),
            'requester.name' => (string) ($ticket['requester']['name'] ?? ''), 'requester.first_name' => $first($ticket['requester']['name'] ?? ''), 'organization.name' => (string) ($ticket['organization']['name'] ?? ''),
            'agent.name' => (string) ($actor['display_name'] ?? ''), 'agent.first_name' => $first($actor['display_name'] ?? ''), 'agent.signature' => $signature !== '' ? $signature : (string) ($actor['display_name'] ?? ''),
            'queue.name' => (string) ($queue['name'] ?? ($ticket['queue']['name'] ?? '')), 'queue.email' => (string) ($queue['email_address'] ?? ''), 'today' => (new DateTimeImmutable('now', new DateTimeZone(member_timezone())))->format('F j, Y')];
}

/** The body with its placeholders filled; an unknown one is left as typed. */
function canned_render(array $canned, array $ticket, array $actor, array $queue): string
{
    $values = canned_values($ticket, $actor, $queue);
    return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', static fn (array $m): string => array_key_exists($m[1], $values) ? $values[$m[1]] : $m[0], (string) $canned['body_text']);
}

/** The queue's facts a render needs: name, email_address, the active mailbox's signature. */
function canned_queue_facts(PDO $pdo, ?int $queueId): array
{
    if ($queueId === null) {
        return [];
    }
    $st = $pdo->prepare('SELECT q.name, q.email_address, (SELECT b.signature FROM mailboxes b WHERE b.queue_id = q.id AND b.active ORDER BY b.id LIMIT 1) AS signature FROM queues q WHERE q.id = :q');
    $st->execute(['q' => $queueId]);
    return $st->fetch() ?: [];
}

/** The actor's facts: display_name. */
function canned_actor_facts(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT display_name FROM members WHERE id = :m');
    $st->execute(['m' => $memberId]);
    return $st->fetch() ?: ['display_name' => ''];
}

/** A sample ticket for the form's preview (nothing is read from the database). */
function canned_sample_ticket(): array
{
    return ['number' => 'HD-1042', 'subject' => 'Printer on the third floor jams', 'queue' => ['name' => 'IT help'], 'status' => ['label' => 'Open'], 'requester' => ['name' => 'Jo Example'], 'organization' => ['name' => 'Example Co']];
}

/** Side-effects validated against the vocabulary: errors by key; $out the normalised object. */
function canned_side_effects_validate(PDO $pdo, array $raw, ?array &$out = null): array
{
    $errors = [];
    $out = [];
    if (($raw['status'] ?? '') !== '') {
        if (status_by_key($pdo, (string) $raw['status']) === null) {
            $errors['side_effects.status'] = 'The status side-effect is a status key.';
        } else {
            $out['status'] = (string) $raw['status'];
        }
    }
    if (($raw['priority'] ?? '') !== '') {
        if (!in_array($raw['priority'], VIEW_PRIORITIES, true)) {
            $errors['side_effects.priority'] = 'The priority side-effect is low, normal, high or urgent.';
        } else {
            $out['priority'] = (string) $raw['priority'];
        }
    }
    $assign = (string) ($raw['assign'] ?? '');
    if ($assign !== '') {
        if ($assign === 'me' || $assign === 'nobody') {
            $out['assign'] = $assign;
        } elseif (filter_var($assign, FILTER_VALIDATE_INT) !== false) {
            $out['assign'] = (int) $assign;
        } else {
            $errors['side_effects.assign'] = 'The assign side-effect is me, nobody or a member id.';
        }
    }
    $tags = is_array($raw['add_tags'] ?? null) ? $raw['add_tags'] : explode(',', (string) ($raw['add_tags'] ?? ''));
    $tags = array_values(array_unique(array_filter(array_map(static fn ($g): string => slugify_tag(trim((string) $g)), $tags))));
    if ($tags !== []) {
        $out['add_tags'] = $tags;
    }
    return $errors;
}

/**
 * Apply a response's side-effects to a ticket, in order (tags, priority, assign, status), each through slice 3's functions under a savepoint — one the table forbids is skipped and
 * named. Answers [applied: [...words], skipped: [{effect, why}]]. Logs the per-effect rows (ticket.tag, ticket.set_fields, ticket.assign, ticket.transition via canned).
 */
function canned_side_effects(PDO $pdo, array $canned, int $ticketId, int $by): array
{
    $se = $canned['side_effects'];
    $t = ticket_facts($pdo, $ticketId) ?? throw new DomainException('Not found.');
    $applied = [];
    $skipped = [];
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    $step = static function (string $effect, callable $fn) use ($pdo, &$applied, &$skipped): void {
        $pdo->exec('SAVEPOINT canned_effect');
        try {
            $words = $fn();
            if ($words !== null) {
                $applied[] = $words;
            }
            $pdo->exec('RELEASE SAVEPOINT canned_effect');
        } catch (DomainException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT canned_effect');
            $skipped[] = ['effect' => $effect, 'why' => $e->getMessage()];
        } catch (PDOException $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT canned_effect');
            if ((string) $e->getCode() !== 'P0001') {
                throw $e;
            }
            $skipped[] = ['effect' => $effect, 'why' => db_message($e, 'That could not be done.')];
        }
    };
    if (!empty($se['add_tags'])) {
        $step('add_tags', static function () use ($pdo, $t, $se): string {
            $tags = set_ticket_tags($pdo, $t['ticket_id'], $se['add_tags'], []);
            ticket_log($pdo, $t, 'ticket.tag', ['after' => ['added' => $se['add_tags'], 'tags' => $tags, 'via' => 'canned']]);
            return '+' . implode(' +', $se['add_tags']);
        });
    }
    if (!empty($se['priority'])) {
        $step('priority', static function () use ($pdo, $t, $se, $by): ?string {
            $r = set_ticket_fields($pdo, $t['ticket_id'], ['priority' => $se['priority']], $by);
            if ($r['after'] === []) {
                return null;
            }
            ticket_log($pdo, $t, 'ticket.set_fields', ['before' => $r['before'], 'after' => $r['after'] + ['via' => 'canned']]);
            return '↑ ' . ucfirst((string) $se['priority']);
        });
    }
    if (!empty($se['assign'])) {
        $step('assign', static function () use ($pdo, $t, $se, $by): ?string {
            $who = $se['assign'] === 'me' ? $by : ($se['assign'] === 'nobody' ? null : (int) $se['assign']);
            $r = assign_ticket($pdo, $t['ticket_id'], $who, null, $by, 'canned');
            if (!$r['changed']) {
                return null;
            }
            ticket_log($pdo, $t, 'ticket.assign', ['before' => $r['before'], 'after' => $r['after']]);
            return 'assigned to ' . ($r['after']['assignee_name'] ?? 'nobody');
        });
    }
    if (!empty($se['status'])) {
        $step('status', static function () use ($pdo, $t, $se, $by): string {
            $to = status_by_key($pdo, (string) $se['status']) ?? throw new DomainException('That status is not here.');
            $moved = transition_ticket($pdo, $t['ticket_id'], $to['status_id'], [], $by, 'canned');
            ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after']]);
            return '→ ' . $to['label'];
        });
    }
    if ($own) {
        $pdo->commit();
    }
    return ['applied' => $applied, 'skipped' => $skipped];
}
