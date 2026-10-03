<?php
/** Helpers for the slice 5 proofs (docs/build-specs/working-faster.md, "Proof"): slice 3's library and world, plus canned responses as fixtures and HTMX-shaped posts. */
require dirname(__DIR__) . '/slice3/lib.php';

function canned_row(int $id): ?array { $r = q('SELECT * FROM canned_responses WHERE id = :i', ['i' => $id]); return $r[0] ?? null; }
/** Save a canned response as a member: its id (0 when refused). */
function mkcanned(string $jar, array $f): int { [$c, $b] = act($jar, '/canned/save.php', $f); return $c === 200 ? (int) $b['record_id'] : 0; }
function hx(string $jar, string $path, array $form, string $target = ''): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => $tok[$jar]], 'headers' => array_filter(['HX-Request: true', $target !== '' ? 'HX-Target: ' . $target : ''])]);
    return [(int) $r['code'], $r['body'], $r['headers']];
}
function messages_of(int $ticket): array { return q('SELECT id, kind, channel, author_member_id, body_text FROM messages WHERE ticket_id = :t ORDER BY id', ['t' => $ticket]); }
