<?php
declare(strict_types=1);

/**
 * A person's own settings: how they are told (notification_prefs), their in-app notifications, their access tokens for the
 * two MCP servers (screens `settings`, `notifications`, `tokens`; actions prefs_save, notification_read, token_mint, token_revoke).
 */

/** Every event a person can be told about, with the sentence the settings screen shows (db/013 + db/016 notification kinds). */
const NOTICE_KINDS = [
    'created'          => 'A ticket I asked for is opened',
    'assigned'         => 'A ticket is assigned to me',
    'replied'          => 'A requester replies on a ticket I hold',
    'noted'            => 'A note is added on a ticket I hold',
    'mentioned'        => 'Someone mentions me',
    'cc_added'         => 'I am added as a CC',
    'followed_changed' => 'A ticket I follow changes',
    'new_in_queue'     => 'A new ticket lands in my queue',
    'pending_elapsed'  => 'A pending ticket\'s wait runs out',
    'sla_warning'      => 'A response or resolution is about to be late',
    'sla_breached'     => 'A response or resolution is late',
    'escalated'        => 'A ticket is escalated',
    'rated'            => 'A requester rates a ticket I worked',
    'survey'           => 'A survey answer comes in',
];

/** The person's choices, or the table's defaults when they never saved any. */
function find_prefs(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT email_enabled, text_enabled, kinds, text_kinds, digest FROM notification_prefs WHERE member_id = :m');
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false) {
        $d = $pdo->query("SELECT column_name, column_default FROM information_schema.columns WHERE table_name = 'notification_prefs' AND column_name IN ('kinds', 'text_kinds')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $lit = static fn (string $def): string => preg_match("/'(\{[^']*\})'/", $def, $m) ? $m[1] : '{}';
        return ['email_enabled' => true, 'text_enabled' => false, 'kinds' => pg_text_array($lit((string) ($d['kinds'] ?? ''))),
                'text_kinds' => pg_text_array($lit((string) ($d['text_kinds'] ?? ''))), 'digest' => false, 'saved' => false];
    }
    return ['email_enabled' => (bool) $r['email_enabled'], 'text_enabled' => (bool) $r['text_enabled'], 'kinds' => pg_text_array((string) $r['kinds']),
            'text_kinds' => pg_text_array((string) $r['text_kinds']), 'digest' => (bool) $r['digest'], 'saved' => true];
}

