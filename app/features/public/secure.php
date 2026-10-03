<?php
declare(strict_types=1);

/**
 * The secure ticket page (slice 6): the mailed link is the only authority. hd_secure_link_ticket() turns a raw token into a ticket id (format, hash, not rotated, not expired; stamps last_used_at);
 * everything shown is the whitelist present_secure_ticket() reads from the base tables — never a note, a CC, another requester, an assignee's surname, SLA, tags, custom fields.
 */
require_once __DIR__ . '/queries.php';

const SECURE_STATUS_WORDS = ['new' => 'We are on it', 'open' => 'We are on it', 'pending' => 'Waiting for you', 'on_hold' => 'On hold', 'solved' => 'Solved — does this resolve it?', 'closed' => 'Closed'];

function secure_status_words(string $category): string
{
    return SECURE_STATUS_WORDS[$category] ?? 'We are on it';
}

/** The ticket a raw token opens, or null (malformed, unknown, rotated, expired alike). */
function secure_ticket_open(PDO $pdo, string $token): ?int
{
    if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
        return null;
    }
    $st = $pdo->prepare('SELECT hd_secure_link_ticket(:t)');
    $st->execute(['t' => $token]);
    $id = $st->fetchColumn();
    return $id === null || $id === false ? null : (int) $id;
}

/** One more live link for a ticket (the survey's; earlier links keep working — hd_secure_link_mint() would rotate them). Answers the raw token. */
function mint_extra_secure_link(PDO $pdo, int $ticketId): string
{
    $raw = bin2hex(random_bytes(24));
    $days = public_settings($pdo)['secure_link_days'];
    $pdo->prepare("INSERT INTO ticket_links_secure (ticket_id, token_hash, expires_at) VALUES (:t, :h, now() + make_interval(days => :d))")->execute(['t' => $ticketId, 'h' => hash('sha256', $raw), 'd' => $days]);
    return $raw;
}

