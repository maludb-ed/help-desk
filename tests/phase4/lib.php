<?php
/**
 * Helpers for the Phase 4 proofs (docs/helpdesk-mcp-tool-surface.md; design §10, §13). Run through tests/phase4/run.sh: a fresh SCRATCH database (never the installed
 * one), the application on :8291, a fake kernel on :8292 (run-facts, K6), and the TWO MCP SERVERS on :8295 (records) and :8296 (activity) — plus a third records server on
 * :8297 whose kernel URL is dead (the gate must fail closed). Everything a proof makes is named "SMOKE …". Builds on slice 10's helpers (the world, the dated fixture).
 */
require dirname(__DIR__) . '/phase3/slice10/lib.php';

const REC = 8295;
const ACT = 8296;
const REC_DEAD = 8297;

/** A person's own mcp_ token (the servers resolve it through mcp_resolve_token). Cached per member. */
function ptoken(int $member): string
{
    static $t = [];
    if (isset($t[$member])) { return $t[$member]; }
    $raw = 'mcp_' . bin2hex(random_bytes(24));
    q("INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope) VALUES (:m, 'SMOKE p4', :h, 'mcp')", ['m' => $member, 'h' => hash('sha256', $raw)]);
    return $t[$member] = $raw;
}
/** The tenant's signed RUN token an agent presents ('mid.exp.run.hmac' over run:mid.exp.run). */
function rtoken(int $member, int $run, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl) . '.' . $run;
    return $p . '.' . hash_hmac('sha256', 'run:' . $p, need('ACTION_TOKEN_KEY'));
}
/** A person's action token (the command bar's): 'mid.exp.hmac'. */
function atoken(int $member, int $ttl = 300): string
{
    $p = $member . '.' . (time() + $ttl);
    return $p . '.' . hash_hmac('sha256', $p, need('ACTION_TOKEN_KEY'));
}
/** The kernel's own 60-second token: 'kernel.exp.app.nonce.hmac' over kernel:exp.app.nonce. */
function ktoken(string $app = 'helpdesk', int $ttl = 60): string
{
    $p = (time() + $ttl) . '.' . $app . '.' . bin2hex(random_bytes(16));
    return 'kernel.' . $p . '.' . hash_hmac('sha256', 'kernel:' . $p, need('ACTION_TOKEN_KEY'));
}
/** Tell the fake kernel what the run-facts call answers for a run id. $endpoints: [name => [tool => constraints]] or [] for another application's. */
function facts(int $run, int $member, array $endpoints, array $o = []): void
{
    kernel_state(function ($s) use ($run, $member, $endpoints, $o) {
        $eps = [];
        foreach ($endpoints as $name => $tools) { $eps[] = ['id' => 1, 'name' => $name, 'tools' => (object) $tools]; }
        $s['facts'][(string) $run] = ['valid' => $o['valid'] ?? true, 'is_agent' => $o['is_agent'] ?? true, 'member_id' => $member, 'run_id' => $run, 'request_id' => "req-run-$run",
            'trigger' => $o['trigger'] ?? 'chat', 'is_eval' => ($o['trigger'] ?? '') === 'eval', 'endpoints' => $eps];
        return $s;
    });
}
/** An agent: a mirror row with its roles. capability null = not yet admitted (the first-contact path admits it). */
function make_agent(int $id, string $name, array $roles = ['user'], ?string $cap = 'write'): void
{
    $capSql = $cap === null ? 'NULL' : "'$cap'";
    q("INSERT INTO members (id, member_kind, display_name, business_role, status, capability, roles) VALUES (:i, 'agent', :n, 'user', 'active', $capSql, CAST(:r AS text[]))
       ON CONFLICT (id) DO UPDATE SET capability = $capSql, status = 'active', roles = CAST(:r AS text[])", ['i' => $id, 'n' => $name, 'r' => '{' . implode(',', $roles) . '}']);
}

// ---- the MCP client (the kernel's own: initialize, initialized, then the call) ------------------------------------
function mcp_post(int $port, string $token, array $msg, ?string $session = null, string $path = '/mcp'): array
{
    $h = ['Content-Type: application/json', 'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2025-06-18'];
    if ($token !== '') { $h[] = 'Authorization: Bearer ' . $token; }
    if ($session) { $h[] = 'Mcp-Session-Id: ' . $session; }
    $sess = $session;
    $ch = curl_init("http://127.0.0.1:$port$path");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($msg), CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$sess) { if (stripos($line, 'mcp-session-id:') === 0) { $sess = trim(substr($line, 15)); } return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    if (!is_string($body) || $body === '') { return [null, $sess, $status]; }
    if (stripos($type, 'text/event-stream') !== false) {
        foreach (preg_split('/\r?\n/', $body) as $line) {
            if (str_starts_with($line, 'data:')) { $m = json_decode(trim(substr($line, 5)), true); if (is_array($m) && ($m['id'] ?? null) === ($msg['id'] ?? null)) { return [$m, $sess, $status]; } }
        }
        return [null, $sess, $status];
    }
    return [json_decode($body, true), $sess, $status];
}
function mcp_open(int $port, string $token, string $path = '/mcp'): array
{
    [$init, $sess, $st] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'p4', 'version' => '1']]], null, $path);
    if ($st === 200) { mcp_post($port, $token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sess, $path); }
    return [$sess, $st];
}
/** The tool names a bearer is offered, or null when the server refused it (401). */
function mcp_tools(int $port, string $token, string $path = '/mcp'): ?array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return null; }
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $sess, $path);
    $names = array_map(fn ($t) => $t['name'], $a['result']['tools'] ?? []);
    sort($names);
    return $names;
}
/**
 * Call a tool as a bearer. Answers ['http', 'error' => bool (the tool refused), 'text', 'data' => decoded JSON or null]. Arguments are wrapped as {"params": ...} the way
 * FastMCP's models take them, except the kernel's flat call ($flat) and the tools that take none (app_roles, my_tokens).
 */