/** Save the choices ($prefs keys present are changed; absent are kept). Answers ['before' => …, 'after' => …] of the changed keys. */
function save_prefs(PDO $pdo, int $memberId, array $prefs): array
{
    $before = find_prefs($pdo, $memberId);
    unset($before['saved']);
    $after = array_intersect_key($prefs, $before) + $before;
    $lit = static fn (array $a): string => '{' . implode(',', array_map(static fn (string $k): string => preg_replace('/[^a-z_]/', '', $k), $a)) . '}';
    $pdo->prepare('INSERT INTO notification_prefs (member_id, email_enabled, text_enabled, kinds, text_kinds, digest) VALUES (:m, :e, :t, CAST(:k AS text[]), CAST(:tk AS text[]), :d)
                   ON CONFLICT (member_id) DO UPDATE SET email_enabled = EXCLUDED.email_enabled, text_enabled = EXCLUDED.text_enabled, kinds = EXCLUDED.kinds,
                                                        text_kinds = EXCLUDED.text_kinds, digest = EXCLUDED.digest, updated_at = now()')
        ->execute(['m' => $memberId, 'e' => $after['email_enabled'] ? 't' : 'f', 't' => $after['text_enabled'] ? 't' : 'f', 'k' => $lit($after['kinds']),
                   'tk' => $lit($after['text_kinds']), 'd' => $after['digest'] ? 't' : 'f']);
    $changed = array_keys(array_filter($after, static fn ($v, string $k): bool => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    return ['before' => array_intersect_key($before, array_flip($changed)), 'after' => array_intersect_key($after, array_flip($changed)), 'prefs' => $after];
}

/**
 * What the kernel last said about this person's texts, from our own outbox (this application never sees a phone number):
 * 'no_verified_phone', 'opted_out', 'no_sender' or null when the latest text was sent (or none was ever tried).
 */
function last_text_refusal(PDO $pdo, int $memberId): ?string
{
    $st = $pdo->prepare("SELECT status, detail FROM notification_outbox WHERE member_id = :m AND channel = 'text' AND status IN ('sent', 'skipped', 'failed') ORDER BY id DESC LIMIT 1");
    $st->execute(['m' => $memberId]);
    $r = $st->fetch();
    if ($r === false || $r['status'] === 'sent') {
        return null;
    }
    foreach (['no_verified_phone', 'opted_out', 'no_sender'] as $code) {
        if (str_contains((string) $r['detail'], $code)) {
            return $code;
        }
    }
    return null;
}

// ---- notifications (the bell; slice 7 writes them) --------------------------------------------------------------
/** The member's own notifications, unread first then newest (mcp_notifications shows only one's own). */
function find_my_notifications(PDO $pdo, int $memberId, bool $unreadOnly): array
{
    $st = $pdo->prepare('SELECT notification_id, kind, ticket_id, title, body, read_at, created_at FROM mcp_notifications'
        . ($unreadOnly ? ' WHERE read_at IS NULL' : '') . ' ORDER BY (read_at IS NULL) DESC, created_at DESC LIMIT 100');
    $st->execute();
    return $st->fetchAll();
}

/** Mark one of the member's notifications read, or (null) every unread one. How many changed. */
function mark_notifications_read(PDO $pdo, int $memberId, ?int $id): int
{
    if ($id === null) {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND read_at IS NULL');
        $st->execute(['m' => $memberId]);
    } else {
        $st = $pdo->prepare('UPDATE notifications SET read_at = now() WHERE member_id = :m AND id = :id AND read_at IS NULL');
        $st->execute(['m' => $memberId, 'id' => $id]);
    }
    return $st->rowCount();
}

// ---- tokens (db/003) ---------------------------------------------------------------------------------------------
function find_my_tokens(PDO $pdo, int $memberId): array
{
    $st = $pdo->prepare('SELECT id, label, scope, created_at, last_used_at, expires_at, revoked_at FROM mcp_access_tokens WHERE member_id = :m ORDER BY revoked_at NULLS FIRST, created_at DESC');
    $st->execute(['m' => $memberId]);
    return $st->fetchAll();
}

function find_my_token(PDO $pdo, int $memberId, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, label, scope, revoked_at FROM mcp_access_tokens WHERE id = :id AND member_id = :m');
    $st->execute(['id' => $id, 'm' => $memberId]);
    return $st->fetch() ?: null;
}

/** Mint a token: `mcp_` + 48 hex, stored hashed. Returns ['id', 'raw', 'label', 'scope'] — the raw value's only appearance. */
function mint_token(PDO $pdo, int $memberId, string $label, string $scope = 'mcp'): array
{
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    $st = $pdo->prepare('INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, :l, :h, :s) RETURNING id');
    $st->execute(['m' => $memberId, 'l' => $label, 'h' => hash('sha256', $raw), 's' => $scope]);
    return ['id' => (int) $st->fetchColumn(), 'raw' => $raw, 'label' => $label, 'scope' => $scope];
}

/** Revoke one of the member's own live tokens. False when there is none such. */
function revoke_token(PDO $pdo, int $memberId, int $tokenId): bool
{
    $st = $pdo->prepare('UPDATE mcp_access_tokens SET revoked_at = now() WHERE id = :id AND member_id = :m AND revoked_at IS NULL RETURNING id');
    $st->execute(['id' => $tokenId, 'm' => $memberId]);
    return $st->fetchColumn() !== false;
}

// ---- the business's settings (slice 1: general-settings / settings_save) --------------------------------------------
const SETTINGS_FIELDS = ['ticket_prefix', 'auto_close_days', 'reopen_window_days', 'secure_link_days', 'default_queue_id', 'request_form_enabled', 'org_visibility_default', 'timezone'];

function find_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT s.ticket_prefix, s.auto_close_days, s.reopen_window_days, s.secure_link_days, s.default_queue_id, q.name AS default_queue_name, s.request_form_enabled, s.org_visibility_default, s.timezone, s.updated_at
                        FROM app_settings s LEFT JOIN queues q ON q.id = s.default_queue_id WHERE s.id = 1')->fetch();
    foreach (['auto_close_days', 'reopen_window_days', 'secure_link_days', 'default_queue_id'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['request_form_enabled'] = (bool) $r['request_form_enabled'];
    $r['org_visibility_default'] = (bool) $r['org_visibility_default'];
    return $r;
}

/** Save the settings ($fields: any of SETTINGS_FIELDS). Answers ['before' => …, 'after' => …] of the changed keys only. */
function save_settings(PDO $pdo, array $fields): array
{
    $before = array_intersect_key(find_settings($pdo), array_flip(SETTINGS_FIELDS));
    $after = array_intersect_key($fields, $before) + $before;
    $d = hd_diff($before, $after);
    if ($d['after'] === []) {
        return $d;
    }
    $pdo->prepare('UPDATE app_settings SET ticket_prefix = :p, auto_close_days = :ac, reopen_window_days = :rw, secure_link_days = :sl, default_queue_id = :dq, request_form_enabled = :rf, org_visibility_default = :ov, timezone = :tz WHERE id = 1')
        ->execute(['p' => $after['ticket_prefix'], 'ac' => $after['auto_close_days'], 'rw' => $after['reopen_window_days'], 'sl' => $after['secure_link_days'], 'dq' => $after['default_queue_id'],
                   'rf' => $after['request_form_enabled'] ? 't' : 'f', 'ov' => $after['org_visibility_default'] ? 't' : 'f', 'tz' => $after['timezone']]);
    return $d;
}

/** The OS agents the mirror admits here, with their queues and their last dispatch (mcp_agent_dispatches). */
function find_agents_here(PDO $pdo): array
{
    $rows = $pdo->query("SELECT member_id, display_name, job_title, roles FROM mcp_members WHERE member_kind = 'agent' AND capability IS NOT NULL ORDER BY display_name")->fetchAll();
    foreach ($rows as &$a) {
        $a['member_id'] = (int) $a['member_id'];
        $a['roles'] = pg_text_array((string) $a['roles']);
        $q = $pdo->prepare("SELECT q.queue_id, q.name, m.is_lead FROM mcp_queue_members m JOIN mcp_queues q ON q.queue_id = m.queue_id WHERE m.member_id = :m ORDER BY q.name");
        $q->execute(['m' => $a['member_id']]);
        $a['queues'] = $q->fetchAll();
        $d = $pdo->prepare('SELECT dispatch_id, ticket_id, kind, via, status, created_at, answered_at FROM mcp_agent_dispatches WHERE agent_member_id = :m ORDER BY created_at DESC LIMIT 1');
        $d->execute(['m' => $a['member_id']]);
        $a['last_dispatch'] = $d->fetch() ?: null;
    }
    unset($a);
    return $rows;
}
