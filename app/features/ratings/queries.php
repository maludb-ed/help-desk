<?php
declare(strict_types=1);

/** Ratings and the survey (slice 6): csat_settings, satisfaction_ratings (one a ticket; a one-time token for the survey mail), the ledger a lead reads, the CSAT percentage. */

const CSAT_SCALES = ['good_bad' => 'Good or bad', 'five_point' => 'One to five'];

function find_csat_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT * FROM csat_settings WHERE id = 1')->fetch() ?: [];
    return ['enabled' => (bool) ($r['enabled'] ?? false), 'scale' => (string) ($r['scale'] ?? 'good_bad'), 'question' => (string) ($r['question'] ?? 'How did we do?'), 'send_delay_minutes' => (int) ($r['send_delay_minutes'] ?? 60),
            'response_window_days' => (int) ($r['response_window_days'] ?? 14), 'bad_reasons' => pg_text_array((string) ($r['bad_reasons'] ?? '{}')), 'queue_ids' => pg_int_array((string) ($r['queue_ids'] ?? '{}')), 'updated_at' => $r['updated_at'] ?? null];
}

/** Save what changed: [before, after] of the changed fields (empty when nothing changed). */
function save_csat_settings(PDO $pdo, array $f, int $by): array
{
    $cur = find_csat_settings($pdo);
    $keys = ['enabled', 'scale', 'question', 'send_delay_minutes', 'response_window_days', 'bad_reasons', 'queue_ids'];
    $before = [];
    $after = [];
    foreach ($keys as $k) {
        if ($cur[$k] !== $f[$k]) {
            $before[$k] = $cur[$k];
            $after[$k] = $f[$k];
        }
    }
    if ($after === []) {
        return ['before' => [], 'after' => []];
    }
    $pdo->prepare('UPDATE csat_settings SET enabled = :e, scale = :s, question = :q, send_delay_minutes = :d, response_window_days = :w, bad_reasons = CAST(:r AS text[]), queue_ids = CAST(:qs AS bigint[]) WHERE id = 1')
        ->execute(['e' => $f['enabled'] ? 't' : 'f', 's' => $f['scale'], 'q' => $f['question'], 'd' => $f['send_delay_minutes'], 'w' => $f['response_window_days'], 'r' => pg_array_literal($f['bad_reasons']), 'qs' => '{' . implode(',', $f['queue_ids']) . '}']);
    return ['before' => $before, 'after' => $after];
}

/** Is a queue surveyed (none chosen = every queue)? */
function queue_surveyed(array $cs, int $queueId): bool
{
    return $cs['enabled'] && ($cs['queue_ids'] === [] || in_array($queueId, $cs['queue_ids'], true));
}

function find_rating_for_ticket(PDO $pdo, int $ticketId): ?array
{
    $st = $pdo->prepare('SELECT * FROM satisfaction_ratings WHERE ticket_id = :t');
    $st->execute(['t' => $ticketId]);
    $r = $st->fetch();
    return $r === false ? null : rating_cast($r);
}

function rating_cast(array $r): array
{
    foreach (['id', 'ticket_id', 'requester_id'] as $k) {
        $r[$k] = (int) $r[$k];
    }
    $r['score'] = $r['score'] === null ? null : (int) $r['score'];
    return $r;
}

/** The rating row for a ticket, made if missing (a one-time token, the window from the settings): [row, raw token or null when it existed]. */
function ensure_rating_row(PDO $pdo, int $ticketId, int $requesterId): array
{
    $cur = find_rating_for_ticket($pdo, $ticketId);
    if ($cur !== null) {
        return [$cur, null];
    }
    $raw = bin2hex(random_bytes(24));
    $days = find_csat_settings($pdo)['response_window_days'];
    $pdo->prepare('INSERT INTO satisfaction_ratings (ticket_id, requester_id, token_hash, expires_at) VALUES (:t, :r, :h, now() + make_interval(days => :d))')->execute(['t' => $ticketId, 'r' => $requesterId, 'h' => hash('sha256', $raw), 'd' => $days]);
    return [find_rating_for_ticket($pdo, $ticketId), $raw];
}

/** Write the answer once: false when already answered. */
function submit_rating(PDO $pdo, int $ratingId, int $score, ?string $reason, ?string $comment): bool
{
    $st = $pdo->prepare('UPDATE satisfaction_ratings SET score = :s, bad_reason = :r, comment = :c, responded_at = now() WHERE id = :id AND responded_at IS NULL');
    $st->execute(['s' => $score, 'r' => $reason, 'c' => $comment, 'id' => $ratingId]);
    return $st->rowCount() > 0;
}

/** The survey mail's one-time token: its hash and not expired. */
function rating_token_valid(array $row, string $raw): bool
{
    return preg_match('/^[a-f0-9]{48}$/', $raw) === 1 && hash_equals((string) $row['token_hash'], hash('sha256', $raw)) && strtotime((string) $row['expires_at']) > time();
}

/** Is a score bad? good_bad: 1; five_point: 1–2. */
function rating_is_bad(?int $score): bool
{
    return $score !== null && $score <= 2;
}

