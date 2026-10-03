<?php
/** Helpers for the slice 6 proofs (docs/build-specs/requesters-doors.md, "Proof"): slice 3's library and world, plus the public doors — an anonymous session (a cookie jar with no member), its CSRF token, multipart posts, the mailed token out of the outbox. */
require dirname(__DIR__) . '/slice3/lib.php';

/** An anonymous visitor: a jar whose session has no member. */
function visitor(): string { $j = jar(); req('GET', '/request', ['jar' => $j]); return $j; }
function public_csrf(string $jar, string $path = '/request'): string { return csrf_of(req('GET', $path, ['jar' => $jar])['body']); }
/** A public POST as the visitor (multipart when files are given): [code, body, headers, location]. */
function public_post(string $jar, string $path, array $form, array $files = [], bool $csrf = true): array
{
    $ch = curl_init(BASE . $path);
    $fields = $form + ($csrf ? ['csrf_token' => public_csrf($jar, str_starts_with($path, '/t/') ? preg_replace('#/(reply|confirm|reopen|rate)$#', '', $path) : '/request')] : []);
    if ($files !== []) {
        foreach ($files as $i => $f) { $fields['files[' . $i . ']'] = new CURLFile($f); }
    } else {
        $fields = http_build_query($fields);
    }
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POSTFIELDS => $fields]);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($r, 0, $hs);
    return [$code, substr($r, $hs), $headers, preg_match('/^Location: (.+)$/mi', $headers, $m) ? trim($m[1]) : ''];
}
/** File a request as a visitor: [code, body, number|null]. */
function file_request(string $jar, string $email, string $subject = 'SMOKE from the form', array $extra = [], array $files = []): array
{
    [$c, $b] = public_post($jar, '/request', ['name' => 'SMOKE Visitor', 'email' => $email, 'subject' => $subject, 'body' => 'It does not work.'] + $extra, $files);
    return [$c, $b, preg_match('/id="request-done-number">([^<]+)</', $b, $m) ? $m[1] : null];
}
/** The raw secure token the created notice carried for a ticket. */
function mailed_token(int $ticket, ?string $kind = null): ?string
{
    foreach (q('SELECT body FROM notification_outbox WHERE ticket_id = :t' . ($kind !== null ? ' AND kind = :k' : '') . ' ORDER BY id DESC', ['t' => $ticket] + ($kind !== null ? ['k' => $kind] : [])) as $r) {
        if (preg_match('#/t/([a-f0-9]{48})(?:/rate)?#', $r['body'], $m)) { return $m[1]; }
    }
    return null;
}
/** A request filed with no category lands in the general queue: Marco works it once the owner adds him there. */
function marco_in_general(): void { static $done = false; if (!$done) { act(as_member(1), '/settings/queues/member.php', ['queue' => general_queue(), 'member' => 27]); $done = true; } }
function ticket_by_number(string $number): int { return (int) one('SELECT id FROM tickets WHERE number = :n', ['n' => $number]); }
function secure_page(string $jar, string $token): array { return req('GET', '/t/' . $token, ['jar' => $jar]); }
function rating_row(int $ticket): ?array { $r = q('SELECT * FROM satisfaction_ratings WHERE ticket_id = :t', ['t' => $ticket]); return $r[0] ?? null; }
