<?php
declare(strict_types=1);

/**
 * The MOVE (slice 3): hd_transition() says whether a move is allowed and what it needs; this decides, in words, and UPDATEs the status —
 * the table's triggers keep the dates, the clock and the reopen count (db/010). A refusal is a DomainException in the sentence the screen shows.
 */

function status_by_key(PDO $pdo, string $key): ?array
{
    $st = $pdo->prepare('SELECT id AS status_id, key, label, category FROM ticket_statuses WHERE key = :k AND archived_at IS NULL');
    $st->execute(['k' => $key]);
    $r = $st->fetch();
    return $r === false ? null : $r + ['status_id' => (int) $r['status_id']];
}

function status_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS status_id, key, label, category FROM ticket_statuses WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : $r + ['status_id' => (int) $r['status_id']];
}

/**
 * Move a ticket to a status. $carry: resolution_code, hold_reason, pending_until, assignee_member_id. $checkRight: false for a requester's own
 * confirm / reopen (PHP's rule, not the table's right). Answers ['before' => [status, category], 'after' => [status, category, resolution_code, hold_reason, pending_until, via], 'label' => the move's label].
 */
function transition_ticket(PDO $pdo, int $ticketId, int $toStatusId, array $carry, int $by, string $via = 'screen', bool $checkRight = true): array
{
    $t = ticket_facts($pdo, $ticketId) ?? throw new DomainException('Not found.');
    $to = status_by_id($pdo, $toStatusId) ?? throw new DomainException('That status is not here.');
    if ($t['status_category'] === 'closed') {
        throw new DomainException($t['number'] . ' cannot move from Closed.');
    }
    if ($to['status_id'] === $t['status_id']) {
        throw new DomainException($t['number'] . ' is already ' . $to['label'] . '.');
    }
    $st = $pdo->prepare('SELECT * FROM hd_transition(:a, :b)');
    $st->execute(['a' => $t['status_id'], 'b' => $to['status_id']]);
    $rule = $st->fetch();
    if ($rule === false) {
        $from = ucfirst(str_replace('_', ' ', $t['status_category']));
        $toWords = ucfirst(str_replace('_', ' ', $to['category']));
        throw new DomainException($t['number'] . ' cannot move from ' . $from . ' to ' . $toWords . ' here' . ($to['category'] === 'closed' ? ' — close it from the ticket\'s menu.' : '.'));
    }
    $requires = pg_text_array((string) $rule['requires']);
    $mayCarry = pg_text_array((string) $rule['may_carry']);
    if ($checkRight && !has_right((string) $rule['needs_right'])) {
        throw new DomainException($to['category'] === 'closed' ? 'Closing early needs the Lead role.' : 'This move needs the Lead role.');
    }
    $set = ['status_id' => $to['status_id']];
    $after = ['status' => $to['key'], 'category' => $to['category'], 'via' => $via];
    if (in_array('resolution_code', $requires, true) || in_array($to['category'], ['solved', 'closed'], true)) {
        $code = (string) ($carry['resolution_code'] ?? '');
        if ($code === '' && in_array('resolution_code', $requires, true)) {
            throw new DomainException(($to['category'] === 'closed' ? 'Closing' : 'Solving') . ' needs a resolution code.');
        }
        if ($code !== '' && !isset(RESOLUTION_CODES[$code])) {
            throw new DomainException('The resolution code is one of: ' . implode(', ', array_keys(RESOLUTION_CODES)) . '.');
        }
        if ($code !== '') {
            $set['resolution_code'] = $code;
            $after['resolution_code'] = $code;
        }
    }
    if (in_array('hold_reason', $requires, true) || $to['category'] === 'on_hold') {
        $reason = (string) ($carry['hold_reason'] ?? '');
        if ($reason === '' && in_array('hold_reason', $requires, true)) {
            throw new DomainException('Putting a ticket on hold needs a reason.');
        }
        if ($reason !== '' && !isset(HOLD_REASONS[$reason])) {
            throw new DomainException('The hold reason is one of: ' . implode(', ', array_keys(HOLD_REASONS)) . '.');
        }
        if ($reason !== '') {
            $set['hold_reason'] = $reason;
            $after['hold_reason'] = $reason;
        }
    }
    if (in_array('pending_until', $mayCarry, true) && ($carry['pending_until'] ?? '') !== '') {
        try {
            $until = new DateTimeImmutable((string) $carry['pending_until'], new DateTimeZone(member_timezone()));
        } catch (Exception) {
            throw new DomainException('The wake-up time is a date and time.');
        }
        $set['pending_until'] = $until->format('c');
        $after['pending_until'] = $until->format('c');
    }
    if (in_array('assignee_member_id', $mayCarry, true) && ($carry['assignee_member_id'] ?? null) !== null) {
        $set['assignee_member_id'] = (int) $carry['assignee_member_id'];
        $after['assignee_member_id'] = (int) $carry['assignee_member_id'];
    }
    $sql = 'UPDATE tickets SET ' . implode(', ', array_map(static fn (string $k): string => "$k = :$k", array_keys($set))) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($set + ['id' => $ticketId]);
    return ['before' => ['status' => $t['status'], 'category' => $t['status_category']], 'after' => $after, 'label' => (string) $rule['label']];
}
