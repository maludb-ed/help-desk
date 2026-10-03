<?php
declare(strict_types=1);

/** The conversation's reads (slice 3): one message, the attachments of a ticket not yet on a message (the composer's uploads). */

function find_message(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT message_id, ticket_id, kind, author_member_id, author_member_name, author_kind, author_requester_id, author_requester_name, body_text, channel, mentions, created_at FROM mcp_messages WHERE message_id = :id');
    $st->execute(['id' => $id]);
    $m = $st->fetch();
    if ($m === false) {
        return null;
    }
    $m['message_id'] = (int) $m['message_id'];
    $m['ticket_id'] = (int) $m['ticket_id'];
    $m['mentions'] = pg_int_array((string) $m['mentions']);
    return $m;
}

/** The ticket's uploads not yet on a message (the composer's pending attachments), the caller's own. */
function pending_attachments(PDO $pdo, int $ticketId, int $memberId): array
{
    $st = $pdo->prepare('SELECT id AS attachment_id, filename, mime_type, byte_size FROM attachments WHERE ticket_id = :t AND message_id IS NULL AND uploaded_by_member_id = :m ORDER BY id');
    $st->execute(['t' => $ticketId, 'm' => $memberId]);
    return array_map(static fn (array $a): array => $a + ['attachment_id' => (int) $a['attachment_id'], 'byte_size' => (int) $a['byte_size']], $st->fetchAll());
}

/** "1.2 MB" */
function bytes_words(int $n): string
{
    if ($n >= 1048576) {
        return round($n / 1048576, 1) . ' MB';
    }
    if ($n >= 1024) {
        return round($n / 1024) . ' KB';
    }
    return $n . ' B';
}