/**
 * Mail the survey now for a solved ticket of a surveyed queue: the rating row (its token, the window), the secure link the mail carries (an extra live one), the notice (kind survey) through
 * notify(). Refuses an unsolved ticket, an unsurveyed queue, a ticket surveyed already. Answers [rating_id, requester_id]. The caller logs.
 */
function send_survey(PDO $pdo, int $ticketId, ?int $by): array
{
    $t = ticket_facts($pdo, $ticketId) ?? throw new DomainException('Not found.');
    if (!in_array($t['status_category'], ['solved', 'closed'], true)) {
        throw new DomainException($t['number'] . ' is not solved yet.');
    }
    $cs = find_csat_settings($pdo);
    if (!queue_surveyed($cs, $t['queue_id'])) {
        throw new DomainException('That queue is not surveyed.');
    }
    if (find_rating_for_ticket($pdo, $ticketId) !== null) {
        throw new DomainException($t['number'] . ' was surveyed already.');
    }
    [$row, $raw] = ensure_rating_row($pdo, $ticketId, $t['requester_id']);
    $link = $t['requester_member_id'] === null ? app_url('/t/' . mint_extra_secure_link($pdo, $ticketId) . '/rate?r=' . $raw) : app_url('/tickets/' . $ticketId . '#rating');
    notify($pdo, ['requester_id' => $t['requester_id']], 'survey', $ticketId, $cs['question'] . ' — ' . $t['number'], 'Your request ' . $t['number'] . ' "' . $t['subject'] . '" was solved. ' . $cs['question'] . "\n\nTell us here: " . $link . "\n\nThe link works for " . $cs['response_window_days'] . ' days.', null, 'survey:' . $ticketId);
    return ['rating_id' => $row['id'], 'requester_id' => $t['requester_id']];
}

/** The worker's call: every ticket solved longer ago than the delay, in a surveyed queue, with no rating row — surveyed now. Answers how many. */
function send_due_surveys(PDO $pdo, int $limit = 100): int
{
    $cs = find_csat_settings($pdo);
    if (!$cs['enabled']) {
        return 0;
    }
    $st = $pdo->prepare("SELECT t.id FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id
                          WHERE s.category IN ('solved', 'closed') AND t.solved_at IS NOT NULL AND t.solved_at <= now() - make_interval(mins => :d) AND t.merged_into_id IS NULL
                            AND NOT EXISTS (SELECT 1 FROM satisfaction_ratings r WHERE r.ticket_id = t.id)
                            AND (CAST(:qs AS bigint[]) = '{}'::bigint[] OR t.queue_id = ANY (CAST(:qs AS bigint[])))
                          ORDER BY t.solved_at LIMIT " . (int) $limit);
    $st->execute(['d' => $cs['send_delay_minutes'], 'qs' => '{' . implode(',', $cs['queue_ids']) . '}']);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pdo->beginTransaction();
        try {
            $r = send_survey($pdo, (int) $id, null);
            log_activity($pdo, 'survey.send', 'ticket', (int) $id, ['ticket_id' => (int) $id, 'after' => ['requester_id' => $r['requester_id'], 'via' => 'worker'], 'source' => 'cron']);
            $pdo->commit();
            $n++;
        } catch (Throwable $e) {
            $pdo->rollBack();
        }
    }
    return $n;
}

/** The ratings the caller may read (the views decide), with the ticket's facts. $filters: score (bad|good|all), queue, period (days). Bad ones first, then newest. */
function find_ratings(PDO $pdo, int $memberId, array $filters, int $limit = 50): array
{
    $where = ['s.responded_at IS NOT NULL'];
    $args = [];
    if (($filters['score'] ?? 'all') === 'bad') {
        $where[] = 's.score <= 2';
    } elseif (($filters['score'] ?? 'all') === 'good') {
        $where[] = 's.score >= 4';
    }
    if (!empty($filters['queue'])) {
        $where[] = 't.queue_id = :q';
        $args['q'] = (int) $filters['queue'];
    }
    if (!empty($filters['period'])) {
        $where[] = 's.responded_at > now() - make_interval(days => :p)';
        $args['p'] = min(3650, (int) $filters['period']);
    }
    $st = $pdo->prepare('SELECT s.rating_id, s.ticket_id, s.score, s.bad_reason, s.comment, s.responded_at, t.number, t.subject, t.queue_id, t.queue_name, t.assignee_name, t.assignee_kind, t.requester_name
                           FROM mcp_satisfaction_ratings s JOIN mcp_tickets t ON t.ticket_id = s.ticket_id WHERE ' . implode(' AND ', $where) . ' ORDER BY (s.score <= 2) DESC, s.responded_at DESC LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map(static function (array $r): array {
        foreach (['rating_id', 'ticket_id', 'queue_id'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['score'] = (int) $r['score'];
        return $r;
    }, $st->fetchAll());
}

/** Good ÷ answered, as a percentage; null when nothing answered. */
function csat_percent(array $rows): ?float
{
    $answered = array_filter($rows, static fn (array $r): bool => $r['score'] !== null);
    if ($answered === []) {
        return null;
    }
    $good = count(array_filter($answered, static fn (array $r): bool => $r['score'] >= 4));
    return round(100 * $good / count($answered), 1);
}
