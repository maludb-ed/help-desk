<?php
declare(strict_types=1);

/** A report is display text: report_cells() turns the typed rows into the strings the page, the JSON and the CSV all show. */
function fmt_int(mixed $v): string
{
    return $v === null ? '—' : number_format((float) $v, 0, '.', ',');
}
function fmt_num(mixed $v, int $dec = 1): string
{
    return $v === null ? '—' : number_format((float) $v, $dec, '.', ',');
}
function fmt_pct(mixed $part, mixed $whole): string
{
    return $whole === null || (int) $whole === 0 ? '—' : number_format((float) $part * 100 / (float) $whole, 0) . '%';
}

/** The columns of a report: [key => label], the first being the group. */
function report_columns(string $report, string $groupBy = 'queue'): array
{
    $g = REPORT_GROUPS[$groupBy] ?? 'Group';
    return match ($report) {
        'volume' => ['group' => $g, 'created' => 'Created', 'solved' => 'Solved', 'closed' => 'Closed', 'reopened' => 'Reopened', 'open_at_end' => 'Open at end', 'ch_web' => 'Web', 'ch_email' => 'Email', 'ch_form' => 'Form', 'ch_agent' => 'Agent', 'ch_api' => 'API', 'ch_other' => 'Other'],
        'first_response' => ['group' => $g, 'tickets' => 'Tickets', 'responded' => 'With a first response', 'median_minutes' => 'Median minutes', 'p90_minutes' => 'P90 minutes', 'with_target' => 'With a target', 'within_target' => 'Within target', 'pct_within' => '% within'],
        'resolution' => ['group' => $g, 'solved' => 'Solved', 'median_hours' => 'Median hours', 'p90_hours' => 'P90 hours', 'with_target' => 'With a target', 'within_target' => 'Within target', 'pct_within' => '% within'],
        'sla_attainment' => ['group' => $g, 'metric' => 'Metric', 'due' => 'Targets due', 'met' => 'Met before due', 'breached' => 'Breached', 'pct_attained' => '% attained', 'median_minutes_over' => 'Median minutes over'],
        'reopen_rate' => ['group' => $g, 'solved' => 'Solved', 'reopened' => 'Reopened', 'pct_reopened' => '% reopened', 'median_days_to_reopen' => 'Median days to reopen'],
        'csat' => ['group' => $g, 'sent' => 'Surveys sent', 'answered' => 'Answered', 'pct_answered' => '% answered', 'good' => 'Good', 'bad' => 'Bad', 'pct_good' => '% good', 'mean_score' => 'Mean (five-point)', 'reasons' => 'Bad reasons'],
        'workload' => ['group' => 'Assignee', 'kind' => 'Kind', 'open_now' => 'Open now', 'solved' => 'Solved in period', 'median_hours' => 'Median resolution hours', 'minutes_logged' => 'Minutes logged', 'needs_reply' => 'Needs reply now'],
        'aging' => ['group' => $g, 'bucket' => 'Age', 'urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low', 'total' => 'Total'],
        default => throw new DomainException('No such report.'),
    };
}

const SLA_METRIC_WORDS = ['first_response' => 'First response', 'next_response' => 'Next response', 'resolution' => 'Resolution'];

/** The rows as strings, one per column of report_columns(). */
function report_cells(string $report, array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $out[] = match ($report) {
            'volume' => [(string) $r['group_label'], fmt_int($r['created']), fmt_int($r['solved']), fmt_int($r['closed']), fmt_int($r['reopened']), fmt_int($r['open_at_end']), fmt_int($r['ch_web']), fmt_int($r['ch_email']), fmt_int($r['ch_form']), fmt_int($r['ch_agent']), fmt_int($r['ch_api']), fmt_int($r['ch_other'])],
            'first_response' => [(string) $r['group_label'], fmt_int($r['tickets']), fmt_int($r['responded']), fmt_num($r['median_minutes'], 0), fmt_num($r['p90_minutes'], 0), fmt_int($r['with_target']), fmt_int($r['within_target']), fmt_pct($r['within_target'], $r['with_target'])],
            'resolution' => [(string) $r['group_label'], fmt_int($r['solved']), fmt_num($r['median_hours']), fmt_num($r['p90_hours']), fmt_int($r['with_target']), fmt_int($r['within_target']), fmt_pct($r['within_target'], $r['with_target'])],
            'sla_attainment' => [(string) $r['group_label'], SLA_METRIC_WORDS[$r['metric']] ?? (string) $r['metric'], fmt_int($r['due']), fmt_int($r['met']), fmt_int($r['breached']), fmt_pct($r['met'], $r['due']), fmt_num($r['median_minutes_over'], 0)],
            'reopen_rate' => [(string) $r['group_label'], fmt_int($r['solved']), fmt_int($r['reopened']), fmt_pct($r['reopened'], $r['solved']), fmt_num($r['median_days_to_reopen'])],
            'csat' => [(string) $r['group_label'], fmt_int($r['sent']), fmt_int($r['answered']), fmt_pct($r['answered'], $r['sent']), fmt_int($r['good']), fmt_int($r['bad']), fmt_pct($r['good'], $r['answered']), ($r['scale'] ?? '') === 'five_point' ? fmt_num($r['mean_score']) : '—', reasons_words($r['reasons'] ?? [])],
            'workload' => [(string) $r['display_name'], ($r['kind'] ?? '') === 'agent' ? 'Agent' : 'Person', fmt_int($r['open_now']), fmt_int($r['solved']), fmt_num($r['median_hours']), fmt_int($r['minutes_logged']), fmt_int($r['needs_reply'])],
            'aging' => [(string) $r['group_label'], AGING_BUCKETS[$r['bucket']] ?? (string) $r['bucket'], fmt_int($r['urgent']), fmt_int($r['high']), fmt_int($r['normal']), fmt_int($r['low']), fmt_int($r['total'])],
            default => [],
        };
    }
    return $out;
}

