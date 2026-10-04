<?php
declare(strict_types=1);

/** The CSV of a report: UTF-8, CRLF, RFC 4180 quoting, a leading apostrophe on a cell that begins with =, +, - or @ (a spreadsheet would run it). */
function csv_cell(string $v): string
{
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true) && !is_numeric($v)) {
        $v = "'" . $v;
    }
    return preg_match('/[",\r\n]/', $v) ? '"' . str_replace('"', '""', $v) . '"' : $v;
}

function csv_text(array $columns, array $rows): string
{
    $lines = [implode(',', array_map('csv_cell', array_map('strval', $columns)))];
    foreach ($rows as $r) {
        $lines[] = implode(',', array_map('csv_cell', array_map('strval', $r)));
    }
    return implode("\r\n", $lines) . "\r\n";
}

/** Streams the whole table as a download and stops. */
function stream_csv(string $name, array $columns, array $rows): never
{
    $text = csv_text($columns, $rows);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"');
    header('Content-Length: ' . strlen($text));
    header('Cache-Control: private, no-store');
    echo $text;
    exit;
}
