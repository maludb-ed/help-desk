<?php
/** Helpers for the slice 4 proofs (docs/build-specs/views-board.md, "Proof"): slice 3's library and its world, plus the board as JSON and HTMX-shaped posts. */
require dirname(__DIR__) . '/slice3/lib.php';

/** The board as JSON: [code, data]. */
function board(string $jar, string $query = ''): array { return screen($jar, '/board' . ($query === '' ? '' : '?' . $query)); }
/** column key → [ticket ids] of a board's columns (the top level, or a lane's). */
function columns_of(array $data, ?string $lane = null): array
{
    $cols = $lane === null ? ($data['board']['columns'] ?? []) : (array_values(array_filter($data['board']['lanes'] ?? [], fn ($l) => $l['key'] === $lane))[0]['columns'] ?? []);
    $out = [];
    foreach ($cols as $c) { $out[$c['key']] = array_map(fn ($t) => $t['ticket_id'], $c['cards']); }
    return $out;
}
function column_of(array $data, int $ticket): ?string { foreach (columns_of($data) as $k => $ids) { if (in_array($ticket, $ids, true)) { return $k; } } return null; }
/** An HTMX-shaped POST as a signed-on person (what a drag or the card's select sends): [code, body, headers]. */
function hx(string $jar, string $path, array $form, string $target = ''): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => $tok[$jar]], 'headers' => array_filter(['HX-Request: true', $target !== '' ? 'HX-Target: ' . $target : ''])]);
    return [(int) $r['code'], $r['body'], $r['headers']];
}
/** A plain browser form POST (no HTMX, no JSON): [code, headers]. */
function plain(string $jar, string $path, array $form): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [(int) $r['code'], $r['headers']];
}
function inbox_ids(string $jar, string $query): array { [$c, $d] = screen($jar, '/inbox' . ($query === '' ? '' : '?' . $query)); return $c === 200 ? array_map(fn ($t) => $t['ticket_id'], $d['tickets'] ?? []) : [-1]; }
function view_row(int $id): ?array { $r = q('SELECT * FROM saved_views WHERE id = :i', ['i' => $id]); return $r[0] ?? null; }