/** The whitelist: number, subject, status words, the organization's name, the public conversation with first names, the attachments on public messages, the window and the survey facts. */
function present_secure_ticket(PDO $pdo, int $ticketId): ?array
{
    $st = $pdo->prepare('SELECT t.id AS ticket_id, t.number, t.subject, t.queue_id, t.requester_id, t.assignee_member_id, t.solved_at, t.closed_at, t.last_agent_reply_at, s.category AS status_category, s.label AS status_label,
                                o.name AS organization_name, r.display_name AS requester_name
                           FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN requesters r ON r.id = t.requester_id LEFT JOIN organizations o ON o.id = t.organization_id WHERE t.id = :id');
    $st->execute(['id' => $ticketId]);
    $t = $st->fetch();
    if ($t === false) {
        return null;
    }
    $m = $pdo->prepare("SELECT m.id AS message_id, m.body_text, m.created_at, m.author_requester_id, m.author_member_id, mm.display_name AS member_name, mm.member_kind, rr.display_name AS requester_name
                          FROM messages m LEFT JOIN members mm ON mm.id = m.author_member_id LEFT JOIN requesters rr ON rr.id = m.author_requester_id
                         WHERE m.ticket_id = :t AND m.kind = 'public_reply' ORDER BY m.created_at, m.id");
    $m->execute(['t' => $ticketId]);
    $messages = [];
    foreach ($m->fetchAll() as $row) {
        $a = $pdo->prepare('SELECT id AS attachment_id, filename, byte_size FROM attachments WHERE message_id = :m ORDER BY id');
        $a->execute(['m' => (int) $row['message_id']]);
        $mine = $row['author_requester_id'] !== null && (int) $row['author_requester_id'] === (int) $t['requester_id'];
        $messages[] = ['message_id' => (int) $row['message_id'], 'body' => (string) $row['body_text'], 'at' => (string) $row['created_at'], 'mine' => $mine,
                       'author' => $mine ? 'You' : public_author_words($row['member_name'], $row['member_kind'], $row['requester_name']), 'attachments' => array_map(static fn (array $x): array => ['attachment_id' => (int) $x['attachment_id'], 'filename' => $x['filename'], 'byte_size' => (int) $x['byte_size']], $a->fetchAll())];
    }
    $s = public_settings($pdo);
    $cs = find_csat_settings($pdo);
    $surveyed = $cs['enabled'] && ($cs['queue_ids'] === [] || in_array((int) $t['queue_id'], $cs['queue_ids'], true));
    $withinWindow = $t['solved_at'] !== null && strtotime((string) $t['solved_at']) >= time() - $s['reopen_window_days'] * 86400;
    return ['ticket_id' => (int) $t['ticket_id'], 'number' => $t['number'], 'subject' => $t['subject'], 'queue_id' => (int) $t['queue_id'], 'requester_id' => (int) $t['requester_id'], 'requester_name' => $t['requester_name'], 'assignee_member_id' => $t['assignee_member_id'] === null ? null : (int) $t['assignee_member_id'],
            'status_category' => $t['status_category'], 'status_words' => secure_status_words((string) $t['status_category']), 'organization_name' => $t['organization_name'], 'messages' => $messages,
            'solved_at' => $t['solved_at'], 'closed_at' => $t['closed_at'], 'last_agent_reply_at' => $t['last_agent_reply_at'], 'reopen_window_days' => $s['reopen_window_days'], 'within_window' => $withinWindow, 'surveyed' => $surveyed, 'csat' => $cs, 'timezone' => $s['timezone']];
}

/** What the page offers: reply (not closed), confirm (pending/open/on hold after a reply from us), reopen (solved within the window), rate (solved or closed, surveyed). */
function secure_may(array $T): array
{
    $cat = $T['status_category'];
    return ['reply' => $cat !== 'closed', 'confirm' => in_array($cat, ['pending', 'open', 'on_hold'], true) && $T['last_agent_reply_at'] !== null, 'reopen' => $cat === 'solved' && $T['within_window'],
            'rate' => in_array($cat, ['solved', 'closed'], true) && $T['surveyed']];
}

/** More than 30 writes an hour through one ticket's links → true (the activity trail counts them). */
function secure_abuse_guard(PDO $pdo, int $ticketId): bool
{
    $st = $pdo->prepare("SELECT count(*) FROM activity_log WHERE ticket_id = :t AND source = 'portal' AND action IN ('ticket.reply', 'ticket.confirm', 'ticket.reopen', 'rating.submit') AND occurred_at > now() - interval '1 hour'");
    $st->execute(['t' => $ticketId]);
    return (int) $st->fetchColumn() >= 30;
}

/** An attachment on a public message of this ticket: [path, filename, mime, size] or null. */
function secure_attachment(PDO $pdo, int $ticketId, int $attachmentId): ?array
{
    $st = $pdo->prepare("SELECT a.filename, a.mime_type, a.byte_size, a.storage_path FROM attachments a JOIN messages m ON m.id = a.message_id WHERE a.id = :a AND a.ticket_id = :t AND m.ticket_id = :t AND m.kind = 'public_reply'");
    $st->execute(['a' => $attachmentId, 't' => $ticketId]);
    $r = $st->fetch();
    return $r === false ? null : ['path' => APP_ROOT . '/storage/' . $r['storage_path'], 'filename' => $r['filename'], 'mime' => $r['mime_type'], 'size' => (int) $r['byte_size']];
}

/** The dead-link page: one page, 404, the same bytes whatever went wrong. */
function secure_dead_link(PDO $pdo): never
{
    render_public('This link no longer works', view('public/dead-link.php', ['address' => public_queue_address($pdo, null)]), 'dead-link', 404);
}

/** The start of every public POST on a ticket: the method, the CSRF of the anonymous session, the token, the guard. Answers [ticket id, the presented ticket, the raw token]. */
function secure_post_begin(PDO $pdo): array
{
    require_post();
    verify_csrf();
    $token = (string) ($_POST['token'] ?? ($_GET['token'] ?? ''));
    $tid = secure_ticket_open($pdo, $token) ?? secure_dead_link($pdo);
    $GLOBALS['__public_door'] = 'portal';
    if (secure_abuse_guard($pdo, $tid)) {
        render_public('Too many requests', view('shared/message.php', ['title' => 'Too many requests', 'message' => 'Too many requests — try again later.']), 'secure-ticket', 429);
    }
    $T = present_secure_ticket($pdo, $tid) ?? secure_dead_link($pdo);
    return [$tid, $T, $token];
}

/** Back to the page with a notice. */
function secure_back(string $token, string $notice): never
{
    header('Location: /t/' . $token . '?notice=' . rawurlencode($notice), true, 302);
    exit;
}
