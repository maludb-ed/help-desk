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
