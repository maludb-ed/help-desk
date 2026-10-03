<?php
declare(strict_types=1);

/** The rules (slice 8): the admin's reads through mcp_automation_rules; the worker's through the base table (it acts for nobody). */

function rule_cast(array $r): array
{
    $r['rule_id'] = (int) ($r['rule_id'] ?? $r['id']);
    $r['sort_order'] = (int) $r['sort_order'];
    $r['run_count'] = (int) $r['run_count'];
    $r['active'] = (bool) $r['active'];
    $r['conditions'] = is_array($r['conditions']) ? $r['conditions'] : (json_decode((string) $r['conditions'], true) ?: []);
    $r['actions'] = is_array($r['actions']) ? $r['actions'] : (json_decode((string) $r['actions'], true) ?: []);
    $r['built_in'] = in_array($r['name'], ['Close solved tickets after the window', 'Reopen when the snooze passes'], true) && $r['rule_id'] <= 2;
    return $r;
}

/** $base: the base table (the worker, no acting member); else the view (the admin, the lead). */
function find_rules(PDO $pdo, ?string $kind = null, bool $base = false): array
{
    $sql = ($base ? 'SELECT id AS rule_id, name, kind, event, conditions, actions, sort_order, active, run_count, last_run_at, updated_at, created_by FROM automation_rules' : 'SELECT * FROM mcp_automation_rules') . ($kind !== null ? ' WHERE kind = :k' : '') . ' ORDER BY sort_order, rule_id';
    $st = $pdo->prepare($sql);
    $st->execute($kind !== null ? ['k' => $kind] : []);
    return array_map('rule_cast', $st->fetchAll());
}

function find_rule(PDO $pdo, int $id, bool $base = false): ?array
{
    $st = $pdo->prepare($base ? 'SELECT id AS rule_id, name, kind, event, conditions, actions, sort_order, active, run_count, last_run_at, updated_at, created_by FROM automation_rules WHERE id = :id' : 'SELECT * FROM mcp_automation_rules WHERE rule_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : rule_cast($r);
}

function save_rule(PDO $pdo, ?int $id, array $f, int $by): int
{
    $row = ['name' => $f['name'], 'kind' => $f['kind'], 'event' => $f['kind'] === 'trigger' ? $f['event'] : null, 'conditions' => json_encode((object) $f['conditions'], JSON_UNESCAPED_UNICODE), 'actions' => json_encode(array_values($f['actions']), JSON_UNESCAPED_UNICODE), 'sort_order' => $f['sort_order'], 'active' => $f['active'] ? 't' : 'f'];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO automation_rules (name, kind, event, conditions, actions, sort_order, active, created_by) VALUES (:name, :kind, :event, CAST(:conditions AS jsonb), CAST(:actions AS jsonb), :sort_order, :active, :by) RETURNING id');
        $st->execute($row + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE automation_rules SET name = :name, event = :event, conditions = CAST(:conditions AS jsonb), actions = CAST(:actions AS jsonb), sort_order = :sort_order, active = :active WHERE id = :id')->execute(array_diff_key($row, ['kind' => 1]) + ['id' => $id]);
    return $id;
}

function toggle_rule(PDO $pdo, int $id, bool $active, int $by): void
{
    $pdo->prepare('UPDATE automation_rules SET active = :a WHERE id = :id')->execute(['a' => $active ? 't' : 'f', 'id' => $id]);
}

function delete_rule(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('DELETE FROM automation_rules WHERE id = :id')->execute(['id' => $id]);
}

/** The last $days of a rule's runs, 50 a page, newest first. */
function find_rule_runs(PDO $pdo, int $ruleId, int $days = 30, int $page = 1): array
{
    $st = $pdo->prepare('SELECT r.run_id, r.rule_id, r.ticket_id, t.number, t.subject, r.outcome, r.detail, r.ran_at FROM mcp_rule_runs r LEFT JOIN tickets t ON t.id = r.ticket_id
                          WHERE r.rule_id = :r AND r.ran_at > now() - make_interval(days => :d) ORDER BY r.ran_at DESC, r.run_id DESC LIMIT 51 OFFSET :o');
    $st->execute(['r' => $ruleId, 'd' => $days, 'o' => max(0, $page - 1) * 50]);
    return array_map(static function (array $r): array {
        $r['run_id'] = (int) $r['run_id'];
        $r['ticket_id'] = $r['ticket_id'] === null ? null : (int) $r['ticket_id'];
        $r['detail'] = is_array($r['detail']) ? $r['detail'] : (json_decode((string) $r['detail'], true) ?: []);
        return $r;
    }, $st->fetchAll());
}

function rule_log_state(array $r): array
{
    return ['name' => $r['name'], 'kind' => $r['kind'], 'event' => $r['event'], 'conditions' => $r['conditions'], 'actions' => $r['actions'], 'sort_order' => $r['sort_order'], 'active' => $r['active']];
}
