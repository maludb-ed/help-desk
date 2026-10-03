<?php
declare(strict_types=1);

/**
 * Inbound mail (slice 7): one transaction per message, idempotent on (mailbox, uid). The message is read through MaluMail's mailbox MCP; the row is kept raw before anything is decided;
 * an auto-reply or a bounce makes no ticket; a thread lands on its ticket as the right person's public reply (or a note from support); a stranger becomes a CC; a closed ticket gets a
 * follow-up; no thread makes a new ticket in the mailbox's queue with its confirmation notice. The poll runs the same function for what the webhook missed.
 */
require_once __DIR__ . '/parse.php';
require_once __DIR__ . '/thread.php';
require_once __DIR__ . '/queries.php';

/** A sender whose mail bounced twice in 7 days, or whose ticket was marked spam, is not heard. */
function mail_sender_suppressed(PDO $pdo, string $address): bool
{
    $st = $pdo->prepare("SELECT count(*) FROM outbound_email WHERE to_address = :a AND status = 'failed' AND created_at > now() - interval '7 days'");
    $st->execute(['a' => $address]);
    if ((int) $st->fetchColumn() >= 2) {
        return true;
    }
    $st = $pdo->prepare("SELECT 1 FROM tickets t JOIN requesters r ON r.id = t.requester_id WHERE r.primary_email = :a AND t.resolution_code = 'spam' LIMIT 1");
    $st->execute(['a' => $address]);
    return $st->fetchColumn() !== false;
}

/** The inbound row of a message the stub or MaluMail gave us: inserted (or found) by (mailbox, uid). */
function mail_record_inbound(PDO $pdo, array $mailbox, int $uid, array $msg): int
{
    $h = array_change_key_case((array) ($msg['headers'] ?? []), CASE_LOWER);
    $from = (string) ($h['from'] ?? '');
    $refs = $h['references'] ?? [];
    $refs = is_array($refs) ? $refs : preg_split('/\s+/', trim((string) $refs));
    $st = $pdo->prepare('INSERT INTO inbound_email (mailbox_id, provider_uid, message_id, in_reply_to, references_ids, from_address, from_name, to_addresses, cc_addresses, subject, body_text, body_html, headers)
                         VALUES (:m, :uid, :mid, :irt, CAST(:refs AS text[]), :fa, :fn, CAST(:to AS text[]), CAST(:cc AS text[]), :s, :bt, :bh, CAST(:h AS jsonb))
                         ON CONFLICT (mailbox_id, provider_uid) WHERE provider_uid IS NOT NULL DO UPDATE SET headers = EXCLUDED.headers RETURNING id');
    $st->execute(['m' => $mailbox['id'], 'uid' => (string) $uid, 'mid' => mail_norm_id((string) ($h['message_id'] ?? ($h['message-id'] ?? ''))) ?: null, 'irt' => mail_norm_id((string) ($h['in_reply_to'] ?? ($h['in-reply-to'] ?? ''))) ?: null,
                  'refs' => pg_array_literal(array_values(array_filter(array_map(static fn ($r): string => mail_norm_id((string) $r), $refs ?: [])))), 'fa' => mail_address_of($from) ?: null, 'fn' => mail_name_of($from) ?: null,
                  'to' => pg_array_literal(mail_addresses_of($h['to'] ?? '')), 'cc' => pg_array_literal(mail_addresses_of($h['cc'] ?? '')), 's' => (string) ($h['subject'] ?? ''),
                  'bt' => (string) ($msg['text'] ?? ''), 'bh' => isset($msg['html']) ? mb_substr(strip_tags((string) $msg['html'], '<p><br><b><i><strong><em><ul><ol><li><a><blockquote>'), 0, 200000) : null, 'h' => json_encode((object) $h, JSON_UNESCAPED_UNICODE)]);
    return (int) $st->fetchColumn();
}

/** Store a mail's attachments (base64 content from the MCP) on a message, within the limit and the allow-list; silently skips the rest. */
function mail_store_attachments(PDO $pdo, int $ticketId, int $messageId, array $attachments, ?int $requesterId): int
{
    $n = 0;
    foreach ($attachments as $a) {
        $content = isset($a['content_base64']) ? base64_decode((string) $a['content_base64'], true) : null;
        if ($content === null || $content === false || strlen($content) > attachment_max_bytes()) {
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'hdmail');
        file_put_contents($tmp, $content);
        try {
            store_attachment($pdo, $ticketId, $messageId, ['name' => (string) ($a['name'] ?? ($a['filename'] ?? 'attachment')), 'type' => (string) ($a['content_type'] ?? ''), 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content)], null, $requesterId);
            $n++;
        } catch (DomainException) {
            @unlink($tmp);
        }
    }
    return $n;
}

