<?php
declare(strict_types=1);

/**
 * Reports and the queue dashboard (slice 10) — the one set of query functions the screens, the CSV and Phase 4's tools share. Every report reads the mcp_* views only, so a
 * holder's reach is the view's; the two base-table reads are the business's time zone and the CSAT scale (PHP as the writer) and queue_presence for the dashboard's workload.
 * A period is [from 00:00, to 24:00) in the business zone; a grouping is an allow-listed expression, never a request string.
 */
const REPORTS = ['volume' => 'Volume', 'first_response' => 'First response', 'resolution' => 'Resolution', 'sla_attainment' => 'SLA attainment', 'reopen_rate' => 'Reopen rate', 'csat' => 'CSAT', 'workload' => 'Workload', 'aging' => 'Aging'];
const REPORT_GROUPS = ['queue' => 'Queue', 'category' => 'Category', 'organization' => 'Organization', 'assignee' => 'Assignee', 'day' => 'Day', 'week' => 'Week'];
const REPORT_MAX_DAYS = 366;
const REPORT_OPEN_CATEGORIES = ['new', 'open', 'pending', 'on_hold'];
const REPORT_CHANNELS = ['web' => 'Web', 'email' => 'Email', 'form' => 'Form', 'agent' => 'Agent', 'api' => 'API'];
const AGING_BUCKETS = ['today' => 'Today', 'd1_3' => '1–3 d', 'd4_7' => '4–7 d', 'd8_30' => '8–30 d', 'older' => 'Older'];
const REPORT_PRIORITIES = ['urgent', 'high', 'normal', 'low'];

/** The business's time zone (app_settings; the one base-table read a report makes, as the writer). */
function business_timezone(PDO $pdo): string
{
    static $tz = null;
    if ($tz === null) {
        $v = (string) ($pdo->query('SELECT timezone FROM app_settings WHERE id = 1')->fetchColumn() ?: 'UTC');
        $tz = in_array($v, timezone_identifiers_list(), true) ? $v : 'UTC';
    }
    return $tz;
}

/** The period: ['from' => Y-m-d, 'to' => Y-m-d, 'start' => UTC ISO, 'end' => UTC ISO (exclusive), 'days' => n]. Defaults: the last 30 days. Throws DomainException in words. */
function report_period(string $from, string $to, string $tz): array
{
    $zone = new DateTimeZone($tz);
    $today = new DateTimeImmutable('today', $zone);
    $parse = static function (string $v, DateTimeImmutable $default) use ($zone): DateTimeImmutable {
        if (trim($v) === '') {
            return $default;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', trim($v), $zone);
        if ($d === false || $d->format('Y-m-d') !== trim($v)) {
            throw new DomainException('A date is written 2026-01-31.');
        }
        return $d;
    };
    $toD = $parse($to, $today);
    $fromD = $parse($from, $toD->modify('-29 days'));
    if ($fromD > $toD) {
        throw new DomainException("The period's start is after its end.");
    }
    $days = (int) $fromD->diff($toD)->days + 1;
    if ($days > REPORT_MAX_DAYS) {
        throw new DomainException('A period is at most ' . REPORT_MAX_DAYS . ' days.');
    }
    return ['from' => $fromD->format('Y-m-d'), 'to' => $toD->format('Y-m-d'), 'start' => $fromD->setTimezone(new DateTimeZone('UTC'))->format('c'), 'end' => $toD->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('c'), 'days' => $days, 'tz' => $tz];
}

/** The grouping as SQL over the ticket alias t: [key expression, label expression, needs :tz]. */
function report_group_sql(string $groupBy): array
{
    return match ($groupBy) {
        'queue' => ['t.queue_id', 't.queue_name', false],
        'category' => ['t.category_id', "COALESCE(t.category_name, 'No category')", false],
        'organization' => ['t.organization_id', "COALESCE(t.organization_name, 'No organization')", false],
        'assignee' => ['t.assignee_member_id', "COALESCE(t.assignee_name, 'Unassigned')", false],
        'day' => ["to_char(t.created_at AT TIME ZONE CAST(:tz AS text), 'YYYY-MM-DD')", "to_char(t.created_at AT TIME ZONE CAST(:tz AS text), 'YYYY-MM-DD')", true],
        'week' => ["to_char(date_trunc('week', t.created_at AT TIME ZONE CAST(:tz AS text)), 'YYYY-MM-DD')", "'Week of ' || to_char(date_trunc('week', t.created_at AT TIME ZONE CAST(:tz AS text)), 'YYYY-MM-DD')", true],
        default => throw new DomainException("'" . $groupBy . "' is not a grouping."),
    };
}

/** The shared tail of a grouped report: the group expressions, the period and the queue bound. */
function report_bind(array $p, string $groupBy, ?int $queue): array
{
    [$key, $label, $tzNeeded] = report_group_sql($groupBy);
    $args = ['start' => $p['start'], 'end' => $p['end']];
    if ($tzNeeded) {
        $args['tz'] = $p['tz'];
    }
    $queueSql = '';
    if ($queue !== null) {
        $queueSql = ' AND t.queue_id = :queue';
        $args['queue'] = $queue;
    }
    return [$key, $label, $queueSql, $args];
}

function report_rows(PDO $pdo, string $sql, array $args): array
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        foreach ($r as $k => $v) {
            if (in_array($k, ['group_key', 'group_label', 'metric', 'bucket', 'kind', 'availability', 'reasons', 'display_name', 'reason'], true)) {
                continue;
            }
            $r[$k] = $v === null ? null : (is_numeric($v) ? (str_contains((string) $v, '.') ? round((float) $v, 2) : (int) $v) : $v);
        }
    }
    return $rows;
}

