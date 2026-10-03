<?php
declare(strict_types=1);
/**
 * Actions `sla_policy_create` / `sla_policy_update` (by the presence of `policy`; log `sla_policy.create` / `sla_policy.update`, before/after of the changed fields): settings.manage.
 * Matches: organizations[], queues[], types[], priorities[], tags (empty = any). Targets: the grid (targets[priority][metric]) or `targets` as JSON; minutes are positive integers or blank.
 * `move=up|down` re-saves the match order instead.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/sla/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('policy') ?? request_integer('policy_id');
$cur = null;
if ($id !== null) {
    $cur = policy_state($pdo, $id) ?? refuse(404, 'Policy not found.');
    if (!$cur['active']) {
        refuse(422, 'That policy is archived.');
    }
}
$move = (string) req_val('move');
if ($cur !== null && in_array($move, ['up', 'down'], true)) {
    [$before, $after] = hd_guard($pdo, static function () use ($pdo, $id, $move): array {
        $pdo->beginTransaction();
        $r = move_sla_policy($pdo, $id, $move);
        if ($r[0] !== $r[1]) {
            log_activity($pdo, 'sla_policy.update', 'sla_policy', $id, ['before' => ['sort_order' => $r[0]], 'after' => ['sort_order' => $r[1]]]);
        }
        $pdo->commit();
        return $r;
    });
    hd_done($before === $after ? $cur['name'] . ' is already at the ' . ($move === 'up' ? 'top' : 'bottom') : 'Moved ' . $cur['name'] . ' ' . $move, $id, hd_land(return_path('/settings/sla/'), 'moved', 'policy-card-' . $id), 'policyChanged', ['sort_order' => $after]);
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Give the policy a name of up to 80 characters.';
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 500)) : ($cur['description'] ?? null);
$order = hd_int('sort_order', $cur['sort_order'] ?? 100, 0, 10000, 'The order', $errors);
$warning = hd_int('warning_percent', $cur['warning_percent'] ?? 80, 1, 99, 'The warning percent', $errors);
$scheduleField = req_has('schedule') ? 'schedule' : 'business_hours_id';
$schedule = hd_ref($pdo, $scheduleField, $cur['business_hours_id'] ?? null, 'SELECT 1 FROM business_hours WHERE id = :id', 'schedule', $errors);
// conditions
$c = $cur['conditions'] ?? [];
$ids = static function (string $field, string $key, string $table) use ($pdo, &$c, &$errors): void {
    $list = request_list($field);
    if ($list === null) {
        return;
    }
    $out = [];
    foreach ($list as $v) {
        if (filter_var($v, FILTER_VALIDATE_INT) === false) {
            $errors[$field] = 'Choose ' . $field . ' from the list.';
            return;
        }
        $st = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = :id");
        $st->execute(['id' => (int) $v]);
        if ($st->fetchColumn() === false) {
            $errors[$field] = 'One of the ' . $field . ' is not here.';
            return;
        }
        $out[] = (int) $v;
    }
    if ($out === []) {
        unset($c[$key]);
    } else {
        $c[$key] = array_values(array_unique($out));
    }
};
$ids('organizations', 'organization_ids', 'organizations');
$ids('queues', 'queue_ids', 'queues');
$ids('types', 'type_ids', 'ticket_types');
if (($pr = request_list('priorities')) !== null) {
    $bad = array_diff($pr, array_keys(PRIORITIES));
    if ($bad !== []) {
        $errors['priorities'] = 'Priorities are urgent, high, normal or low.';
    }
    $pr = array_values(array_intersect(array_keys(PRIORITIES), $pr));
    if ($pr === []) {
        unset($c['priorities']);
    } else {
        $c['priorities'] = $pr;
    }
}
if (($tags = request_list('tags')) !== null) {
    $tags = array_values(array_unique(array_map(static fn (string $t): string => mb_substr(strtolower($t), 0, 40), $tags)));
    if ($tags === []) {
        unset($c['tags']);
    } else {
        $c['tags'] = $tags;
    }
}
// targets
$t = $cur['targets'] ?? [];
if (req_has('targets')) {
    $raw = $_POST['targets'];
    if (!is_array($raw)) {
        $raw = json_decode((string) $raw, true);
        if (!is_array($raw)) {
            $errors['targets'] = 'Targets are JSON: {"urgent": {"first_response": 15, "next_response": 30, "resolution": 240}, …}.';
            $raw = [];
        }
    }
    $t = [];
    foreach ($raw as $p => $metrics) {
        if (!isset(PRIORITIES[$p])) {
            $errors['targets'] = 'Priorities are urgent, high, normal or low.';
            continue;
        }
        if (!is_array($metrics)) {
            $errors['targets'] = 'Each priority holds first_response, next_response and resolution.';
            continue;
        }
        foreach ($metrics as $m => $v) {
            if (!isset(SLA_METRICS[$m])) {
                $errors['targets'] = 'The metrics are first_response, next_response and resolution.';
                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1 || (int) $v > 525600) {
                $errors['targets'] = 'A target is a number of minutes from 1 to 525600, or blank.';
                continue;
            }
            $t[$p][$m] = (int) $v;
        }
    }
    $t = array_filter($t);
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['name' => $name, 'description' => $description, 'sort_order' => $order, 'business_hours_id' => $schedule, 'warning_percent' => $warning];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f, $c, $t): int {
    $pdo->beginTransaction();
    $newId = save_sla_policy($pdo, $id, $f, $c, $t);
    $after = policy_state($pdo, $newId);
    unset($after['active']);
    if ($cur === null) {
        log_activity($pdo, 'sla_policy.create', 'sla_policy', $newId, ['after' => $after]);
    } else {
        $b = $cur;
        unset($b['active']);
        $d = hd_diff($b, $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'sla_policy.update', 'sla_policy', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the policy ' : 'Saved the policy ') . $name, $newId, hd_land(return_path('/settings/sla/'), 'saved', 'policy-card-' . $newId), 'policyChanged', ['policy_id' => $newId]);
