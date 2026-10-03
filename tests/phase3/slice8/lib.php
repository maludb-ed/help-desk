<?php
/** Helpers for the slice 8 proofs (docs/build-specs/automation-worker.md, "Proof"): slice 3's library and world; the worker run as a command with a faked clock; rules made through the handlers or planted by SQL. */
require dirname(__DIR__) . '/slice3/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/automation/worker_steps.php';
pdo()->exec("SELECT set_config('app.member_id', '1', false)");

/** One pass of the worker as the timer would run it: the JSON report. $now fakes the clock (ISO 8601). */
function worker(array $only = [], ?string $now = null): array
{
    $cmd = ($now !== null ? 'HD_WORKER_NOW=' . escapeshellarg($now) . ' ' : '') . 'php ' . escapeshellarg(dirname(__DIR__, 3) . '/bin/worker.php') . ($only !== [] ? ' --only=' . implode(',', $only) : '') . ' 2>&1';
    $out = (string) shell_exec($cmd);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    $json = json_decode((string) end($lines), true);
    if (!is_array($json)) { fwrite(STDERR, "worker said: " . $out . "\n"); return ['error' => $out]; }
    return $json;
}
function at(string $when): string { return (new DateTimeImmutable($when))->format('c'); }
function plant_rule(string $name, string $kind, array $conditions, array $actions, ?string $event = null, int $sort = 100): int
{
    return (int) one('INSERT INTO automation_rules (name, kind, event, conditions, actions, sort_order) VALUES (:n, :k, :e, CAST(:c AS jsonb), CAST(:a AS jsonb), :s) RETURNING id', ['n' => $name, 'k' => $kind, 'e' => $event, 'c' => json_encode((object) $conditions), 'a' => json_encode($actions), 's' => $sort]);
}
function rule_runs_of(int $rule): array { return q('SELECT * FROM rule_runs WHERE rule_id = :r ORDER BY id', ['r' => $rule]); }
function chat_calls(): array { $f = need('FAKE_KERNEL_STATE') . '.chat'; return is_file($f) ? array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", (string) file_get_contents($f))))) : []; }
function presence_of(int $member, string $availability, ?int $capacity = null): void
{
    q('INSERT INTO queue_presence (member_id, availability, capacity) VALUES (:m, :a, :c) ON CONFLICT (member_id) DO UPDATE SET availability = EXCLUDED.availability, capacity = EXCLUDED.capacity', ['m' => $member, 'a' => $availability, 'c' => $capacity]);
}
function queue_method(int $queue, string $method, ?int $default = null): void { q('UPDATE queues SET assignment_method = :m, default_assignee_member_id = :d WHERE id = :q', ['m' => $method, 'd' => $default, 'q' => $queue]); }