/** A zero row for every live queue the caller may see that the result lacks (a queue is a group that exists even when nothing happened). */
function report_fill_queues(PDO $pdo, array $rows, ?int $queue, array $zero): array
{
    $have = array_column($rows, 'group_key');
    foreach (report_queues($pdo) as $q) {
        if (($queue === null || $q['queue_id'] === $queue) && !in_array($q['queue_id'], $have, true)) {
            $rows[] = ['group_key' => $q['queue_id'], 'group_label' => $q['name']] + $zero;
        }
    }
    usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['group_label'], (string) $b['group_label']));
    return $rows;
}

/** The queues the caller may report on: the live ones they hold (tickets.view_all → every one). */
function report_queues(PDO $pdo): array
{
    static $rows = null;
    if ($rows === null) {
        $rows = array_map(static fn (array $r): array => ['queue_id' => (int) $r['queue_id'], 'name' => $r['name']],
            $pdo->query("SELECT queue_id, name FROM mcp_queues WHERE archived_at IS NULL AND queue_id IN (SELECT hd_queue_ids_with_right('tickets.view_queue')) ORDER BY sort_order, lower(name)")->fetchAll());
    }
    return $rows;
}

function report_volume(PDO $pdo, array $p, string $groupBy, ?int $queue): array
{
    [$key, $label, $q, $args] = report_bind($p, $groupBy, $queue);
    $inP = static fn (string $col): string => "($col >= CAST(:start AS timestamptz) AND $col < CAST(:end AS timestamptz))";
    $channels = '';
    foreach (REPORT_CHANNELS as $c => $_) {
        $channels .= ", count(*) FILTER (WHERE " . $inP('t.created_at') . " AND t.channel = '$c') AS ch_$c";
    }
    $channels .= ", count(*) FILTER (WHERE " . $inP('t.created_at') . " AND t.channel NOT IN ('" . implode("','", array_keys(REPORT_CHANNELS)) . "')) AS ch_other";
    $rows = report_rows($pdo, "SELECT $key AS group_key, $label AS group_label,
            count(*) FILTER (WHERE " . $inP('t.created_at') . ") AS created,
            count(*) FILTER (WHERE " . $inP('t.solved_at') . ") AS solved,
            count(*) FILTER (WHERE " . $inP('t.closed_at') . ") AS closed,
            count(*) FILTER (WHERE t.reopen_count > 0 AND " . $inP('t.solved_at') . ") AS reopened,
            count(*) FILTER (WHERE t.created_at < CAST(:end AS timestamptz) AND (t.solved_at IS NULL OR t.solved_at >= CAST(:end AS timestamptz)) AND t.status_category <> 'closed') AS open_at_end
            $channels
          FROM mcp_tickets t
         WHERE t.merged_into_id IS NULL AND t.created_at < CAST(:end AS timestamptz)
           AND (t.created_at >= CAST(:start AS timestamptz) OR t.solved_at >= CAST(:start AS timestamptz) OR t.closed_at >= CAST(:start AS timestamptz) OR t.solved_at IS NULL) $q
         GROUP BY 1, 2 ORDER BY 2", $args);
    if ($groupBy === 'queue') {
        $rows = report_fill_queues($pdo, $rows, $queue, ['created' => 0, 'solved' => 0, 'closed' => 0, 'reopened' => 0, 'open_at_end' => 0] + array_fill_keys(array_map(static fn (string $c): string => 'ch_' . $c, array_keys(REPORT_CHANNELS)), 0) + ['ch_other' => 0]);
    }
    return $rows;
}

/** Tickets created in the period: how many got a first response, the median and p90 minutes (the SLA clock's, pauses out; else from creation), and the attainment of a first-response target. */
function report_first_response(PDO $pdo, array $p, string $groupBy, ?int $queue): array
{
    [$key, $label, $q, $args] = report_bind($p, $groupBy, $queue);
    $rows = report_rows($pdo, "SELECT $key AS group_key, $label AS group_label, count(*) AS tickets, count(t.first_response_at) AS responded,
            percentile_cont(0.5) WITHIN GROUP (ORDER BY m.mins) AS median_minutes, percentile_cont(0.9) WITHIN GROUP (ORDER BY m.mins) AS p90_minutes,
            count(g.target_id) AS with_target, count(*) FILTER (WHERE g.met_at IS NOT NULL AND g.met_at <= g.due_at) AS within_target
          FROM mcp_tickets t
          LEFT JOIN LATERAL (SELECT g.target_id, g.met_at, g.due_at, g.started_at, g.paused_total FROM mcp_sla_targets g WHERE g.ticket_id = t.ticket_id AND g.metric = 'first_response' AND g.due_at IS NOT NULL ORDER BY g.target_id LIMIT 1) g ON true
          LEFT JOIN LATERAL (SELECT CASE WHEN t.first_response_at IS NULL THEN NULL
                                         WHEN g.met_at IS NOT NULL THEN EXTRACT(EPOCH FROM (g.met_at - g.started_at - COALESCE(g.paused_total, INTERVAL '0'))) / 60
                                         ELSE EXTRACT(EPOCH FROM (t.first_response_at - t.created_at)) / 60 END AS mins) m ON true
         WHERE t.merged_into_id IS NULL AND t.created_at >= CAST(:start AS timestamptz) AND t.created_at < CAST(:end AS timestamptz) $q
         GROUP BY 1, 2 ORDER BY 2", $args);
    return $groupBy === 'queue' ? report_fill_queues($pdo, $rows, $queue, ['tickets' => 0, 'responded' => 0, 'median_minutes' => null, 'p90_minutes' => null, 'with_target' => 0, 'within_target' => 0]) : $rows;
}

/** Tickets solved in the period: the median and p90 hours (the resolution clock's, else from creation) and the attainment of a resolution target. */
function report_resolution(PDO $pdo, array $p, string $groupBy, ?int $queue): array
{
    [$key, $label, $q, $args] = report_bind($p, $groupBy, $queue);
    $rows = report_rows($pdo, "SELECT $key AS group_key, $label AS group_label, count(*) AS solved,
            percentile_cont(0.5) WITHIN GROUP (ORDER BY m.hours) AS median_hours, percentile_cont(0.9) WITHIN GROUP (ORDER BY m.hours) AS p90_hours,
            count(g.target_id) AS with_target, count(*) FILTER (WHERE g.met_at IS NOT NULL AND g.met_at <= g.due_at) AS within_target
          FROM mcp_tickets t
          LEFT JOIN LATERAL (SELECT g.target_id, g.met_at, g.due_at, g.started_at, g.paused_total FROM mcp_sla_targets g WHERE g.ticket_id = t.ticket_id AND g.metric = 'resolution' AND g.due_at IS NOT NULL ORDER BY g.target_id LIMIT 1) g ON true
          LEFT JOIN LATERAL (SELECT CASE WHEN g.met_at IS NOT NULL THEN EXTRACT(EPOCH FROM (g.met_at - g.started_at - COALESCE(g.paused_total, INTERVAL '0'))) / 3600
                                         ELSE EXTRACT(EPOCH FROM (t.solved_at - t.created_at)) / 3600 END AS hours) m ON true
         WHERE t.merged_into_id IS NULL AND t.solved_at >= CAST(:start AS timestamptz) AND t.solved_at < CAST(:end AS timestamptz) $q
         GROUP BY 1, 2 ORDER BY 2", $args);
    return $groupBy === 'queue' ? report_fill_queues($pdo, $rows, $queue, ['solved' => 0, 'median_hours' => null, 'p90_hours' => null, 'with_target' => 0, 'within_target' => 0]) : $rows;
}

/** Per group × metric, the targets with a due time whose ticket was created in the period: due, met before due, breached, the median minutes over on breaches. */
function report_sla_attainment(PDO $pdo, array $p, string $groupBy, ?int $queue): array
{
    [$key, $label, $q, $args] = report_bind($p, $groupBy, $queue);
    $breached = "(g.breached_at IS NOT NULL OR (g.met_at IS NULL AND now() > g.due_at) OR (g.met_at IS NOT NULL AND g.met_at > g.due_at))";
    return report_rows($pdo, "SELECT $key AS group_key, $label AS group_label, g.metric, count(*) AS due,
            count(*) FILTER (WHERE g.met_at IS NOT NULL AND g.met_at <= g.due_at) AS met,
            count(*) FILTER (WHERE $breached) AS breached,
            percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (COALESCE(g.met_at, now()) - g.due_at)) / 60) FILTER (WHERE $breached) AS median_minutes_over
          FROM mcp_sla_targets g JOIN mcp_tickets t ON t.ticket_id = g.ticket_id
         WHERE g.due_at IS NOT NULL AND t.merged_into_id IS NULL AND t.created_at >= CAST(:start AS timestamptz) AND t.created_at < CAST(:end AS timestamptz) $q
         GROUP BY 1, 2, 3 ORDER BY 2, 3", $args);
}

/** Tickets solved in the period: how many were reopened at least once, and the median days from creation to the first reopening (the solve before it is not held — see the spec's open question). */
function report_reopen_rate(PDO $pdo, array $p, string $groupBy, ?int $queue): array
{
    [$key, $label, $q, $args] = report_bind($p, $groupBy, $queue);
    $rows = report_rows($pdo, "SELECT $key AS group_key, $label AS group_label, count(*) AS solved, count(*) FILTER (WHERE t.reopen_count > 0) AS reopened,
            percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (t.first_reopened_at - t.created_at)) / 86400) FILTER (WHERE t.reopen_count > 0 AND t.first_reopened_at IS NOT NULL) AS median_days_to_reopen
          FROM mcp_tickets t
         WHERE t.merged_into_id IS NULL AND t.solved_at >= CAST(:start AS timestamptz) AND t.solved_at < CAST(:end AS timestamptz) $q
         GROUP BY 1, 2 ORDER BY 2", $args);
    return $groupBy === 'queue' ? report_fill_queues($pdo, $rows, $queue, ['solved' => 0, 'reopened' => 0, 'median_days_to_reopen' => null]) : $rows;
}

/** Surveys sent in the period: answered, good and bad by the scale (good_bad: 5 is good; five_point: 4 and 5), the mean on five-point, the bad reasons counted. */
function report_csat(PDO $pdo, array $p, string $groupBy, ?int $queue): array
{
    [$key, $label, $q, $args] = report_bind($p, $groupBy, $queue);
    $scale = find_csat_settings($pdo)['scale'];
    $good = $scale === 'five_point' ? 4 : 5;
    $rows = report_rows($pdo, "SELECT $key AS group_key, $label AS group_label, count(*) AS sent, count(r.responded_at) AS answered,
            count(*) FILTER (WHERE r.responded_at IS NOT NULL AND r.score >= $good) AS good, count(*) FILTER (WHERE r.responded_at IS NOT NULL AND r.score < $good) AS bad,
            avg(r.score) FILTER (WHERE r.responded_at IS NOT NULL) AS mean_score
          FROM mcp_satisfaction_ratings r JOIN mcp_tickets t ON t.ticket_id = r.ticket_id
         WHERE r.survey_sent_at >= CAST(:start AS timestamptz) AND r.survey_sent_at < CAST(:end AS timestamptz) $q
         GROUP BY 1, 2 ORDER BY 2", $args);
    $reasons = report_rows($pdo, "SELECT $key AS group_key, r.bad_reason AS reason, count(*) AS n
          FROM mcp_satisfaction_ratings r JOIN mcp_tickets t ON t.ticket_id = r.ticket_id
         WHERE r.responded_at IS NOT NULL AND r.bad_reason IS NOT NULL AND r.survey_sent_at >= CAST(:start AS timestamptz) AND r.survey_sent_at < CAST(:end AS timestamptz) $q
         GROUP BY 1, 2 ORDER BY 3 DESC, 2", $args);
    $byGroup = [];
    foreach ($reasons as $r) {
        $byGroup[(string) $r['group_key']][(string) $r['reason']] = (int) $r['n'];
    }
    foreach ($rows as &$row) {
        $row['scale'] = $scale;
        $row['reasons'] = $byGroup[(string) $row['group_key']] ?? [];
    }
    return $groupBy === 'queue' ? report_fill_queues($pdo, $rows, $queue, ['sent' => 0, 'answered' => 0, 'good' => 0, 'bad' => 0, 'mean_score' => null, 'scale' => $scale, 'reasons' => []]) : $rows;
}

/** Per assignee (person or agent): open now, solved in the period, the median resolution hours, minutes logged in the period, needs reply now. */
function report_workload(PDO $pdo, array $p, ?int $queue): array
{
    $args = ['start' => $p['start'], 'end' => $p['end']];
    $q = '';
    if ($queue !== null) {
        $q = ' AND t.queue_id = :queue';
        $args['queue'] = $queue;
    }
    $open = "t.status_category IN ('" . implode("','", REPORT_OPEN_CATEGORIES) . "')";
    $rows = report_rows($pdo, "SELECT t.assignee_member_id AS member_id, t.assignee_name AS display_name, t.assignee_kind AS kind,
            count(*) FILTER (WHERE $open) AS open_now,
            count(*) FILTER (WHERE t.solved_at >= CAST(:start AS timestamptz) AND t.solved_at < CAST(:end AS timestamptz)) AS solved,
            percentile_cont(0.5) WITHIN GROUP (ORDER BY EXTRACT(EPOCH FROM (t.solved_at - t.created_at)) / 3600) FILTER (WHERE t.solved_at >= CAST(:start AS timestamptz) AND t.solved_at < CAST(:end AS timestamptz)) AS median_hours,
            count(*) FILTER (WHERE $open AND t.needs_reply) AS needs_reply
          FROM mcp_tickets t
         WHERE t.assignee_member_id IS NOT NULL AND t.merged_into_id IS NULL AND ($open OR (t.solved_at >= CAST(:start AS timestamptz) AND t.solved_at < CAST(:end AS timestamptz))) $q
         GROUP BY 1, 2, 3 ORDER BY 2", $args);
    $minutes = report_rows($pdo, "SELECT e.member_id, sum(e.minutes) AS minutes FROM mcp_time_entries e JOIN mcp_tickets t ON t.ticket_id = e.ticket_id
         WHERE e.worked_on >= CAST(:from AS date) AND e.worked_on <= CAST(:to AS date) $q GROUP BY 1", ['from' => $p['from'], 'to' => $p['to']] + ($queue !== null ? ['queue' => $queue] : []));
    $byMember = [];
    foreach ($minutes as $m) {
        $byMember[(int) $m['member_id']] = (int) $m['minutes'];
    }
    foreach ($rows as &$r) {
        $r['minutes_logged'] = $byMember[(int) $r['member_id']] ?? 0;
    }
    return $rows;
}

/** Open tickets now by age bucket and priority, per group ('none' = one group). */
function report_aging(PDO $pdo, string $groupBy, ?int $queue): array
{
    $args = [];
    if ($groupBy === 'none') {
        $key = "'all'";
        $label = "'All'";
    } else {
        [$key, $label, $tzNeeded] = report_group_sql($groupBy);
        if ($tzNeeded) {
            $args['tz'] = business_timezone($pdo);
        }
    }
    $q = '';
    if ($queue !== null) {
        $q = ' AND t.queue_id = :queue';
        $args['queue'] = $queue;
    }
    $bucket = "CASE WHEN t.created_at >= now() - INTERVAL '1 day' THEN 'today' WHEN t.created_at >= now() - INTERVAL '3 days' THEN 'd1_3' WHEN t.created_at >= now() - INTERVAL '7 days' THEN 'd4_7' WHEN t.created_at >= now() - INTERVAL '30 days' THEN 'd8_30' ELSE 'older' END";
    $prios = '';
    foreach (REPORT_PRIORITIES as $pr) {
        $prios .= ", count(*) FILTER (WHERE t.priority = '$pr') AS $pr";
    }
    $rows = report_rows($pdo, "SELECT $key AS group_key, $label AS group_label, $bucket AS bucket, count(*) AS total $prios
          FROM mcp_tickets t
         WHERE t.merged_into_id IS NULL AND t.status_category IN ('" . implode("','", REPORT_OPEN_CATEGORIES) . "') $q
         GROUP BY 1, 2, 3", $args);
    // every bucket for every group, in bucket order
    $groups = [];
    foreach ($rows as $r) {
        $groups[(string) $r['group_key']] ??= ['label' => $r['group_label'], 'buckets' => []];
        $groups[(string) $r['group_key']]['buckets'][$r['bucket']] = $r;
    }
    if ($groupBy === 'none' && $groups === []) {
        $groups['all'] = ['label' => 'All', 'buckets' => []];
    }
    if ($groupBy === 'queue') {
        foreach (report_queues($pdo) as $qq) {
            if (($queue === null || $qq['queue_id'] === $queue) && !isset($groups[(string) $qq['queue_id']])) {
                $groups[(string) $qq['queue_id']] = ['label' => $qq['name'], 'buckets' => []];
            }
        }
    }
    uasort($groups, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));
    $out = [];
    foreach ($groups as $gk => $g) {
        foreach (AGING_BUCKETS as $bk => $_) {
            $out[] = ['group_key' => $gk, 'group_label' => $g['label'], 'bucket' => $bk] + array_diff_key($g['buckets'][$bk] ?? ['total' => 0] + array_fill_keys(REPORT_PRIORITIES, 0), ['group_key' => 1, 'group_label' => 1, 'bucket' => 1]);
        }
    }
    return $out;
}

/** The dashboard's headline counts over the open tickets the caller may see. */
function dashboard_counts(PDO $pdo, ?int $queue): array
{
    $args = [];
    $q = '';
    if ($queue !== null) {
        $q = ' AND t.queue_id = :queue';
        $args['queue'] = $queue;
    }
    $cats = '';
    foreach (REPORT_OPEN_CATEGORIES as $c) {
        $cats .= ", count(*) FILTER (WHERE t.status_category = '$c') AS cat_$c";
    }
    $r = report_rows($pdo, "SELECT count(*) AS open $cats,
            count(*) FILTER (WHERE t.needs_reply) AS needs_reply, count(*) FILTER (WHERE t.assignee_member_id IS NULL) AS unassigned,
            count(*) FILTER (WHERE t.sla_state = 'at_risk') AS at_risk, count(*) FILTER (WHERE t.sla_state = 'breached') AS breached,
            count(*) FILTER (WHERE t.last_activity_at < now() - INTERVAL '7 days') AS stale
          FROM mcp_tickets t WHERE t.merged_into_id IS NULL AND t.status_category IN ('" . implode("','", REPORT_OPEN_CATEGORIES) . "') $q", $args)[0] ?? [];
    $out = ['open' => (int) ($r['open'] ?? 0), 'by_category' => [], 'needs_reply' => (int) ($r['needs_reply'] ?? 0), 'unassigned' => (int) ($r['unassigned'] ?? 0), 'at_risk' => (int) ($r['at_risk'] ?? 0), 'breached' => (int) ($r['breached'] ?? 0), 'stale' => (int) ($r['stale'] ?? 0)];
    foreach (REPORT_OPEN_CATEGORIES as $c) {
        $out['by_category'][$c] = (int) ($r['cat_' . $c] ?? 0);
    }
    return $out;
}

/** Per priority, urgent first: open, at risk (at_risk + breached), needs reply. */
function dashboard_by_priority(PDO $pdo, ?int $queue): array
{
    $args = [];
    $q = '';
    if ($queue !== null) {
        $q = ' AND t.queue_id = :queue';
        $args['queue'] = $queue;
    }
    $rows = report_rows($pdo, "SELECT t.priority AS group_key, count(*) AS open, count(*) FILTER (WHERE t.sla_state IN ('at_risk', 'breached')) AS at_risk, count(*) FILTER (WHERE t.needs_reply) AS needs_reply
          FROM mcp_tickets t WHERE t.merged_into_id IS NULL AND t.status_category IN ('" . implode("','", REPORT_OPEN_CATEGORIES) . "') $q GROUP BY 1", $args);
    $by = array_column($rows, null, 'group_key');
    $out = [];
    foreach (REPORT_PRIORITIES as $p) {
        $out[] = ['priority' => $p, 'open' => (int) ($by[$p]['open'] ?? 0), 'at_risk' => (int) ($by[$p]['at_risk'] ?? 0), 'needs_reply' => (int) ($by[$p]['needs_reply'] ?? 0)];
    }
    return $out;
}

/** Workload now: everyone with open tickets plus the queue's members with none; capacity and availability from queue_presence (the dashboard's one base-table read). */
function dashboard_workload(PDO $pdo, ?int $queue): array
{
    $now = ['start' => (new DateTimeImmutable('-30 days'))->format('c'), 'end' => (new DateTimeImmutable('+1 day'))->format('c'), 'from' => (new DateTimeImmutable('-30 days'))->format('Y-m-d'), 'to' => (new DateTimeImmutable())->format('Y-m-d')];
    $rows = [];
    foreach (report_workload($pdo, $now, $queue) as $r) {
        $rows[(int) $r['member_id']] = $r;
    }
    $members = $queue !== null ? report_rows($pdo, 'SELECT DISTINCT member_id, display_name, member_kind AS kind FROM mcp_queue_members WHERE queue_id = :q', ['q' => $queue])
        : report_rows($pdo, 'SELECT DISTINCT member_id, display_name, member_kind AS kind FROM mcp_queue_members', []);
    foreach ($members as $m) {
        $rows[(int) $m['member_id']] ??= ['member_id' => (int) $m['member_id'], 'display_name' => $m['display_name'], 'kind' => $m['kind'], 'open_now' => 0, 'solved' => 0, 'median_hours' => null, 'needs_reply' => 0, 'minutes_logged' => 0];
    }
    $presence = [];
    foreach ($pdo->query('SELECT member_id, availability, capacity FROM queue_presence')->fetchAll() as $p) {
        $presence[(int) $p['member_id']] = ['availability' => $p['availability'], 'capacity' => $p['capacity'] === null ? null : (int) $p['capacity']];
    }
    $risk = [];
    $args = [];
    $q = '';
    if ($queue !== null) {
        $q = ' AND t.queue_id = :queue';
        $args['queue'] = $queue;
    }
    foreach (report_rows($pdo, "SELECT t.assignee_member_id AS member_id, count(*) AS at_risk FROM mcp_tickets t WHERE t.assignee_member_id IS NOT NULL AND t.merged_into_id IS NULL AND t.status_category IN ('" . implode("','", REPORT_OPEN_CATEGORIES) . "') AND t.sla_state IN ('at_risk', 'breached') $q GROUP BY 1", $args) as $r) {
        $risk[(int) $r['member_id']] = (int) $r['at_risk'];
    }
    foreach ($rows as $id => &$r) {
        $r['at_risk'] = $risk[$id] ?? 0;
        $r['availability'] = $presence[$id]['availability'] ?? null;
        $r['capacity'] = $presence[$id]['capacity'] ?? null;
        $r['over_capacity'] = $r['capacity'] !== null && $r['capacity'] > 0 && $r['open_now'] > $r['capacity'];
    }
    unset($r);
    $out = array_values($rows);
    usort($out, static fn (array $a, array $b): int => [$b['open_now'], (string) $a['display_name']] <=> [$a['open_now'], (string) $b['display_name']]);
    return $out;
}
