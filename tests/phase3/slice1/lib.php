<?php
/**
 * Helpers for the slice 1 proofs (docs/build-specs/queues-people.md, "Proof"). Run through tests/phase3/slice1/run.sh: a fresh SCRATCH database,
 * the application on :8291, a fake kernel and a fake MaluDB. Everything a proof makes is named "SMOKE …". Builds on tests/phase2/lib.php.
 * The cast: the fixture's seven — the owner (1, admin), Priya (26, requester), Marco (27, support), Lena (28, lead), Colin (29, collaborator),
 * Ext (31, an external member), the Frontline agent (40).
 */
require dirname(__DIR__, 2) . '/phase2/lib.php';

const JSONH = ['Accept: application/json'];

/** A signed-on jar for a fixture member (cached per proof). */
function as_member(int $m): string
{
    static $jars = [];
    if (isset($jars[$m])) { return $jars[$m]; }
    [$j, $r] = sign_on($m);
    if ($r['code'] !== 302) { fwrite(STDERR, "sign-on of $m failed: {$r['code']}\n"); }
    return $jars[$m] = $j;
}
/** A write over HTTP as a signed-on person, answered as JSON: [status, decoded body, raw]. The CSRF token is fetched from the shell once per jar. */
function act(string $jar, string $path, array $form, array $extraHeaders = []): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'headers' => array_merge(JSONH, $extraHeaders), 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
/** The same write as the browser sends it (a form POST, HTML back): [status, raw response]. */
function act_html(string $jar, string $path, array $form): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $r = req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => $tok[$jar]]]);
    return [$r['code'], $r];
}
/** A screen as JSON (data only): [status, data, raw]. */
function screen(string $jar, string $path): array { $r = req('GET', $path, ['jar' => $jar, 'headers' => JSONH]); return [$r['code'], json_decode($r['body'], true)['data'] ?? [], $r]; }
function msg(array $body): string { return (string) ($body['error']['message'] ?? ''); }
function fields(array $body): array { return (array) ($body['error']['fields'] ?? []); }
/** A person's action token (the kernel's shape) and a run token with its relay. */
function person_token(int $m, int $ttl = 300): string { $key = need('ACTION_TOKEN_KEY'); $p = $m . '.' . (time() + $ttl); return $p . '.' . hash_hmac('sha256', $p, $key); }
function run_token(int $m, int $runId): array { $key = need('ACTION_TOKEN_KEY'); $p = $m . '.' . (time() + 300) . '.' . $runId; $t = $p . '.' . hash_hmac('sha256', 'run:' . $p, $key); return [$t, hash_hmac('sha256', $t, need('ACTIONS_RELAY_KEY'))]; }
/** A write under an action token (no session, no CSRF): [status, body]. */
function act_token(string $path, array $form, array $headers): array { $r = req('POST', $path, ['headers' => array_merge(JSONH, $headers), 'form' => $form]); return [(int) $r['code'], json_decode($r['body'], true) ?? [], $r]; }

/** A ticket by SQL (slice 3 brings the screens): subject, requester, queue, status category. Answers its id. */
function mkticket(int $requester, int $queue, string $subject = 'SMOKE ticket', string $category = 'new'): int
{
    $status = (int) one("SELECT id FROM ticket_statuses WHERE category = :c AND archived_at IS NULL ORDER BY is_default DESC, sort_order LIMIT 1", ['c' => $category]);
    return (int) one('INSERT INTO tickets (subject, requester_id, queue_id, status_id, organization_id) VALUES (:s, :r, :q, :st, (SELECT organization_id FROM requesters WHERE id = :r2)) RETURNING id',
        ['s' => $subject, 'r' => $requester, 'q' => $queue, 'st' => $status, 'r2' => $requester]);
}
/** A requester row for a member (hd_requester_for_member), or an external by email (hd_requester_for_email). */
function requester_of_member(int $m): int { return (int) one('SELECT hd_requester_for_member(:m)', ['m' => $m]); }
function external(string $email, string $name): int { return (int) one('SELECT hd_requester_for_email(:e, :n)', ['e' => $email, 'n' => $name]); }
function general_queue(): int { return (int) one("SELECT id FROM queues WHERE key = 'general'"); }
