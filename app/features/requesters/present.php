<?php
declare(strict_types=1);

function present_requester_brief(array $r): array
{
    return ['requester_id' => (int) $r['requester_id'], 'kind' => $r['kind'], 'member_id' => $r['member_id'] === null ? null : (int) $r['member_id'], 'name' => $r['display_name'],
            'email' => $r['email'] ?? null, 'phone' => $r['phone'] ?? null, 'ticket_count' => (int) ($r['ticket_count'] ?? 0), 'last_seen_at' => json_ts($r['last_seen_at'] ?? null)];
}

function present_requester(array $r): array
{
    return present_requester_brief($r) + ['organization' => $r['organization_id'] === null ? null : ['organization_id' => $r['organization_id'], 'name' => $r['organization_name']],
            'locale' => $r['locale'], 'timezone' => $r['timezone'], 'notes' => $r['notes'] ?? null, 'merged_into_id' => $r['merged_into_id'],
            'emails' => array_map(static fn (array $e): array => ['email' => $e['email'], 'verified' => $e['verified_at'] !== null], $r['emails'] ?? []), 'created_at' => json_ts($r['created_at'])];
}
