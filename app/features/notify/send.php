<?php
declare(strict_types=1);

/**
 * Sending the outbox (slice 7): outbox_send_batch() is what slice 8's worker calls every minute. Email through MaluMail (from the ticket's queue mailbox, threaded on the ticket's last
 * Message-ID; 2xx sent, a rejected address skipped, 429/5xx/transport retried, the fifth failure failed, no key leaves the row queued and uncounted); texts through the kernel's K6 (202 sent,
 * every refusal a skip with its code — the email row is independent; 401/5xx/unreachable retried). Every send logs notification.send without a body or an address.
 */
require_once __DIR__ . '/body.php';

const OUTBOX_MAX_ATTEMPTS = 5;

function outbox_send_batch(PDO $pdo, int $limit = 100): array
{
    $counts = ['sent' => 0, 'skipped' => 0, 'retried' => 0, 'failed' => 0, 'unconfigured' => 0];
    $st = $pdo->prepare("SELECT * FROM notification_outbox WHERE status = 'queued' AND send_after <= now() ORDER BY id LIMIT " . (int) $limit);
    $st->execute();
    foreach ($st->fetchAll() as $row) {
        [$outcome, $code] = $row['channel'] === 'text' ? send_text_row($pdo, $row) : send_email_row($pdo, $row);
        $counts[$outcome === 'retry' ? 'retried' : $outcome] = ($counts[$outcome === 'retry' ? 'retried' : $outcome] ?? 0) + 1;
        if ($outcome !== 'unconfigured') {
            log_activity($pdo, 'notification.send', 'notification_outbox', (int) $row['id'], ['ticket_id' => $row['ticket_id'] === null ? null : (int) $row['ticket_id'], 'source' => 'cron',
                'after' => ['kind' => $row['kind'], 'channel' => $row['channel'], 'outcome' => $outcome, 'code' => $code]]);
        }
    }
    return $counts;
}

/** The row's recipient address and the ticket facts a send needs. */
function outbox_row_context(PDO $pdo, array $row): array
{
    $to = null;
    $audience = 'member';
    if ($row['member_id'] !== null) {
        $st = $pdo->prepare("SELECT email FROM members WHERE id = :m AND status = 'active'");
        $st->execute(['m' => (int) $row['member_id']]);
        $to = $st->fetchColumn() ?: null;
    } else {
        $st = $pdo->prepare('SELECT primary_email::text FROM requesters WHERE id = :r');
        $st->execute(['r' => (int) $row['requester_id']]);
        $to = $st->fetchColumn() ?: null;
        $audience = 'requester';
    }
    $ticket = ['number' => '', 'subject' => '', 'queue_id' => null, 'ticket_id' => null];
    $thread = [null, []];
    if ($row['ticket_id'] !== null) {
        $t = ticket_facts($pdo, (int) $row['ticket_id']);
        if ($t !== null) {
            $ticket = ['number' => $t['number'], 'subject' => $t['subject'], 'queue_id' => $t['queue_id'], 'ticket_id' => $t['ticket_id']];
            $ids = $pdo->prepare('SELECT email_message_id FROM messages WHERE ticket_id = :t AND email_message_id IS NOT NULL ORDER BY created_at DESC, id DESC LIMIT 8');    // the conversation's own ids, newest first
            $ids->execute(['t' => $t['ticket_id']]);
            $list = $ids->fetchAll(PDO::FETCH_COLUMN);
            $thread = [$list[0] ?? null, array_reverse($list)];
        }
    }
    return [$to, $audience, $ticket, $thread];
}

