<?php
declare(strict_types=1);

/** Reading a mail (slice 7): the text above the quoted history, auto-replies and bounces, addresses and the subject's ticket tag. */

const MAIL_MARKER = '## Reply above this line ##';

/** What the person wrote: cut at "On … wrote:", "-----Original Message-----", "From: … Sent: …", a run of quoted lines to the end, or our marker. */
function mail_strip_quoted(string $text): string
{
    $lines = preg_split('/\r\n|\n/', $text) ?: [];
    $out = [];
    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === MAIL_MARKER || preg_match('/^On .{4,200} wrote:$/u', $t) || preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $t) || preg_match('/^_{10,}$/', $t) || $t === '-- ') {
            break;
        }
        if (preg_match('/^From: .+/', $t) && isset($lines[$i + 1]) && preg_match('/^(Sent|Date|To): /', trim($lines[$i + 1]))) {
            break;
        }
        if (str_starts_with($t, '>')) {
            $rest = array_slice($lines, $i);
            if (array_filter($rest, static fn (string $l): bool => trim($l) !== '' && !str_starts_with(trim($l), '>')) === []) {
                break;                                                   // quoted to the end
            }
            continue;
        }
        $out[] = rtrim($line);
    }
    return trim(implode("\n", $out));
}

/** Auto-Submitted other than no, X-Autoreply, Precedence bulk/auto_reply/junk. */
function mail_is_auto(array $headers): bool
{
    $h = array_change_key_case($headers, CASE_LOWER);
    $as = strtolower(trim((string) ($h['auto-submitted'] ?? ($h['auto_submitted'] ?? ''))));
    if ($as !== '' && $as !== 'no') {
        return true;
    }
    if (isset($h['x-autoreply']) || isset($h['x-autorespond'])) {
        return true;
    }
    $p = strtolower(trim((string) ($h['precedence'] ?? '')));
    return in_array($p, ['bulk', 'auto_reply', 'junk', 'list'], true);
}

/** A delivery-status report or a mailer-daemon: the bounced Message-ID when the report names one (else '') — null when it is no bounce. */
function mail_is_bounce(array $headers, array $parts): ?string
{
    $h = array_change_key_case($headers, CASE_LOWER);
    $from = mail_address_of((string) ($h['from'] ?? ''));
    $ct = strtolower((string) ($h['content-type'] ?? ($h['content_type'] ?? '')));
    $isReport = str_contains($ct, 'multipart/report') && str_contains($ct, 'delivery-status');
    $isDaemon = preg_match('/^(mailer-daemon|postmaster)@/i', $from) === 1;
    if (!$isReport && !$isDaemon) {
        return null;
    }
    foreach ($parts as $p) {
        $txt = is_array($p) ? (string) ($p['text'] ?? ($p['body'] ?? '')) : (string) $p;
        if (preg_match('/Original-Message-ID:\s*(<[^>]+>)/i', $txt, $m) || preg_match('/Message-ID:\s*(<[^>]+>)/i', $txt, $m)) {
            return trim($m[1]);
        }
    }
    return '';
}

/** The bare address of a From/To header ("Name <a@b>" → a@b), lower-cased; '' when none. */
function mail_address_of(string $from): string
{
    $addr = preg_match('/<([^<>\s]+@[^<>\s]+)>/', $from, $m) ? $m[1] : trim($from);
    return filter_var($addr, FILTER_VALIDATE_EMAIL) ? strtolower($addr) : '';
}

/** The display name of a From header, else ''. */
function mail_name_of(string $from): string
{
    if (preg_match('/^\s*"?([^"<]*?)"?\s*<[^>]+>\s*$/', $from, $m)) {
        return trim($m[1]);
    }
    return '';
}

/** A list of addresses out of a To/Cc header or array. */
function mail_addresses_of($header): array
{
    $items = is_array($header) ? $header : preg_split('/,(?![^<]*>)/', (string) $header);
    return array_values(array_unique(array_filter(array_map(static fn ($x): string => mail_address_of((string) $x), $items ?: []))));
}

/** The subject without Re:/Fwd: prefixes. */
function mail_clean_subject(string $subject): string
{
    return trim((string) preg_replace('/^\s*((re|fwd?|aw|sv|antw):\s*)+/i', '', $subject));
}

/** "[HD-1042]" in a subject → the number, else null. */
function mail_subject_ticket_number(string $subject): ?string
{
    return preg_match('/\[([A-Z]{1,6}-\d{3,})\]/', $subject, $m) ? $m[1] : null;
}

/** A Message-ID with its angle brackets. */
function mail_norm_id(string $id): string
{
    $id = trim($id);
    return $id === '' ? '' : (str_starts_with($id, '<') ? $id : '<' . $id . '>');
}
