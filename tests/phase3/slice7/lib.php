<?php
/** Helpers for the slice 7 proofs (docs/build-specs/email-notifications.md, "Proof"): slice 3's library and world, the stubbed MaluMail (messages the proofs put in its state; what it received), the signed webhook, the outbox batch and the poll run in-process. */
require dirname(__DIR__) . '/slice3/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
pdo()->exec("SELECT set_config('app.member_id', '1', false)");

const MAILBOX_ADDRESS = 'support@example.invalid';
function malumail_state(?callable $change = null): array
{
    $f = need('FAKE_MALUMAIL_STATE');
    $s = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    if (!is_array($s)) { usleep(50000); $s = is_file($f) ? json_decode((string) file_get_contents($f), true) : null; }
    $s = is_array($s) ? $s : ['mailboxes' => []];
    if ($change !== null) { $s = $change($s); file_put_contents($f . '.tmp', json_encode($s)); rename($f . '.tmp', $f); }
    return $s;
}
/** A message in the stub's mailbox. $headers: from, subject, message_id, in_reply_to, references, to, cc, auto_submitted, precedence, content_type. */
function put_mail(int $uid, array $headers, string $text, array $extra = []): void
{
    $h = ['to' => MAILBOX_ADDRESS, 'message_id' => '<mail-' . $uid . '@customer.invalid>', 'date' => date('r')] + $headers;
    malumail_state(function ($s) use ($uid, $h, $text, $extra) { $s['mailboxes'][MAILBOX_ADDRESS][(string) $uid] = ['headers' => $h, 'text' => $text, 'flags' => []] + $extra; return $s; });
}
function mail_flags(int $uid): array { return malumail_state()['mailboxes'][MAILBOX_ADDRESS][(string) $uid]['flags'] ?? []; }
/** A signed new-mail notice: the code. */
function webhook(int $uid, ?string $secret = null, ?int $ts = null, string $address = MAILBOX_ADDRESS, string $method = 'POST', array $notice = []): int
{
    $body = json_encode($notice + ['type' => 'message.new', 'address' => $address, 'folder' => 'INBOX', 'uid' => $uid]);
    $t = $ts ?? time();
    $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret ?? need('MAILBOX_SUPPORT_WEBHOOK_SECRET'));
    $r = req($method, '/channels/malumail/mail', ['raw' => $body, 'headers' => ['Content-Type: application/json', 'X-MaluMail-Signature: ' . $sig]]);
    return $r['code'];
}
function malumail_sent(): array { $f = need('FAKE_MALUMAIL_LOG'); return is_file($f) ? array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", (string) file_get_contents($f))))) : []; }
function mcp_calls(): array { $f = need('FAKE_MALUMAIL_LOG') . '.mcp'; return is_file($f) ? array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", (string) file_get_contents($f))))) : []; }
function send_batch(): array { return outbox_send_batch(pdo(), 100); }
function poll_now(): int { return mail_poll_mailbox(pdo(), find_mailbox(pdo(), mailbox_id())); }
function mailbox_id(): int { return (int) one('SELECT id FROM mailboxes WHERE address = :a', ['a' => MAILBOX_ADDRESS]); }
function inbound_row(int $uid): ?array { $r = q('SELECT * FROM inbound_email WHERE mailbox_id = :m AND provider_uid = :u', ['m' => mailbox_id(), 'u' => (string) $uid]); return $r[0] ?? null; }
function outbox_rows(int $ticket, ?string $channel = null): array { return q('SELECT * FROM notification_outbox WHERE ticket_id = :t' . ($channel ? ' AND channel = :c' : '') . ' ORDER BY id', ['t' => $ticket] + ($channel ? ['c' => $channel] : [])); }
function sms_mode($mode): void { kernel_state(function ($s) use ($mode) { $s['sms'] = is_array($mode) ? $mode : ['mode' => $mode]; return $s; }); }
/** The IT mailbox, made once by the owner. */
function ensure_mailbox(): int
{
    static $id = null;
    if ($id !== null) { return $id; }
    $id = mailbox_id();
    if ($id === 0) {
        [$c, $b] = act(as_member(1), '/settings/mailboxes/save.php', ['address' => MAILBOX_ADDRESS, 'display_name' => 'SMOKE Support', 'queue' => world()['it'], 'webhook_secret_ref' => 'MAILBOX_SUPPORT_WEBHOOK_SECRET', 'signature' => 'The IT desk', 'poll_enabled' => 'yes']);
        $id = (int) ($b['record_id'] ?? 0);
    }
    return $id;
}