function reasons_words(array $reasons): string
{
    if ($reasons === []) {
        return '—';
    }
    $parts = [];
    foreach ($reasons as $k => $n) {
        $parts[] = $k . ' (' . $n . ')';
    }
    return implode(', ', $parts);
}

/** The footer: the sum of every count column, '—' where a sum means nothing (medians, percentages recomputed from the sums). */
function report_totals(string $report, array $rows): array
{
    $sum = static fn (string $k): int => (int) array_sum(array_map(static fn (array $r): int => (int) ($r[$k] ?? 0), $rows));
    return match ($report) {
        'volume' => ['Total', fmt_int($sum('created')), fmt_int($sum('solved')), fmt_int($sum('closed')), fmt_int($sum('reopened')), fmt_int($sum('open_at_end')), fmt_int($sum('ch_web')), fmt_int($sum('ch_email')), fmt_int($sum('ch_form')), fmt_int($sum('ch_agent')), fmt_int($sum('ch_api')), fmt_int($sum('ch_other'))],
        'first_response' => ['Total', fmt_int($sum('tickets')), fmt_int($sum('responded')), '—', '—', fmt_int($sum('with_target')), fmt_int($sum('within_target')), fmt_pct($sum('within_target'), $sum('with_target'))],
        'resolution' => ['Total', fmt_int($sum('solved')), '—', '—', fmt_int($sum('with_target')), fmt_int($sum('within_target')), fmt_pct($sum('within_target'), $sum('with_target'))],
        'sla_attainment' => ['Total', '', fmt_int($sum('due')), fmt_int($sum('met')), fmt_int($sum('breached')), fmt_pct($sum('met'), $sum('due')), '—'],
        'reopen_rate' => ['Total', fmt_int($sum('solved')), fmt_int($sum('reopened')), fmt_pct($sum('reopened'), $sum('solved')), '—'],
        'csat' => ['Total', fmt_int($sum('sent')), fmt_int($sum('answered')), fmt_pct($sum('answered'), $sum('sent')), fmt_int($sum('good')), fmt_int($sum('bad')), fmt_pct($sum('good'), $sum('answered')), '—', ''],
        'workload' => ['Total', '', fmt_int($sum('open_now')), fmt_int($sum('solved')), '—', fmt_int($sum('minutes_logged')), fmt_int($sum('needs_reply'))],
        'aging' => ['Total', '', fmt_int($sum('urgent')), fmt_int($sum('high')), fmt_int($sum('normal')), fmt_int($sum('low')), fmt_int($sum('total'))],
        default => [],
    };
}

/** Runs one report: [columns, typed rows]. */
function run_report(PDO $pdo, string $report, array $p, string $groupBy, ?int $queue): array
{
    $rows = match ($report) {
        'volume' => report_volume($pdo, $p, $groupBy, $queue),
        'first_response' => report_first_response($pdo, $p, $groupBy, $queue),
        'resolution' => report_resolution($pdo, $p, $groupBy, $queue),
        'sla_attainment' => report_sla_attainment($pdo, $p, $groupBy, $queue),
        'reopen_rate' => report_reopen_rate($pdo, $p, $groupBy, $queue),
        'csat' => report_csat($pdo, $p, $groupBy, $queue),
        'workload' => report_workload($pdo, $p, $queue),
        'aging' => report_aging($pdo, $groupBy, $queue),
        default => throw new DomainException('No such report.'),
    };
    return [report_columns($report, $groupBy), $rows];
}

/** The inbox URL that lists exactly a dashboard count (the click-around rule). */
function inbox_link(?int $queue, array $filters): string
{
    $q = array_filter(['queue' => $queue] + $filters, static fn ($v) => $v !== null && $v !== '');
    return '/inbox' . ($q === [] ? '' : '?' . http_build_query($q));
}
