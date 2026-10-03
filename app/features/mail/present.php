<?php
declare(strict_types=1);

function present_mailbox(array $m): array
{
    return ['mailbox_id' => $m['id'], 'address' => (string) $m['address'], 'display_name' => $m['display_name'], 'queue' => ['queue_id' => $m['queue_id'], 'name' => $m['queue_name'] ?? null], 'provider' => $m['provider'],
            'webhook_secret_ref' => $m['webhook_secret_ref'], 'secret_set' => $m['secret_set'], 'has_signature' => ($m['signature'] ?? '') !== '', 'poll_enabled' => $m['poll_enabled'], 'last_polled_at' => json_ts($m['last_polled_at'] ?? null),
            'last_uid' => $m['last_uid'], 'failures' => $m['failures'] ?? 0, 'active' => $m['active']];
}

function present_inbound(array $r): array
{
    return ['inbound_id' => $r['id'], 'mailbox_id' => $r['mailbox_id'], 'mailbox' => $r['mailbox_address'] ?? null, 'from' => $r['from_address'], 'subject' => $r['subject'], 'received_at' => json_ts($r['received_at']), 'status' => $r['status'], 'error' => $r['error'],
            'ticket_id' => $r['ticket_id'], 'ticket_number' => $r['ticket_number'] ?? null];
}
