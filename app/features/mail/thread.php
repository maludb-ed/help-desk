<?php
declare(strict_types=1);

/** Threading (slice 7): In-Reply-To, then each References id, against our messages' Message-IDs and our outbound ones; else the subject's [HD-1042] tag. */
require_once __DIR__ . '/parse.php';

function mail_thread_ticket(PDO $pdo, array $headers, string $subject): ?int
{
    $h = array_change_key_case($headers, CASE_LOWER);
    $ids = [];
    $irt = mail_norm_id((string) ($h['in-reply-to'] ?? ($h['in_reply_to'] ?? '')));
    if ($irt !== '') {
        $ids[] = $irt;
    }
    $refs = $h['references'] ?? [];
    $refs = is_array($refs) ? $refs : preg_split('/\s+/', trim((string) $refs));
    foreach (array_reverse($refs ?: []) as $r) {
        $r = mail_norm_id((string) $r);
        if ($r !== '' && !in_array($r, $ids, true)) {
            $ids[] = $r;
        }
    }
    foreach ($ids as $id) {
        $st = $pdo->prepare('SELECT ticket_id FROM messages WHERE email_message_id = :id ORDER BY id DESC LIMIT 1');
        $st->execute(['id' => $id]);
        $t = $st->fetchColumn();
        if ($t !== false && $t !== null) {
            return (int) $t;
        }
        $st = $pdo->prepare('SELECT ticket_id FROM outbound_email WHERE provider_message_id = :id AND ticket_id IS NOT NULL ORDER BY id DESC LIMIT 1');
        $st->execute(['id' => $id]);
        $t = $st->fetchColumn();
        if ($t !== false && $t !== null) {
            return (int) $t;
        }
        $st = $pdo->prepare('SELECT ticket_id FROM inbound_email WHERE message_id = :id AND ticket_id IS NOT NULL ORDER BY id DESC LIMIT 1');
        $st->execute(['id' => $id]);
        $t = $st->fetchColumn();
        if ($t !== false && $t !== null) {
            return (int) $t;
        }
    }
    $num = mail_subject_ticket_number($subject);
    if ($num !== null) {
        $st = $pdo->prepare('SELECT id FROM tickets WHERE number = :n');
        $st->execute(['n' => $num]);
        $t = $st->fetchColumn();
        if ($t !== false) {
            return (int) $t;
        }
    }
    return null;
}
