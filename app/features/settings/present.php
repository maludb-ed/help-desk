<?php
declare(strict_types=1);

/** The JSON shapes of a person's settings — whitelists, never a raw row; never a token's value or hash. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'digest' => (bool) $p['digest'], 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'scope' => $t['scope'], 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null];
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'kind' => $n['kind'], 'ticket_id' => $n['ticket_id'] !== null ? (int) $n['ticket_id'] : null,
            'title' => $n['title'], 'body' => $n['body'], 'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at'])];
}

function present_settings(array $s): array
{
    return ['ticket_prefix' => $s['ticket_prefix'], 'auto_close_days' => $s['auto_close_days'], 'reopen_window_days' => $s['reopen_window_days'], 'secure_link_days' => $s['secure_link_days'],
            'default_queue' => $s['default_queue_id'] === null ? null : ['queue_id' => $s['default_queue_id'], 'name' => $s['default_queue_name']],
            'request_form_enabled' => $s['request_form_enabled'], 'org_visibility_default' => $s['org_visibility_default'], 'timezone' => $s['timezone'], 'updated_at' => json_ts($s['updated_at'])];
}

function present_agent_here(array $a): array
{
    return ['member_id' => $a['member_id'], 'name' => $a['display_name'], 'job_title' => $a['job_title'], 'roles' => $a['roles'],
            'queues' => array_map(static fn (array $q): array => ['queue_id' => (int) $q['queue_id'], 'name' => $q['name'], 'is_lead' => (bool) $q['is_lead']], $a['queues']),
            'last_dispatch' => $a['last_dispatch'] === null ? null : ['dispatch_id' => (int) $a['last_dispatch']['dispatch_id'], 'ticket_id' => (int) $a['last_dispatch']['ticket_id'], 'kind' => $a['last_dispatch']['kind'], 'via' => $a['last_dispatch']['via'],
                'status' => $a['last_dispatch']['status'], 'created_at' => json_ts($a['last_dispatch']['created_at']), 'answered_at' => json_ts($a['last_dispatch']['answered_at'])]];
}