/** [outcome, code]: sent | skipped | retry | failed | unconfigured. */
function send_email_row(PDO $pdo, array $row): array
{
    $id = (int) $row['id'];
    if ((string) env('MALUMAIL_API_KEY', '') === '') {
        $pdo->prepare("UPDATE notification_outbox SET detail = 'unconfigured' WHERE id = :id")->execute(['id' => $id]);
        return ['unconfigured', 'unconfigured'];
    }
    [$to, $audience, $ticket, [$inReplyTo, $references]] = outbox_row_context($pdo, $row);
    if ($to === null) {
        $pdo->prepare("UPDATE notification_outbox SET status = 'skipped', detail = 'no_address', attempts = attempts + 1 WHERE id = :id")->execute(['id' => $id]);
        return ['skipped', 'no_address'];
    }
    $mailbox = $ticket['queue_id'] !== null ? find_queue_mailbox($pdo, (int) $ticket['queue_id']) : null;
    $from = $mailbox !== null ? (string) $mailbox['address'] : (string) env('MAIL_FROM', 'helpdesk@localhost');
    $fromName = $mailbox !== null ? (string) $mailbox['display_name'] : (string) env('MAIL_FROM_NAME', app_name());
    $body = (string) $row['body'];
    if ($audience === 'requester' && $ticket['ticket_id'] !== null && !preg_match('#/t/[a-f0-9]{48}#', $body)) {        // an external's mail always carries their own live link (an extra one; nothing rotates)
        $body .= "\n\nSee it here: " . app_url('/t/' . mint_extra_secure_link($pdo, (int) $ticket['ticket_id']));
    }
    [$subject, $text, $html] = notify_body((string) $row['kind'], $ticket, ['text' => $body, 'subject' => (string) ($row['subject'] ?? ''), 'signature' => $mailbox['signature'] ?? null], $audience);
    $mail = ['from' => $from, 'from_name' => $fromName, 'to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html, 'reply_to' => $from];
    if ($inReplyTo !== null) {
        $mail['in_reply_to'] = $inReplyTo;
        $mail['references'] = $references;
    }
    try {
        $r = malumail_send($mail);
    } catch (RuntimeException $e) {
        return outbox_retry($pdo, $row, 'transport');
    }
    $status = $r['status'];
    $rejected = (array) ($r['body']['rejected'] ?? []);
    if ($status >= 200 && $status < 300 && $rejected === []) {
        $pdo->prepare("INSERT INTO outbound_email (ticket_id, message_ref_id, to_address, subject, template, provider_message_id, status, sent_at) VALUES (:t, :m, :to, :s, :tpl, :pid, 'sent', now())")
            ->execute(['t' => $ticket['ticket_id'], 'm' => $row['message_ref_id'], 'to' => $to, 's' => $subject, 'tpl' => $row['kind'], 'pid' => $r['message_id']]);
        if ($row['message_ref_id'] !== null && $r['message_id'] !== null) {
            $pdo->prepare('UPDATE messages SET email_message_id = COALESCE(email_message_id, :mid) WHERE id = :m')->execute(['mid' => $r['message_id'], 'm' => (int) $row['message_ref_id']]);
        }
        $pdo->prepare("UPDATE notification_outbox SET status = 'sent', sent_at = now(), attempts = attempts + 1, detail = :d WHERE id = :id")->execute(['d' => $r['message_id'], 'id' => $id]);
        if ($ticket['ticket_id'] !== null) {
            log_activity($pdo, 'mail.outbound', 'ticket', $ticket['ticket_id'], ['ticket_id' => $ticket['ticket_id'], 'queue_id' => $ticket['queue_id'], 'source' => 'cron', 'after' => ['to' => 1, 'template' => $row['kind'], 'provider_message_id' => $r['message_id']]]);
        }
        return ['sent', (string) $status];
    }
    if ($status === 400 || ($status >= 200 && $status < 300 && $rejected !== [])) {
        $why = (string) ($rejected[0]['reason'] ?? ($r['body']['error'] ?? 'undeliverable'));
        $pdo->prepare("INSERT INTO outbound_email (ticket_id, message_ref_id, to_address, subject, template, status, error) VALUES (:t, :m, :to, :s, :tpl, 'suppressed', :e)")
            ->execute(['t' => $ticket['ticket_id'], 'm' => $row['message_ref_id'], 'to' => $to, 's' => $subject, 'tpl' => $row['kind'], 'e' => mb_substr($why, 0, 200)]);
        $pdo->prepare("UPDATE notification_outbox SET status = 'skipped', attempts = attempts + 1, detail = :d WHERE id = :id")->execute(['d' => 'undeliverable:' . mb_substr($why, 0, 80), 'id' => $id]);
        return ['skipped', 'undeliverable'];
    }
    return outbox_retry($pdo, $row, (string) $status);
}

/** One more attempt in a minute; the fifth failure is final. */
function outbox_retry(PDO $pdo, array $row, string $code): array
{
    $attempts = (int) $row['attempts'] + 1;
    if ($attempts >= OUTBOX_MAX_ATTEMPTS) {
        $pdo->prepare("UPDATE notification_outbox SET status = 'failed', attempts = :a, detail = :d WHERE id = :id")->execute(['a' => $attempts, 'd' => 'failed:' . $code, 'id' => (int) $row['id']]);
        return ['failed', $code];
    }
    $pdo->prepare("UPDATE notification_outbox SET attempts = :a, detail = :d, send_after = now() + interval '1 minute' WHERE id = :id")->execute(['a' => $attempts, 'd' => 'retry:' . $code, 'id' => (int) $row['id']]);
    return ['retry', $code];
}

/** A text through the kernel (K6): [outcome, code]. */
function send_text_row(PDO $pdo, array $row): array
{
    $id = (int) $row['id'];
    [, , $ticket] = outbox_row_context($pdo, $row);
    $link = $ticket['ticket_id'] !== null ? app_url('/tickets/' . $ticket['ticket_id']) : app_url('/notifications');
    $head = ($ticket['number'] !== '' ? $ticket['number'] . ' ' : '') . (string) ($row['subject'] ?? notify_kinds()[$row['kind']][2] ?? $row['kind']);
    $text = mb_substr(trim(preg_replace('/\s+/', ' ', $head)), 0, 480 - mb_strlen($link) - 2) . '. ' . $link;
    $url = rtrim((string) env('OS_INTERNAL_URL', ''), '/') . '/api/v1/notify/sms.php';
    $token = (string) env('OS_APPLICATION_TOKEN', '');
    if ($url === '/api/v1/notify/sms.php' || $token === '') {
        $pdo->prepare("UPDATE notification_outbox SET detail = 'unconfigured' WHERE id = :id")->execute(['id' => $id]);
        return ['unconfigured', 'unconfigured'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['member_id' => (int) $row['member_id'], 'text' => $text, 'reference' => $ticket['ticket_id'] !== null ? 'ticket:' . $ticket['ticket_id'] : 'notification:' . $id], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $raw === false) {
        return outbox_retry($pdo, $row, 'unreachable');
    }
    $body = json_decode((string) $raw, true) ?: [];
    if ($status === 202 || $status === 200) {
        $kid = (string) ($body['notification']['id'] ?? '');
        $pdo->prepare("UPDATE notification_outbox SET status = 'sent', sent_at = now(), attempts = attempts + 1, detail = :d WHERE id = :id")->execute(['d' => $kid !== '' ? 'kernel:' . $kid : null, 'id' => $id]);
        return ['sent', (string) $status];
    }
    $code = (string) ($body['error']['code'] ?? ('http_' . $status));
    if ($status === 422 || $status === 503 || $status === 404) {
        $pdo->prepare("UPDATE notification_outbox SET status = 'skipped', attempts = attempts + 1, detail = :d WHERE id = :id")->execute(['d' => $code, 'id' => $id]);
        return ['skipped', $code];
    }
    return outbox_retry($pdo, $row, $code);
}