function mcp_tool(int $port, string $token, string $tool, array $args = [], bool $flat = false, string $path = '/mcp'): array
{
    [$sess, $st] = mcp_open($port, $token, $path);
    if ($st !== 200) { return ['http' => $st, 'error' => true, 'text' => '', 'data' => null]; }
    $arguments = $flat || ($args === [] && in_array($tool, ['app_roles', 'my_tokens'], true)) ? (object) $args : ['params' => (object) $args];
    [$a] = mcp_post($port, $token, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $sess, $path);
    $res = $a['result'] ?? [];
    $text = (string) ($res['content'][0]['text'] ?? ($a['error']['message'] ?? ''));
    $data = json_decode($text, true);
    return ['http' => 200, 'error' => !empty($res['isError']) || isset($a['error']) || (is_array($data) && isset($data['error']) && count($data) <= 2), 'text' => $text, 'data' => is_array($data) ? $data : null];
}
/** A tool as a person (their own token): the decoded answer. */
function tool(int $member, string $name, array $args = [], int $port = REC): array { return mcp_tool($port, ptoken($member), $name, $args); }
function tdata(int $member, string $name, array $args = [], int $port = REC) { return tool($member, $name, $args, $port)['data']; }
/** Every key anywhere in a decoded answer, and every string value — what a grep over the text would find. */
function walk($v, callable $f, string $path = ''): void
{
    if (is_array($v)) { foreach ($v as $k => $x) { walk($x, $f, $path . '.' . $k); } return; }
    $f($path, $v);
}
function has_key($data, string $key): bool
{
    $found = false;
    walk($data, function ($p) use ($key, &$found) { if (substr($p, (int) strrpos($p, '.') + 1) === $key) { $found = true; } });
    return $found;
}
/** The ticket numbers of a list answer. */
function numbers(?array $d, string $key = 'tickets'): array { $n = array_map(fn ($t) => $t['number'], $d[$key] ?? []); sort($n); return $n; }
function num_of(int $id): string { return (string) one('SELECT number FROM tickets WHERE id = :t', ['t' => $id]); }
/** SQL over the views AS a member (the same rows the server hands that member): rows. */
function sql_as(int $member, string $sql, array $args = []): array
{
    pdo()->exec("SELECT set_config('app.member_id', '$member', false)");
    $rows = q($sql, $args);
    pdo()->exec("SELECT set_config('app.member_id', '1', false)");
    return $rows;
}
/** A one-value query as a member. */
function val_as(int $member, string $sql, array $args = []) { $r = sql_as($member, $sql, $args); return $r ? array_values($r[0])[0] : null; }
const OPEN_SQL = "('new','open','pending','on_hold')";