/**
 * Process one message of a mailbox: [status, ticket_id, message_id]. Idempotent: a row already processed, ignored, bounced, auto-replied or suppressed is answered as it is.
 * A failure leaves the row `failed` with the error and the message unseen; the retry runs this again.
 */
function mail_inbound_uid(PDO $pdo, array $mailbox, int $uid): array
{
    $st = $pdo->prepare('SELECT id, status, ticket_id, message_ref_id FROM inbound_email WHERE mailbox_id = :m AND provider_uid = :u');
    $st->execute(['m' => $mailbox['id'], 'u' => (string) $uid]);
    $existing = $st->fetch();
    if ($existing !== false && !in_array($existing['status'], ['received', 'failed'], true)) {
        return [$existing['status'], $existing['ticket_id'] === null ? null : (int) $existing['ticket_id'], $existing['message_ref_id'] === null ? null : (int) $existing['message_ref_id']];
    }
    $source = $GLOBALS['__public_door'] ?? 'cron';
    $rowId = $existing === false ? null : (int) $existing['id'];
    try {
        $msg = malumail_mcp_call('read_message', ['address' => (string) $mailbox['address'], 'uid' => $uid, 'mark_seen' => false]);
    } catch (Throwable $e) {
        if ($rowId === null) {
            $pdo->prepare("INSERT INTO inbound_email (mailbox_id, provider_uid, status, error) VALUES (:m, :u, 'failed', :e) ON CONFLICT (mailbox_id, provider_uid) WHERE provider_uid IS NOT NULL DO UPDATE SET status = 'failed', error = EXCLUDED.error")->execute(['m' => $mailbox['id'], 'u' => (string) $uid, 'e' => mb_substr($e->getMessage(), 0, 500)]);
        } else {
            $pdo->prepare("UPDATE inbound_email SET status = 'failed', error = :e WHERE id = :id")->execute(['e' => mb_substr($e->getMessage(), 0, 500), 'id' => $rowId]);
        }
        $rid = $rowId ?? (int) $pdo->query('SELECT id FROM inbound_email WHERE mailbox_id = ' . (int) $mailbox['id'] . " AND provider_uid = '" . (int) $uid . "'")->fetchColumn();
        log_activity($pdo, 'mail.inbound', 'inbound_email', $rid, ['queue_id' => $mailbox['queue_id'], 'source' => $source, 'after' => ['mailbox_id' => $mailbox['id'], 'outcome' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 200)]]);
        return ['failed', null, null];
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(:k))')->execute(['k' => 'mail-inbound:' . $mailbox['id']]);
        $rowId = mail_record_inbound($pdo, $mailbox, $uid, $msg);
        $h = array_change_key_case((array) ($msg['headers'] ?? []), CASE_LOWER);
        $messageId = mail_norm_id((string) ($h['message_id'] ?? ($h['message-id'] ?? '')));
        $from = mail_address_of((string) ($h['from'] ?? ''));
        $fromName = mail_name_of((string) ($h['from'] ?? ''));
        $subject = mail_clean_subject((string) ($h['subject'] ?? ''));
        $finish = function (string $status, ?int $ticketId, ?int $msgRef, ?string $error = null) use ($pdo, $rowId, $mailbox, $uid, $source, $messageId): array {
            $pdo->prepare('UPDATE inbound_email SET status = :s, ticket_id = :t, message_ref_id = :m, error = :e, processed_at = now() WHERE id = :id')->execute(['s' => $status, 't' => $ticketId, 'm' => $msgRef, 'e' => $error, 'id' => $rowId]);
            $queue = $ticketId !== null ? (int) ($pdo->query('SELECT queue_id FROM tickets WHERE id = ' . $ticketId)->fetchColumn() ?: $mailbox['queue_id']) : $mailbox['queue_id'];
            log_activity($pdo, 'mail.inbound', 'inbound_email', $rowId, ['ticket_id' => $ticketId, 'queue_id' => $queue, 'source' => $source, 'after' => ['mailbox_id' => $mailbox['id'], 'message_id' => $messageId ?: null, 'outcome' => $status, 'ticket_id' => $ticketId, 'uid' => $uid]]);
            $pdo->commit();
            try {
                malumail_mcp_call('mark_message', ['address' => (string) $mailbox['address'], 'uid' => $uid, 'seen' => true]);
            } catch (Throwable) {
            }
            return [$status, $ticketId, $msgRef];
        };
        $bounced = mail_is_bounce($h, (array) ($msg['parts'] ?? []));
        if ($bounced !== null) {
            if ($bounced !== '') {
                $pdo->prepare("UPDATE outbound_email SET status = 'failed', error = 'bounced' WHERE provider_message_id = :id")->execute(['id' => $bounced]);
            }
            return $finish('bounce', null, null, $bounced !== '' ? 'bounced ' . $bounced : 'delivery report');
        }
        if (mail_is_auto($h)) {
            return $finish('auto_reply', null, null);
        }
        if ($from === '' || $from === strtolower((string) $mailbox['address'])) {
            return $finish('ignored', null, null, $from === '' ? 'no sender' : 'our own address');
        }
        if (mail_sender_suppressed($pdo, $from)) {
            return $finish('suppressed', null, null, 'sender suppressed');
        }
        $text = mail_strip_quoted((string) ($msg['text'] ?? ''));
        if ($text === '') {
            $text = '(an email with no text)';
        }
        $attachments = (array) ($msg['attachments'] ?? []);
        $ticketId = mail_thread_ticket($pdo, $h, (string) ($h['subject'] ?? ''));
        if ($ticketId !== null) {
            $t = ticket_facts($pdo, $ticketId);
            if ($t === null) {
                $ticketId = null;
            } elseif ($t['status_category'] === 'closed') {
                $rid = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e, :n)', ['e' => $from, 'n' => $fromName ?: null]);
                $made = create_ticket($pdo, ['subject' => $t['subject'], 'requester_id' => $t['requester_id'], 'submitter_member_id' => null, 'queue_id' => $t['queue_id'], 'priority' => 'normal', 'type_id' => null, 'category_id' => null,
                    'channel' => 'email', 'due_at' => null, 'external_id' => null, 'body' => $text, 'assign' => true], null);
                $new = $made['id'];
                $mid = (int) $pdo->query('SELECT min(id) FROM messages WHERE ticket_id = ' . $new)->fetchColumn();
                $pdo->prepare('UPDATE messages SET email_message_id = :m, in_reply_to = :i, channel = :c WHERE id = :id')->execute(['m' => $messageId ?: null, 'i' => mail_norm_id((string) ($h['in_reply_to'] ?? ($h['in-reply-to'] ?? ''))) ?: null, 'c' => 'email', 'id' => $mid]);
                mail_store_attachments($pdo, $new, $mid, $attachments, $t['requester_id']);
                $linkId = link_tickets($pdo, $new, $ticketId, 'follow_up_of', null);
                $nt = ticket_facts($pdo, $new);
                ticket_log($pdo, $nt, 'ticket.create', ['source' => $source, 'actor_requester_id' => $t['requester_id'], 'after' => ['number' => $made['number'], 'channel' => 'email', 'requester_id' => $t['requester_id'], 'queue_id' => $t['queue_id'], 'follow_up_of' => $ticketId]]);
                ticket_log($pdo, $nt, 'ticket.link', ['source' => $source, 'after' => ['link_id' => $linkId, 'kind' => 'follow_up_of', 'other_ticket_id' => $ticketId]]);
                notify_requester($pdo, $t['requester_id'], 'created', $new, 'We have your request ' . $made['number'], 'Thank you — ' . $t['number'] . ' was closed, so we opened ' . $made['number'] . " for this.\n\n" . requester_link_words($nt, $made['raw_link']), 'created:' . $new, $mid);
                return $finish('processed', $new, $mid);
            } else {
                $rid = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e, :n)', ['e' => $from, 'n' => $fromName ?: null]);
                $isRequester = $rid === $t['requester_id'];
                $isCc = (bool) one_value($pdo, "SELECT 1 FROM ticket_followers WHERE ticket_id = :t AND kind = 'cc' AND requester_id = :r", ['t' => $ticketId, 'r' => $rid]);
                $memberId = one_value($pdo, "SELECT m.id FROM members m WHERE m.email = :e AND m.status = 'active' AND m.capability IS NOT NULL", ['e' => $from]);
                $memberId = $memberId === false || $memberId === null ? null : (int) $memberId;
                $worker = $memberId !== null && member_in_queue($pdo, $memberId, $t['queue_id']) && !$isRequester;
                $opts = ['channel' => 'email', 'email_message_id' => $messageId ?: null, 'in_reply_to' => mail_norm_id((string) ($h['in_reply_to'] ?? ($h['in-reply-to'] ?? ''))) ?: null];
                if ($worker) {
                    $isNote = preg_match('/^\s*#note\b/i', $text) === 1;
                    $body = $isNote ? trim((string) preg_replace('/^\s*#note\b[ \t]*/i', '', $text)) : $text;
                    $mid = add_message($pdo, $ticketId, $isNote ? 'internal_note' : 'public_reply', $body !== '' ? $body : '(an email with no text)', $opts + ['author_member_id' => $memberId]);
                    mail_store_attachments($pdo, $ticketId, $mid, $attachments, null);
                    ticket_log($pdo, $t, $isNote ? 'ticket.note' : 'ticket.reply', ['source' => $source, 'actor_member_id' => $memberId, 'after' => ['message_id' => $mid, 'kind' => $isNote ? 'internal_note' : 'public_reply', 'excerpt' => excerpt($body), 'attachments' => count($attachments), 'channel' => 'email']]);
                    if (!$isNote) {
                        foreach (reply_recipients($pdo, $t) as $rc) {
                            notify($pdo, $rc, 'replied', $ticketId, 'Re: ' . $t['number'] . ' ' . $t['subject'], $body . "\n\n" . requester_link_words($t, null), $mid, 'replied:' . $mid);
                        }
                    }
                    return $finish('processed', $ticketId, $mid);
                }
                if (!$isRequester && !$isCc) {
                    add_follower($pdo, $ticketId, 'cc', $memberId, $memberId === null ? $rid : null, null);
                    ticket_log($pdo, $t, 'ticket.cc_add', ['source' => $source, 'actor_requester_id' => $rid, 'after' => ['requester_id' => $rid, 'via' => 'email']]);
                }
                $mid = add_message($pdo, $ticketId, 'public_reply', $text, $opts + ($memberId !== null && $isRequester ? ['author_member_id' => $memberId] : ['author_requester_id' => $rid]));
                mail_store_attachments($pdo, $ticketId, $mid, $attachments, $rid);
                ticket_log($pdo, $t, 'ticket.reply', ['source' => $source, 'actor_requester_id' => $rid, 'after' => ['message_id' => $mid, 'kind' => 'public_reply', 'excerpt' => excerpt($text), 'attachments' => count($attachments), 'channel' => 'email']]);
                $recipients = $t['assignee_member_id'] !== null ? [['member_id' => $t['assignee_member_id']]] : array_map(static fn (int $m): array => ['member_id' => $m], queue_lead_ids($pdo, $t['queue_id']));
                foreach ($recipients as $rc) {
                    notify($pdo, $rc, 'replied', $ticketId, 'Re: ' . $t['number'] . ' ' . $t['subject'], $text . "\n\nSee it here: " . app_url('/tickets/' . $ticketId), $mid, 'replied:' . $mid);
                }
                return $finish('processed', $ticketId, $mid);
            }
        }
        // no thread: a new ticket in the mailbox's queue
        $rid = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e, :n)', ['e' => $from, 'n' => $fromName ?: null]);
        $ccIds = [];
        foreach (mail_addresses_of($h['cc'] ?? '') as $cc) {
            if ($cc !== $from && $cc !== strtolower((string) $mailbox['address'])) {
                $ccIds[] = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e)', ['e' => $cc]);
            }
        }
        $made = create_ticket($pdo, ['subject' => $subject !== '' ? $subject : '(no subject)', 'requester_id' => $rid, 'submitter_member_id' => null, 'queue_id' => $mailbox['queue_id'], 'priority' => 'normal', 'type_id' => null, 'category_id' => null,
            'channel' => 'email', 'due_at' => null, 'external_id' => null, 'body' => $text, 'cc_requester_ids' => $ccIds, 'assign' => true], null);
        $new = $made['id'];
        $mid = (int) $pdo->query('SELECT min(id) FROM messages WHERE ticket_id = ' . $new)->fetchColumn();
        $pdo->prepare('UPDATE messages SET email_message_id = :m, channel = :c WHERE id = :id')->execute(['m' => $messageId ?: null, 'c' => 'email', 'id' => $mid]);
        mail_store_attachments($pdo, $new, $mid, $attachments, $rid);
        $nt = ticket_facts($pdo, $new);
        ticket_log($pdo, $nt, 'ticket.create', ['source' => $source, 'actor_requester_id' => $rid, 'after' => ['number' => $made['number'], 'channel' => 'email', 'requester_id' => $rid, 'queue_id' => $mailbox['queue_id'], 'priority' => 'normal', 'ccs' => count($ccIds), 'attachments' => count($attachments)]]);
        notify_requester($pdo, $rid, 'created', $new, 'We have your request ' . $made['number'], 'Thank you — we have your request ' . $made['number'] . ".\n\n" . requester_link_words($nt, $made['raw_link']) . "\n\nReply to this email to add to it.", 'created:' . $new, $mid);
        foreach ($ccIds as $cid) {
            notify_requester($pdo, $cid, 'cc_added', $new, 'You were copied on ' . $made['number'], 'You are copied on the request ' . $made['number'] . ' "' . $nt['subject'] . '".', 'cc_added:' . $new . ':' . $cid, null);
        }
        if ($made['assignee_member_id'] !== null && $made['assignee_kind'] === 'agent') {
            $GLOBALS['__dispatch_after'][] = [$new, $made['assignee_member_id']];
        }
        return $finish('processed', $new, $mid);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->prepare("INSERT INTO inbound_email (mailbox_id, provider_uid, status, error) VALUES (:m, :u, 'failed', :e) ON CONFLICT (mailbox_id, provider_uid) WHERE provider_uid IS NOT NULL DO UPDATE SET status = 'failed', error = EXCLUDED.error")->execute(['m' => $mailbox['id'], 'u' => (string) $uid, 'e' => mb_substr($e->getMessage(), 0, 500)]);
        $rid = (int) $pdo->query('SELECT id FROM inbound_email WHERE mailbox_id = ' . (int) $mailbox['id'] . " AND provider_uid = '" . (int) $uid . "'")->fetchColumn();
        log_activity($pdo, 'mail.inbound', 'inbound_email', $rid, ['queue_id' => $mailbox['queue_id'], 'source' => $source, 'after' => ['mailbox_id' => $mailbox['id'], 'outcome' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 200)]]);
        return ['failed', null, null];
    }
}

