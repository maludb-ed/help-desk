<?php
/** Proof — period, grouping and the CSV (spec "Proof", 1 and 2): the CSV equals the table cell for cell with every row; formula-looking cells are prefixed; report.export logged; from > to and 367 days → 422; week buckets on Monday; an unknown report → 404. */
require __DIR__ . '/lib.php';
$F = dated();
$lena = as_member(28);
$P = sixty();
echo "1. The CSV\n";
[$c, $d] = report_json('volume', $P + ['group_by' => 'organization']);
$since = last_activity_id();
$r = page($lena, $d['csv']);
ok($r['code'] === 200 && str_contains($r['headers'], 'text/csv') && str_contains($r['headers'], 'filename="helpdesk-volume-' . $P['from'] . '-' . $P['to'] . '.csv"'), 'the CSV downloads with its name');
$lines = explode("\r\n", rtrim($r['body'], "\r\n"));
ok(str_ends_with($r['body'], "\r\n") && count($lines) === count($d['rows']) + 1, 'CRLF lines: a header and every row (' . (count($lines) - 1) . ')');
ok(str_getcsv($lines[0]) === $d['columns'], 'the header is the columns');
$same = true;
foreach ($d['rows'] as $i => $row) {
    $cells = str_getcsv($lines[$i + 1]);
    $expect = array_map(static fn (string $v): string => $v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v) ? "'" . $v : $v, $row);
    if ($cells !== $expect) { $same = false; fwrite(STDERR, json_encode([$cells, $expect]) . "\n"); }
}
ok($same, 'the CSV equals the table cell for cell');
$dashRow = array_values(array_filter($lines, static fn (string $l): bool => str_contains($l, 'SMOKE Dash Co')));
ok($dashRow !== [] && str_starts_with($dashRow[0], "'-SMOKE Dash Co"), "a cell beginning with - is prefixed with an apostrophe ('-SMOKE Dash Co)");
ok(in_array('-SMOKE Dash Co', array_column($d['rows'], 0), true), 'while the page shows it unprefixed');
$rows = activity('report.export', $since);
ok(count($rows) === 1 && json_decode($rows[0]['after'], true) == ['report' => 'volume', 'queue_id' => null, 'from' => $P['from'], 'to' => $P['to'], 'group_by' => 'organization', 'rows' => count($d['rows'])], 'report.export logged with report, period, grouping and the row count');
ok(str_contains(csv_text(['a'], [['="cmd"'], ['+1'], ['@x'], ['-5'], ['x,y'], ["q\"q"]]), "\"'=\"\"cmd\"\"\"\r\n+1\r\n'@x\r\n-5\r\n\"x,y\"\r\n\"q\"\"q\"\r\n"), 'RFC 4180 quoting; a cell that is a number (+1, -5) is never prefixed');
echo "2. The period\n";
$r = page($lena, '/reports/volume?from=2026-02-01&to=2026-01-01');
ok($r['code'] === 422 && str_contains(html_entity_decode(strip_tags($r['body']), ENT_QUOTES), "The period's start is after its end."), 'from > to: 422 "The period\'s start is after its end."');
$r = page($lena, '/reports/volume?from=2025-01-01&to=2026-01-02');
ok($r['code'] === 422 && str_contains(strip_tags($r['body']), 'A period is at most 366 days.'), '367 days: 422 "A period is at most 366 days."');
ok(page($lena, '/reports/volume?from=2025-01-01&to=2026-01-01')['code'] === 200, '366 days: fine');
ok(page($lena, '/reports/volume?from=yesterday')['code'] === 422, 'a malformed date: 422');
[$c, $d] = report_json('volume');
ok($c === 200 && $d['period']['days'] === 30 && $d['period']['to'] === (new DateTimeImmutable('today', new DateTimeZone($d['period']['timezone'])))->format('Y-m-d'), 'the default period is the last 30 days ending today in the business zone');
[$c, $d] = report_json('volume', $P + ['group_by' => 'week']);
$mondays = true;
foreach ($d['rows'] as $row) { if (!preg_match('/^Week of (\d{4}-\d{2}-\d{2})$/', $row[0], $m) || (new DateTimeImmutable($m[1]))->format('N') !== '1') { $mondays = false; } }
ok($c === 200 && count($d['rows']) >= 3 && $mondays, 'group_by=week buckets on Mondays (' . count($d['rows']) . ' weeks)');
[$c, $d] = report_json('volume', $P + ['group_by' => 'day']);
ok($c === 200 && count($d['rows']) >= 8 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['rows'][0][0]) === 1, 'group_by=day: one row per day with a ticket');
$r = page($lena, '/reports/volume?group_by=colour');
ok($r['code'] === 422 && str_contains(html_entity_decode(strip_tags($r['body']), ENT_QUOTES), "'colour' is not a grouping"), 'an unknown grouping: 422 by name');
$r = page($lena, '/reports/backlog');
ok($r['code'] === 404 && str_contains(strip_tags($r['body']), 'No such report.'), 'an unknown report: 404 "No such report."');
[$c, $d] = report_json('workload', $P + ['group_by' => 'day']);
ok($c === 200 && $d['group_by'] === null && $d['columns'][0] === 'Assignee', 'workload ignores a grouping (per assignee)');
[$c, $d] = report_json('aging', ['from' => '2020-01-01', 'to' => '2020-01-02']);
ok($c === 200 && cell_int($d['totals'][6]) > 0, 'aging is about now whatever the period says');
exit($GLOBALS['fails'] === 0 ? 0 : 1);
