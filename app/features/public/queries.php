<?php
declare(strict_types=1);

/** The public doors (slice 6): what the form and the secure page share — the settings, the bare layout, the queue to write to. Nobody is signed in here: the base tables, never the mcp views. */

/** app_settings, cast. */
function public_settings(PDO $pdo): array
{
    $r = $pdo->query('SELECT request_form_enabled, reopen_window_days, secure_link_days, default_queue_id, form_limit_per_email, form_limit_per_ip, timezone FROM app_settings WHERE id = 1')->fetch() ?: [];
    return ['request_form_enabled' => (bool) ($r['request_form_enabled'] ?? false), 'reopen_window_days' => (int) ($r['reopen_window_days'] ?? 14), 'secure_link_days' => (int) ($r['secure_link_days'] ?? 90),
            'default_queue_id' => isset($r['default_queue_id']) ? (int) $r['default_queue_id'] : null, 'form_limit_per_email' => (int) ($r['form_limit_per_email'] ?? 5), 'form_limit_per_ip' => (int) ($r['form_limit_per_ip'] ?? 20), 'timezone' => (string) ($r['timezone'] ?? 'UTC')];
}

/** The queue a request lands in when nothing routes it: the settings' default, else the general queue, else the first. */
function public_default_queue_id(PDO $pdo): ?int
{
    $s = public_settings($pdo);
    if ($s['default_queue_id'] !== null) {
        return $s['default_queue_id'];
    }
    $id = $pdo->query("SELECT id FROM queues WHERE archived_at IS NULL ORDER BY (key = 'general') DESC, sort_order, id LIMIT 1")->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** The address a visitor may write to: the queue's, else its active mailbox's, else null. */
function public_queue_address(PDO $pdo, ?int $queueId): ?string
{
    $q = $queueId ?? public_default_queue_id($pdo);
    if ($q === null) {
        return null;
    }
    $st = $pdo->prepare('SELECT COALESCE(q.email_address, (SELECT b.address::text FROM mailboxes b WHERE b.queue_id = q.id AND b.active ORDER BY b.id LIMIT 1)) FROM queues q WHERE q.id = :q');
    $st->execute(['q' => $q]);
    $a = $st->fetchColumn();
    return $a === false || $a === null ? null : (string) $a;
}

/** Render a public page in the bare layout (no shell, no menu, no link into the application) with a status. */
function render_public(string $title, string $html, string $screen, int $status = 200): never
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo view('public-layout.php', ['title' => $title, 'content' => $html, 'screen' => $screen]);
    exit;
}

/** The uploaded files of a multi-file input as a list of $_FILES-shaped arrays (at most $max; empty slots dropped). */
function public_files(string $field, int $max = 3): array
{
    $f = $_FILES[$field] ?? null;
    if ($f === null) {
        return [];
    }
    $out = [];
    if (is_array($f['name'] ?? null)) {
        foreach ($f['name'] as $i => $name) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = ['name' => $name, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? 0, 'size' => $f['size'][$i] ?? 0];
        }
    } elseif (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $out[] = $f;
    }
    return array_slice($out, 0, $max);
}

/** The visitor's address (behind the proxy: the first X-Forwarded-For). */
function public_ip(): ?string
{
    $xff = trim((string) strtok((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), ','));
    $ip = $xff !== '' ? $xff : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip;
}

/** The author's words on a public entry: a requester's name, a member's first name (an agent its display name). */
function public_author_words(?string $memberName, ?string $memberKind, ?string $requesterName): string
{
    if ($requesterName !== null) {
        return $requesterName;
    }
    if ($memberKind === 'agent') {
        return (string) $memberName;
    }
    return trim((string) strtok(trim((string) $memberName), ' ')) ?: 'Support';
}
