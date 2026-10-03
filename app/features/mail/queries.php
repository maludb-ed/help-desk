<?php
declare(strict_types=1);

/** Mailboxes and the inbound ledger (slice 7). The secret is a reference into the environment, never a value here. */

const INBOUND_STATUSES = ['received' => 'Received', 'processed' => 'Processed', 'failed' => 'Failed', 'bounce' => 'Bounce', 'auto_reply' => 'Auto-reply', 'ignored' => 'Ignored', 'suppressed' => 'Suppressed'];

function mailbox_cast(array $m): array
{
    foreach (['id', 'queue_id', 'last_uid', 'failures'] as $k) {
        if (array_key_exists($k, $m)) {
            $m[$k] = $m[$k] === null ? null : (int) $m[$k];
        }
    }
    $m['poll_enabled'] = (bool) ($m['poll_enabled'] ?? true);
    $m['active'] = (bool) ($m['active'] ?? true);
    $m['secret_set'] = ($m['webhook_secret_ref'] ?? '') !== '' && (string) env((string) $m['webhook_secret_ref'], '') !== '';
    return $m;
}

const MAILBOX_SELECT = "SELECT m.*, q.name AS queue_name, (SELECT count(*) FROM inbound_email i WHERE i.mailbox_id = m.id AND i.status <> 'processed' AND i.status <> 'ignored' AND i.received_at > now() - interval '7 days') AS failures FROM mailboxes m JOIN queues q ON q.id = m.queue_id";

function find_mailboxes(PDO $pdo, bool $failuresOnly = false): array
{
    $rows = $pdo->query(MAILBOX_SELECT . ' WHERE m.active ORDER BY lower(m.address::text)')->fetchAll();
    $rows = array_map('mailbox_cast', $rows);
    return $failuresOnly ? array_values(array_filter($rows, static fn (array $m): bool => $m['failures'] > 0)) : $rows;
}

function find_mailbox(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(MAILBOX_SELECT . ' WHERE m.id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : mailbox_cast($r);
}

function find_mailbox_by_address(PDO $pdo, string $address): ?array
{
    $st = $pdo->prepare(MAILBOX_SELECT . ' WHERE m.address = :a AND m.active');
    $st->execute(['a' => strtolower(trim($address))]);
    $r = $st->fetch();
    return $r === false ? null : mailbox_cast($r);
}

/** The active mailbox of a queue (the From of its notices), or null. */
function find_queue_mailbox(PDO $pdo, int $queueId): ?array
{
    $st = $pdo->prepare(MAILBOX_SELECT . ' WHERE m.queue_id = :q AND m.active ORDER BY m.id LIMIT 1');
    $st->execute(['q' => $queueId]);
    $r = $st->fetch();
    return $r === false ? null : mailbox_cast($r);
}

function save_mailbox(PDO $pdo, ?int $id, array $f, int $by): int
{
    $row = ['address' => strtolower($f['address']), 'display_name' => $f['display_name'], 'queue_id' => $f['queue_id'], 'ref' => $f['webhook_secret_ref'] === '' ? null : $f['webhook_secret_ref'], 'signature' => $f['signature'] === '' ? null : $f['signature'], 'poll' => $f['poll_enabled'] ? 't' : 'f'];
    if ($id === null) {
        $st = $pdo->prepare("INSERT INTO mailboxes (address, display_name, queue_id, provider, webhook_secret_ref, signature, poll_enabled) VALUES (:address, :display_name, :queue_id, 'malumail', :ref, :signature, :poll) RETURNING id");
        $st->execute($row);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE mailboxes SET address = :address, display_name = :display_name, queue_id = :queue_id, webhook_secret_ref = :ref, signature = :signature, poll_enabled = :poll WHERE id = :id')->execute($row + ['id' => $id]);
    return $id;
}

function archive_mailbox(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('UPDATE mailboxes SET active = false WHERE id = :id')->execute(['id' => $id]);
}

function mailbox_log_state(array $m): array
{
    return ['address' => (string) $m['address'], 'display_name' => $m['display_name'], 'queue_id' => $m['queue_id'], 'webhook_secret_ref' => $m['webhook_secret_ref'], 'has_signature' => ($m['signature'] ?? '') !== '', 'poll_enabled' => $m['poll_enabled'], 'active' => $m['active']];
}

/** The inbound ledger: $filters status (a key, or 'problems' = everything but processed), mailbox. Newest first. Never the body. */
function find_inbound(PDO $pdo, array $filters, int $limit = 50): array
{
    $where = [];
    $args = [];
    $status = (string) ($filters['status'] ?? 'problems');
    if ($status === 'problems') {
        $where[] = "i.status NOT IN ('processed')";
    } elseif (isset(INBOUND_STATUSES[$status])) {
        $where[] = 'i.status = :s';
        $args['s'] = $status;
    }
    if (!empty($filters['mailbox'])) {
        $where[] = 'i.mailbox_id = :m';
        $args['m'] = (int) $filters['mailbox'];
    }
    $st = $pdo->prepare('SELECT i.id, i.mailbox_id, m.address AS mailbox_address, i.provider_uid, i.message_id, i.from_address, i.subject, i.received_at, i.status, i.error, i.ticket_id, t.number AS ticket_number, i.processed_at
                           FROM inbound_email i JOIN mailboxes m ON m.id = i.mailbox_id LEFT JOIN tickets t ON t.id = i.ticket_id' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY i.received_at DESC, i.id DESC LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map(static function (array $r): array {
        foreach (['id', 'mailbox_id', 'ticket_id'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        return $r;
    }, $st->fetchAll());
}

function find_inbound_one(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT i.*, m.address AS mailbox_address FROM inbound_email i JOIN mailboxes m ON m.id = i.mailbox_id WHERE i.id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['id', 'mailbox_id', 'ticket_id', 'message_ref_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    return $r;
}