/** The poll: the unseen messages since last_uid, each through mail_inbound_uid(); last_uid advanced only past what was handled; last_polled_at stamped. Answers how many were handled. */
function mail_poll_mailbox(PDO $pdo, array $mailbox, int $limit = 50): int
{
    $list = malumail_mcp_call('list_messages', ['address' => (string) $mailbox['address'], 'unread_only' => true, 'limit' => $limit]);
    $handled = 0;
    $max = (int) ($mailbox['last_uid'] ?? 0);
    $blocked = false;
    $uids = array_map(static fn ($m): int => (int) ($m['uid'] ?? 0), (array) ($list['messages'] ?? []));
    sort($uids);
    foreach ($uids as $uid) {
        if ($uid <= 0 || $uid <= (int) ($mailbox['last_uid'] ?? 0)) {
            continue;
        }
        [$status] = mail_inbound_uid($pdo, $mailbox, $uid);
        if ($status === 'failed') {
            $blocked = true;                                             // the others still go; last_uid stays below the failure (a retry or the next poll sees it again)
            continue;
        }
        $handled++;
        if (!$blocked) {
            $max = max($max, $uid);
        }
    }
    $pdo->prepare('UPDATE mailboxes SET last_polled_at = now(), last_uid = :u WHERE id = :id')->execute(['u' => $max > 0 ? $max : null, 'id' => $mailbox['id']]);     // the floor: a failed uid stays above it until a retry or the next poll handles it
    foreach ($GLOBALS['__dispatch_after'] ?? [] as [$ticketId, $agentId]) {
        dispatch_to_agent($pdo, $ticketId, $agentId, 'assigned', null);
    }
    $GLOBALS['__dispatch_after'] = [];
    return $handled;
}
