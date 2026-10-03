<?php
declare(strict_types=1);

/**
 * The rules engine (slice 8): ONE bounded vocabulary the form, the handler and the evaluator read; rule_evaluate() over a ticket's facts read from the base tables (the worker has no
 * acting member — the mcp views are empty to it); rule_apply() through slice 3's write functions, every action its own activity row with after.via and after.rule_id, each under a savepoint
 * (a refused move is that action skipped, not the run failed); one transaction per ticket; a rule_runs row and a rule.fire row per ticket touched.
 */

const RULE_KINDS = ['trigger' => 'Trigger', 'time_based' => 'Time-based', 'assignment' => 'Assignment'];
const RULE_EVENTS = ['ticket.create' => 'A ticket is filed', 'ticket.update' => 'A ticket changes', 'ticket.reply' => 'A reply is added', 'ticket.note' => 'A note is added'];
const RULE_OPS = ['eq' => 'is', 'neq' => 'is not', 'in' => 'is one of', 'not_in' => 'is none of', 'gte' => 'is at least', 'lte' => 'is at most', 'contains' => 'contains', 'is_empty' => 'is empty'];
const RULE_DATE_ARGS = ['created_at' => 'it was filed', 'solved_at' => 'it was solved', 'last_activity_at' => 'the last activity', 'last_requester_reply_at' => 'the requester last wrote', 'last_agent_reply_at' => 'we last wrote'];
const RULE_ASSIGN_WORDS = ['round_robin' => 'the next in turn', 'least_loaded' => 'the least loaded', 'default' => "the queue's default", 'none' => 'nobody'];
const RULE_NOTIFY_WHO = ['assignee' => 'the assignee', 'requester' => 'the requester', 'queue_leads' => "the queue's leads"];

/** field → [label, kind (text|list|number|bool|id|date), ops, values?] */
function rule_vocabulary(): array
{
    return ['fields' => [
        'status_category' => ['Status category', 'list', ['eq', 'neq', 'in', 'not_in'], ['new', 'open', 'pending', 'on_hold', 'solved', 'closed']],
        'status' => ['Status', 'list', ['eq', 'neq', 'in', 'not_in'], 'statuses'],
        'priority' => ['Priority', 'list', ['eq', 'neq', 'in', 'not_in'], ['low', 'normal', 'high', 'urgent']],
        'queue_id' => ['Queue', 'id', ['eq', 'neq', 'in', 'not_in'], 'queues'],
        'category_id' => ['Category', 'id', ['eq', 'neq', 'in', 'not_in', 'is_empty'], 'categories'],
        'type_id' => ['Type', 'id', ['eq', 'neq', 'in', 'not_in', 'is_empty'], 'types'],
        'organization_id' => ['Organization', 'id', ['eq', 'neq', 'in', 'not_in', 'is_empty'], 'organizations'],
        'channel' => ['Channel', 'list', ['eq', 'neq', 'in', 'not_in'], ['web', 'email', 'form', 'agent', 'api', 'phone', 'chat', 'assistant']],
        'tags' => ['Tags (any of)', 'tags', ['eq', 'in', 'not_in', 'is_empty'], 'tags'],
        'assignee' => ['Assignee', 'assignee', ['eq', 'neq'], ['none', 'agent', 'human']],
        'needs_reply' => ['Needs a reply', 'bool', ['eq'], ['true', 'false']],
        'sla_state' => ['SLA state', 'list', ['eq', 'neq', 'in', 'not_in'], ['on_track', 'at_risk', 'breached', 'paused', 'met', 'n/a']],
        'hours_since' => ['Hours since…', 'number', ['gte', 'lte'], null],
        'days_since' => ['Days since…', 'number', ['gte', 'lte'], null],
        'pending_until_passed' => ['The snooze has passed', 'bool', ['eq'], ['true', 'false']],
        'requester_kind' => ['Requester kind', 'list', ['eq', 'neq'], ['member', 'external']],
        'subject_contains' => ['Subject contains', 'text', ['contains'], null],
        'body_contains' => ['The first message contains', 'text', ['contains'], null],
    ], 'actions' => ['set' => 'Set a field', 'assign' => 'Assign', 'add_tags' => 'Add tags', 'remove_tags' => 'Remove tags', 'transition' => 'Move to a status', 'notify' => 'Notify', 'note' => 'Add a note', 'dispatch' => 'Hand to the agent', 'link_children' => 'Solve the children'],
    'settings' => ['auto_close_days', 'reopen_window_days']];
}

/** The ticket facts a rule reads, from the base tables (the worker acts for nobody). $where over alias t (tickets), s (statuses). */
function rule_ticket_rows(PDO $pdo, string $where, array $args, string $now, int $limit = 500): array
{
    $sql = "SELECT t.id AS ticket_id, t.number, t.subject, s.key AS status, s.category AS status_category, t.status_id, t.priority, t.queue_id, t.category_id, t.type_id, y.key AS type, t.organization_id, t.channel,
                   COALESCE((SELECT array_agg(g.slug ORDER BY g.slug) FROM ticket_tags tt JOIN tags g ON g.id = tt.tag_id WHERE tt.ticket_id = t.id), '{}') AS tags,
                   t.assignee_member_id, am.member_kind AS assignee_kind, t.requester_id, r.kind AS requester_kind, r.member_id AS requester_member_id, t.parent_id, t.due_at, t.pending_until,
                   (t.last_requester_reply_at IS NOT NULL AND (t.last_agent_reply_at IS NULL OR t.last_requester_reply_at > t.last_agent_reply_at) AND s.category NOT IN ('solved', 'closed')) AS needs_reply,
                   sla.state AS sla_state, t.created_at, t.solved_at, t.closed_at, t.last_activity_at, t.last_requester_reply_at, t.last_agent_reply_at,
                   (SELECT m.body_text FROM messages m WHERE m.ticket_id = t.id ORDER BY m.id LIMIT 1) AS first_body
              FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id JOIN requesters r ON r.id = t.requester_id LEFT JOIN ticket_types y ON y.id = t.type_id LEFT JOIN members am ON am.id = t.assignee_member_id
              LEFT JOIN LATERAL (SELECT hd_sla_state(g.due_at, g.started_at, g.paused_at, g.met_at, g.breached_at, p.warning_percent) AS state FROM sla_targets g LEFT JOIN sla_policies p ON p.id = g.policy_id
                                  WHERE g.ticket_id = t.id AND g.met_at IS NULL ORDER BY CASE WHEN g.breached_at IS NOT NULL OR CAST(:now AS timestamptz) > g.due_at THEN 0 ELSE 1 END, g.due_at LIMIT 1) sla ON true
             WHERE t.merged_into_id IS NULL AND ($where) ORDER BY t.id LIMIT " . (int) $limit;
    $st = $pdo->prepare($sql);
    $st->execute($args + ['now' => $now]);
    return array_map(static function (array $t): array {
        foreach (['ticket_id', 'status_id', 'queue_id', 'category_id', 'type_id', 'organization_id', 'assignee_member_id', 'requester_id', 'requester_member_id', 'parent_id'] as $k) {
            $t[$k] = $t[$k] === null ? null : (int) $t[$k];
        }
        $t['tags'] = pg_text_array((string) $t['tags']);
        $t['needs_reply'] = (bool) $t['needs_reply'];
        return $t;
    }, $st->fetchAll());
}

function rule_ticket(PDO $pdo, int $ticketId, string $now): ?array
{
    $rows = rule_ticket_rows($pdo, 't.id = :id', ['id' => $ticketId], $now, 1);
    return $rows[0] ?? null;
}

/** The context a pass shares: the clock and the settings a value may name. */
function rule_ctx(PDO $pdo, ?string $now = null): array
{
    $s = $pdo->query('SELECT auto_close_days, reopen_window_days FROM app_settings WHERE id = 1')->fetch() ?: [];
    return ['now' => $now ?? date('c'), 'settings' => ['auto_close_days' => (int) ($s['auto_close_days'] ?? 0), 'reopen_window_days' => (int) ($s['reopen_window_days'] ?? 14)]];
}

/** A condition's value, with "setting:x" read from the context. */
function rule_value(mixed $v, array $ctx): mixed
{
    if (is_string($v) && str_starts_with($v, 'setting:')) {
        return $ctx['settings'][substr($v, 8)] ?? null;
    }
    return $v;
}

function rule_cond_match(array $c, array $t, array $ctx): bool
{
    $field = (string) ($c['field'] ?? '');
    $op = (string) ($c['op'] ?? 'eq');
    $v = rule_value($c['value'] ?? null, $ctx);
    $nowTs = strtotime((string) $ctx['now']) ?: time();
    $cmp = static function ($actual) use ($op, $v): bool {
        $list = is_array($v) ? array_map('strval', $v) : [(string) $v];
        $a = $actual === null ? null : (is_bool($actual) ? ($actual ? 'true' : 'false') : (string) $actual);
        return match ($op) {
            'eq' => $a !== null && in_array($a, [(string) (is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? '' : $v))], true),
            'neq' => $a === null || $a !== (string) (is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? '' : $v)),
            'in' => $a !== null && in_array($a, $list, true),
            'not_in' => $a === null || !in_array($a, $list, true),
            'gte' => $actual !== null && is_numeric($actual) && is_numeric($v) && (float) $actual >= (float) $v,
            'lte' => $actual !== null && is_numeric($actual) && is_numeric($v) && (float) $actual <= (float) $v,
            'contains' => $a !== null && $v !== null && $v !== '' && mb_stripos($a, (string) $v) !== false,
            'is_empty' => $actual === null || $actual === '' || $actual === [],
            default => false,
        };
    };
    switch ($field) {
        case 'tags':
            $tags = (array) ($t['tags'] ?? []);
            $want = is_array($v) ? array_map('strval', $v) : [(string) $v];
            return match ($op) { 'eq', 'in', 'contains' => array_intersect($tags, $want) !== [], 'not_in', 'neq' => array_intersect($tags, $want) === [], 'is_empty' => $tags === [], default => false };
        case 'assignee':
            $actual = $t['assignee_member_id'] === null ? 'none' : (string) $t['assignee_member_id'];
            $kind = $t['assignee_member_id'] === null ? 'none' : ($t['assignee_kind'] === 'agent' ? 'agent' : 'human');
            $want = (string) (is_array($v) ? '' : $v);
            $hit = $want === $actual || $want === $kind;
            return $op === 'neq' ? !$hit : $hit;
        case 'hours_since':
        case 'days_since':
            $arg = (string) ($c['args'] ?? 'created_at');
            if (!isset(RULE_DATE_ARGS[$arg]) || ($t[$arg] ?? null) === null) {
                return false;
            }
            $elapsed = ($nowTs - (strtotime((string) $t[$arg]) ?: $nowTs)) / ($field === 'hours_since' ? 3600 : 86400);
            return $cmp($elapsed);
        case 'pending_until_passed':
            $passed = ($t['pending_until'] ?? null) !== null && strtotime((string) $t['pending_until']) <= $nowTs;
            return $cmp($passed);
        case 'needs_reply':
            return $cmp((bool) ($t['needs_reply'] ?? false));
        case 'subject_contains':
            return $cmp((string) ($t['subject'] ?? ''));
        case 'body_contains':
            return $cmp((string) ($t['first_body'] ?? ''));
        default:
            if (!array_key_exists($field, rule_vocabulary()['fields'])) {
                return false;
            }
            return $cmp($t[$field] ?? null);
    }
}

/** conditions = {"all": [...], "any": [...]} — both optional; an empty document matches every candidate. */
function rule_evaluate(array $rule, array $ticket, array $ctx = []): bool
{
    $c = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : (json_decode((string) ($rule['conditions'] ?? '{}'), true) ?: []);
    $ctx += ['now' => date('c'), 'settings' => []];
    foreach ((array) ($c['all'] ?? []) as $cond) {
        if (!rule_cond_match((array) $cond, $ticket, $ctx)) {
            return false;
        }
    }
    $any = (array) ($c['any'] ?? []);
    if ($any !== []) {
        foreach ($any as $cond) {
            if (rule_cond_match((array) $cond, $ticket, $ctx)) {
                return true;
            }
        }
        return false;
    }
    return true;
}

/** Errors by field for a rule a person or an agent sent; names resolved against the vocabulary and the tables ("No status 'Parked'."). */
function rule_validate(PDO $pdo, array $rule): array
{
    $errors = [];
    $voc = rule_vocabulary();
    if (($rule['name'] ?? '') === '' || mb_strlen((string) $rule['name']) > 120) {
        $errors['name'] = 'Give the rule a name of up to 120 characters.';
    }
    if (!isset(RULE_KINDS[$rule['kind'] ?? ''])) {
        $errors['kind'] = 'The kind is trigger, time_based or assignment.';
    }
    if (($rule['kind'] ?? '') === 'trigger' && !isset(RULE_EVENTS[$rule['event'] ?? ''])) {
        $errors['event'] = 'A trigger needs an event: ' . implode(', ', array_keys(RULE_EVENTS)) . '.';
    }
    $c = $rule['conditions'] ?? [];
    if (!is_array($c) || array_diff(array_keys($c), ['all', 'any']) !== []) {
        $errors['conditions'] = 'conditions is {"all": [...], "any": [...]}.';
    } else {
        foreach (['all', 'any'] as $k) {
            foreach ((array) ($c[$k] ?? []) as $i => $cond) {
                $f = (string) ($cond['field'] ?? '');
                $op = (string) ($cond['op'] ?? 'eq');
                if (!isset($voc['fields'][$f])) {
                    $errors['conditions'] = "'" . $f . "' is not a condition field.";
                    continue;
                }
                if (!in_array($op, $voc['fields'][$f][2], true)) {
                    $errors['conditions'] = "'" . $op . "' is not an op for " . $f . '.';
                    continue;
                }
                if (in_array($f, ['hours_since', 'days_since'], true) && !isset(RULE_DATE_ARGS[$cond['args'] ?? ''])) {
                    $errors['conditions'] = $f . ' needs args: one of ' . implode(', ', array_keys(RULE_DATE_ARGS)) . '.';
                    continue;
                }
                $v = $cond['value'] ?? null;
                if ($op !== 'is_empty' && ($v === null || $v === '' || $v === [])) {
                    $errors['conditions'] = $f . ' needs a value.';
                    continue;
                }
                if (is_string($v) && str_starts_with($v, 'setting:') && !in_array(substr($v, 8), $voc['settings'], true)) {
                    $errors['conditions'] = "'" . $v . "' is not a setting.";
                    continue;
                }
                $e = rule_check_names($pdo, $f, is_array($v) ? $v : [$v]);
                if ($e !== null) {
                    $errors['conditions'] = $e;
                }
            }
        }
    }
    $a = $rule['actions'] ?? [];
    if (!is_array($a) || array_filter($a, static fn ($x): bool => !is_array($x) || count($x) === 0) !== []) {
        $errors['actions'] = 'actions is a list of {"do": {...}} objects.';
    } else {
        foreach ($a as $i => $act) {
            $key = array_key_first($act);
            if (!isset($voc['actions'][$key])) {
                $errors['actions'] = "'" . $key . "' is not an action.";
                continue;
            }
            $with = $act[$key];
            $e = match ($key) {
                'set' => is_array($with) && array_diff(array_keys($with), ['priority', 'type_id', 'category_id', 'due_at']) === [] && $with !== [] ? (isset($with['priority']) && !in_array($with['priority'], ['low', 'normal', 'high', 'urgent'], true) ? 'set.priority is low, normal, high or urgent.' : (isset($with['type_id']) ? rule_check_names($pdo, 'type_id', [$with['type_id']]) : (isset($with['category_id']) ? rule_check_names($pdo, 'category_id', [$with['category_id']]) : null))) : 'set names priority, type_id, category_id or due_at.',
                'assign' => is_array($with) && (isset($with['queue_id']) || isset($with['assignee_member_id'])) ? (isset($with['queue_id']) ? rule_check_names($pdo, 'queue_id', [$with['queue_id']]) : (in_array($with['assignee_member_id'], array_keys(RULE_ASSIGN_WORDS), true) ? null : rule_check_names($pdo, 'member', [$with['assignee_member_id']]))) : 'assign names queue_id or assignee_member_id.',
                'add_tags', 'remove_tags' => is_array($with) && $with !== [] ? null : $key . ' is a list of tag slugs.',
                'transition' => is_string($with) && $with !== '' ? rule_check_names($pdo, 'status', [$with]) : (is_array($with) && isset($with['status']) ? rule_check_names($pdo, 'status', [$with['status']]) : 'transition names a status key.'),
                'notify' => is_array($with) && isset($with['who'], $with['kind']) && (isset(RULE_NOTIFY_WHO[$with['who']]) || preg_match('/^member:\d+$/', (string) $with['who'])) && isset(NOTICE_KINDS[$with['kind']]) ? null : 'notify names who (assignee, requester, queue_leads, member:<id>) and a kind.',
                'note' => is_string($with) && trim($with) !== '' ? null : 'note is the text.',
                'dispatch' => $with === 'assignee' ? null : 'dispatch is "assignee".',
                'link_children' => $with === 'solve' ? null : 'link_children is "solve".',
                default => null,
            };
            if ($e !== null) {
                $errors['actions'] = $e;
            }
        }
    }
    return $errors;
}

/** "No status 'Parked'." — a name a rule uses must exist. */
function rule_check_names(PDO $pdo, string $field, array $values): ?string
{
    $q = ['status' => ['SELECT 1 FROM ticket_statuses WHERE key = :v AND archived_at IS NULL', 'status'], 'queue_id' => ['SELECT 1 FROM queues WHERE id = :v AND archived_at IS NULL', 'queue'],
          'category_id' => ['SELECT 1 FROM categories WHERE id = :v AND archived_at IS NULL', 'category'], 'type_id' => ['SELECT 1 FROM ticket_types WHERE id = :v AND archived_at IS NULL', 'type'],
          'organization_id' => ['SELECT 1 FROM organizations WHERE id = :v', 'organization'], 'tags' => ['SELECT 1 FROM tags WHERE slug = :v', 'tag'], 'member' => ["SELECT 1 FROM members WHERE id = :v AND status = 'active'", 'member']];
    if (!isset($q[$field])) {
        return null;
    }
    foreach ($values as $v) {
        if (is_string($v) && str_starts_with($v, 'setting:')) {
            continue;
        }
        if (str_ends_with($field, '_id') || $field === 'member') {
            if (filter_var($v, FILTER_VALIDATE_INT) === false) {
                return 'No ' . $q[$field][1] . " '" . $v . "'.";
            }
        }
        $st = $pdo->prepare($q[$field][0]);
        $st->execute(['v' => $v]);
        if ($st->fetchColumn() === false) {
            return 'No ' . $q[$field][1] . " '" . $v . "'.";
        }
    }
    return null;
}

/** Whose words a rule's note are: the rule's creator, else the first super-admin (a note must have an author). */
function rule_author(PDO $pdo, array $rule): int
{
    $by = isset($rule['created_by']) && $rule['created_by'] !== null ? (int) $rule['created_by'] : 0;
    if ($by > 0 && one_value($pdo, "SELECT 1 FROM members WHERE id = :m AND status = 'active'", ['m' => $by])) {
        return $by;
    }
    return (int) (one_value($pdo, "SELECT id FROM members WHERE business_role = 'super_admin' AND status = 'active' ORDER BY id LIMIT 1") ?: 1);
}

/** The members a notify action names. */
function rule_notify_targets(PDO $pdo, string $who, array $t): array
{
    return match (true) {
        $who === 'assignee' => $t['assignee_member_id'] !== null ? [['member_id' => $t['assignee_member_id']]] : [],
        $who === 'requester' => [['requester_id' => $t['requester_id']]],
        $who === 'queue_leads' => array_map(static fn (int $m): array => ['member_id' => $m], queue_lead_ids($pdo, (int) $t['queue_id'])),
        str_starts_with($who, 'member:') => [['member_id' => (int) substr($who, 7)]],
        default => [],
    };
}

/**
 * Apply a rule to one ticket: one transaction; each action under a savepoint (a refusal in words = that action skipped); outcome applied | skipped | error; a rule_runs row and a rule.fire
 * row; run_count and last_run_at. $actor: the person (a trigger fired by their write) or null (the worker). Answers ['outcome', 'actions' => [{do, outcome, detail}], 'detail'].
 */
function rule_apply(PDO $pdo, array $rule, array $ticket, ?int $actor = null, string $via = 'rule'): array
{
    $ruleId = (int) $rule['rule_id'];
    $acts = is_array($rule['actions'] ?? null) ? $rule['actions'] : (json_decode((string) ($rule['actions'] ?? '[]'), true) ?: []);
    $t = ticket_facts($pdo, (int) $ticket['ticket_id']);
    $done = [];
    $byId = $actor ?? 0;
    $stamp = ['via' => $via, 'rule_id' => $ruleId];
    $author = $actor ?? rule_author($pdo, $rule);
    $pdo->beginTransaction();
    try {
        if ($t === null) {
            throw new RuntimeException('The ticket is gone.');
        }
        foreach ($acts as $act) {
            $key = (string) array_key_first($act);
            $with = $act[$key];
            $pdo->exec('SAVEPOINT rule_action');
            try {
                $r = rule_do_action($pdo, $key, $with, $t, $ticket, $byId, $stamp + ['author' => $author]);
                $pdo->exec('RELEASE SAVEPOINT rule_action');
                $done[] = ['do' => $key, 'outcome' => $r[0], 'detail' => $r[1]];
            } catch (DomainException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT rule_action');
                $done[] = ['do' => $key, 'outcome' => 'skipped', 'detail' => $e->getMessage()];
            } catch (PDOException $e) {
                $pdo->exec('ROLLBACK TO SAVEPOINT rule_action');
                if ((string) $e->getCode() !== 'P0001') {
                    throw $e;
                }
                $done[] = ['do' => $key, 'outcome' => 'skipped', 'detail' => db_message($e, 'That could not be done.')];
            }
            $t = ticket_facts($pdo, (int) $ticket['ticket_id']) ?? $t;
        }
        $outcome = array_filter($done, static fn (array $d): bool => $d['outcome'] === 'applied') !== [] ? 'applied' : 'skipped';
        $detail = ['actions' => $done];
        $pdo->prepare('INSERT INTO rule_runs (rule_id, ticket_id, outcome, detail) VALUES (:r, :t, :o, CAST(:d AS jsonb))')->execute(['r' => $ruleId, 't' => $ticket['ticket_id'], 'o' => $outcome, 'd' => json_encode($detail, JSON_UNESCAPED_UNICODE)]);
        $pdo->prepare('UPDATE automation_rules SET run_count = run_count + 1, last_run_at = now() WHERE id = :r')->execute(['r' => $ruleId]);
        log_activity($pdo, 'rule.fire', 'automation_rule', $ruleId, ['ticket_id' => $ticket['ticket_id'], 'queue_id' => $t['queue_id'], 'actor_member_id' => $actor, 'after' => ['rule_id' => $ruleId, 'outcome' => $outcome, 'actions' => array_map(static fn (array $d): string => $d['do'] . ':' . $d['outcome'], $done)]]);
        $pdo->commit();
        return ['outcome' => $outcome, 'actions' => $done, 'detail' => null];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $why = mb_substr($e->getMessage(), 0, 300);
        try {
            $pdo->prepare('INSERT INTO rule_runs (rule_id, ticket_id, outcome, detail) VALUES (:r, :t, \'error\', CAST(:d AS jsonb))')->execute(['r' => $ruleId, 't' => $ticket['ticket_id'], 'd' => json_encode(['error' => $why, 'actions' => $done])]);
            $pdo->prepare('UPDATE automation_rules SET run_count = run_count + 1, last_run_at = now() WHERE id = :r')->execute(['r' => $ruleId]);
            log_activity($pdo, 'rule.fire', 'automation_rule', $ruleId, ['ticket_id' => $ticket['ticket_id'], 'queue_id' => $t['queue_id'] ?? null, 'actor_member_id' => $actor, 'after' => ['rule_id' => $ruleId, 'outcome' => 'error', 'error' => $why]]);
        } catch (Throwable) {
        }
        error_log('rule ' . $ruleId . ' on ticket ' . $ticket['ticket_id'] . ': ' . $why);
        return ['outcome' => 'error', 'actions' => $done, 'detail' => $why];
    }
}

/** One action: [applied|skipped, detail]. Throws DomainException for a refusal in words. */
function rule_do_action(PDO $pdo, string $key, mixed $with, array $t, array $ticket, int $by, array $stamp): array
{
    $author = (int) ($stamp['author'] ?? 0);
    unset($stamp['author']);
    switch ($key) {
        case 'set':
            $fields = [];
            foreach ((array) $with as $k => $v) {
                $fields[$k] = $k === 'due_at' ? ($v === null || $v === '' ? null : (new DateTimeImmutable((string) $v))->format('c')) : $v;
            }
            $r = set_ticket_fields($pdo, $t['ticket_id'], $fields, $by);
            if ($r['after'] === []) {
                return ['skipped', 'nothing to change'];
            }
            ticket_log($pdo, $t, 'ticket.set_fields', ['before' => $r['before'], 'after' => $r['after'] + $stamp]);
            return ['applied', implode(', ', array_keys($r['after']))];
        case 'assign':
            $with = (array) $with;
            if (isset($with['queue_id'])) {
                $r = assign_ticket($pdo, $t['ticket_id'], null, (int) $with['queue_id'], $by, $stamp['via'], false);
                if (!$r['changed']) {
                    return ['skipped', 'already there'];
                }
                ticket_log($pdo, $t, 'ticket.assign', ['queue_id' => $r['after']['queue_id'], 'before' => $r['before'], 'after' => $r['after'] + ['rule_id' => $stamp['rule_id']]]);
                if ($r['after']['assignee_member_id'] === null) {
                    assign_on_arrival($pdo, $t['ticket_id'], 'queue_change');
                }
                return ['applied', 'queue ' . $r['after']['queue_name']];
            }
            $who = $with['assignee_member_id'];
            $pick = match ((string) $who) { 'none' => null, 'round_robin' => pick_round_robin($pdo, (int) $t['queue_id']), 'least_loaded' => pick_least_loaded($pdo, (int) $t['queue_id']), 'default' => queue_default_assignee($pdo, (int) $t['queue_id']), default => (int) $who };
            if ($pick === null && (string) $who !== 'none') {
                return ['skipped', 'nobody available'];
            }
            $r = assign_ticket($pdo, $t['ticket_id'], $pick, null, $by, (string) $who === 'round_robin' || (string) $who === 'least_loaded' ? (string) $who : $stamp['via']);
            if (!$r['changed']) {
                return ['skipped', 'already assigned so'];
            }
            ticket_log($pdo, $t, 'ticket.assign', ['before' => $r['before'], 'after' => $r['after'] + ['rule_id' => $stamp['rule_id']]]);
            if ($pick !== null && $r['after']['assignee_kind'] === 'agent') {
                $d = dispatch_to_agent($pdo, $t['ticket_id'], $pick, 'assigned', null);
                ticket_log($pdo, $t, 'ticket.dispatch', ['after' => ['agent_member_id' => $pick, 'via' => $d['via'], 'run_id' => $d['run_id'], 'status' => $d['status']] + $stamp]);
            } elseif ($pick !== null && $pick !== $by) {
                notify($pdo, ['member_id' => $pick], 'assigned', $t['ticket_id'], $t['number'] . ' is yours: ' . $t['subject'], $t['number'] . ' "' . $t['subject'] . '" was assigned to you. See it here: ' . app_url('/tickets/' . $t['ticket_id']), null, 'assigned:' . $t['ticket_id'] . ':' . $pick . ':' . time());
            }
            return ['applied', 'assigned to ' . ($r['after']['assignee_name'] ?? 'nobody')];
        case 'add_tags':
        case 'remove_tags':
            $slugs = array_values(array_filter(array_map(static fn ($g): string => slugify_tag(trim((string) $g)), (array) $with)));
            $before = $ticket['tags'] ?? [];
            $after = set_ticket_tags($pdo, $t['ticket_id'], $key === 'add_tags' ? $slugs : [], $key === 'remove_tags' ? $slugs : []);
            if ($after === $before) {
                return ['skipped', 'nothing to change'];
            }
            ticket_log($pdo, $t, $key === 'add_tags' ? 'ticket.tag' : 'ticket.untag', ['after' => [$key === 'add_tags' ? 'added' : 'removed' => $slugs, 'tags' => $after] + $stamp]);
            return ['applied', implode(' ', $slugs)];
        case 'transition':
            $with = is_array($with) ? $with : ['status' => (string) $with];
            $to = status_by_key($pdo, (string) $with['status']) ?? throw new DomainException("No status '" . $with['status'] . "'.");
            if ((int) $to['status_id'] === (int) $t['status_id']) {
                return ['skipped', 'already ' . $to['label']];
            }
            $moved = transition_ticket($pdo, $t['ticket_id'], $to['status_id'], ['resolution_code' => (string) ($with['resolution_code'] ?? ''), 'hold_reason' => (string) ($with['hold_reason'] ?? ''), 'pending_until' => (string) ($with['pending_until'] ?? '')], $by, $stamp['via'], false);
            ticket_log($pdo, $t, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after'] + ['rule_id' => $stamp['rule_id']]]);
            return ['applied', 'now ' . $to['label']];
        case 'notify':
            $with = (array) $with;
            $text = (string) ($with['text'] ?? ($t['number'] . ' "' . $t['subject'] . '" — ' . (NOTICE_KINDS[$with['kind']] ?? $with['kind'])));
            $n = 0;
            foreach (rule_notify_targets($pdo, (string) $with['who'], $t) as $rc) {
                $n += notify($pdo, $rc, (string) $with['kind'], $t['ticket_id'], $t['number'] . ': ' . mb_substr($text, 0, 120), $text . "\n\nSee it here: " . app_url('/tickets/' . $t['ticket_id']), null, $with['kind'] . ':rule' . $stamp['rule_id'] . ':' . $t['ticket_id'] . ':' . (isset($rc['member_id']) ? 'm' . $rc['member_id'] : 'r' . $rc['requester_id']) . ':' . date('YmdH'));
            }
            return $n > 0 ? ['applied', $n . ' told'] : ['skipped', 'nobody to tell'];
        case 'note':
            $text = str_replace(['{{ticket.number}}', '{{ticket.subject}}'], [$t['number'], $t['subject']], (string) $with);
            $mid = add_message($pdo, $t['ticket_id'], 'internal_note', $text, ['author_member_id' => $author, 'channel' => 'web']);
            ticket_log($pdo, $t, 'ticket.note', ['after' => ['message_id' => $mid, 'kind' => 'internal_note', 'excerpt' => excerpt($text), 'attachments' => 0] + $stamp]);
            return ['applied', 'noted'];
        case 'dispatch':
            if ($t['assignee_member_id'] === null) {
                return ['skipped', 'no assignee'];
            }
            $kind = (string) one_value($pdo, 'SELECT member_kind FROM members WHERE id = :m', ['m' => $t['assignee_member_id']]);
            if ($kind !== 'agent') {
                return ['skipped', 'the assignee is a person'];
            }
            $d = dispatch_to_agent($pdo, $t['ticket_id'], (int) $t['assignee_member_id'], 'assigned', null);
            ticket_log($pdo, $t, 'ticket.dispatch', ['after' => ['agent_member_id' => $t['assignee_member_id'], 'via' => $d['via'], 'run_id' => $d['run_id'], 'status' => $d['status']] + $stamp]);
            return ['applied', 'dispatched via ' . $d['via']];
        case 'link_children':
            if (($ticket['type'] ?? '') !== 'problem' || $t['status_category'] !== 'solved') {
                return ['skipped', 'not a solved problem'];
            }
            $solved = status_by_key($pdo, 'solved');
            $st = $pdo->prepare("SELECT c.id FROM ticket_links l JOIN tickets c ON c.id = l.to_ticket_id JOIN ticket_statuses s ON s.id = c.status_id WHERE l.from_ticket_id = :p AND l.kind = 'parent_child' AND s.category NOT IN ('solved', 'closed')");
            $st->execute(['p' => $t['ticket_id']]);
            $n = 0;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                $c = ticket_facts($pdo, (int) $cid);
                if ($c === null) {
                    continue;
                }
                $pdo->exec('SAVEPOINT rule_child');
                try {
                    $mid = add_message($pdo, $c['ticket_id'], 'internal_note', 'Solved with the problem ' . $t['number'] . '.', ['author_member_id' => $author]);
                    ticket_log($pdo, $c, 'ticket.note', ['after' => ['message_id' => $mid, 'kind' => 'internal_note', 'excerpt' => 'Solved with the problem ' . $t['number'] . '.', 'attachments' => 0] + $stamp]);
                    $moved = transition_ticket($pdo, $c['ticket_id'], $solved['status_id'], ['resolution_code' => 'resolved'], $by, $stamp['via'], false);
                    ticket_log($pdo, $c, 'ticket.transition', ['before' => $moved['before'], 'after' => $moved['after'] + ['rule_id' => $stamp['rule_id'], 'parent_id' => $t['ticket_id']]]);
                    $pdo->exec('RELEASE SAVEPOINT rule_child');
                    $n++;
                } catch (DomainException | PDOException $e) {
                    $pdo->exec('ROLLBACK TO SAVEPOINT rule_child');
                }
            }
            return $n > 0 ? ['applied', $n . ' children solved'] : ['skipped', 'no open children'];
    }
    throw new DomainException("'" . $key . "' is not an action.");
}

/** A time-based rule's candidates: open-category tickets plus solved ones, never closed. */
function rule_candidates(PDO $pdo, array $rule, string $now, int $limit = 500): array
{
    return rule_ticket_rows($pdo, "s.category IN ('new', 'open', 'pending', 'on_hold', 'solved')", [], $now, $limit);
}

/** How many candidates the rule matches now (nothing saved). */
function rule_preview_count(PDO $pdo, array $rule): int
{
    $ctx = rule_ctx($pdo);
    $n = 0;
    foreach (rule_candidates($pdo, $rule, $ctx['now']) as $t) {
        if (rule_evaluate($rule, $t, $ctx)) {
            $n++;
        }
    }
    return $n;
}

/** The form's rows → the conditions document (an empty field drops the row; "a, b" → a list; "setting:x" kept; true/false → booleans). */
function rule_conditions_from_rows(array $rows): array
{
    $all = [];
    foreach ($rows as $row) {
        $f = trim((string) ($row['field'] ?? ''));
        if ($f === '') {
            continue;
        }
        $raw = trim((string) ($row['value'] ?? ''));
        $op = (string) ($row['op'] ?? 'eq');
        $v = $raw;
        if (str_contains($raw, ',')) {
            $v = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $x): bool => $x !== ''));
            if (in_array($op, ['eq', 'neq'], true)) {
                $op = $op === 'eq' ? 'in' : 'not_in';
            }
        } elseif (in_array(strtolower($raw), ['true', 'false'], true)) {
            $v = strtolower($raw) === 'true';
        } elseif (is_numeric($raw) && in_array($op, ['gte', 'lte'], true)) {
            $v = (float) $raw == (int) $raw ? (int) $raw : (float) $raw;
        }
        $c = ['field' => $f, 'op' => $op, 'value' => $v];
        if (($row['args'] ?? '') !== '') {
            $c['args'] = (string) $row['args'];
        }
        $all[] = $c;
    }
    return $all === [] ? [] : ['all' => $all];
}

/** The form's rows → the actions list ("priority=urgent", "queue_id=3", "assignee=round_robin", "solved resolution_code=resolved", "assignee sla_warning", "a, b", a note's text). */
function rule_actions_from_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $do = trim((string) ($row['do'] ?? ''));
        $with = trim((string) ($row['with'] ?? ''));
        if ($do === '') {
            continue;
        }
        $kv = static function (string $s): array { $o = []; foreach (preg_split('/\s+/', $s) ?: [] as $part) { if (str_contains($part, '=')) { [$k, $v] = explode('=', $part, 2); $o[$k] = $v; } } return $o; };
        $out[] = match ($do) {
            'set' => ['set' => $kv($with)],
            'assign' => ['assign' => (static function () use ($kv, $with): array { $o = $kv($with); if (isset($o['queue_id'])) { return ['queue_id' => (int) $o['queue_id']]; } $a = $o['assignee'] ?? $o['assignee_member_id'] ?? $with; return ['assignee_member_id' => isset(RULE_ASSIGN_WORDS[$a]) ? $a : (int) $a]; })()],
            'add_tags', 'remove_tags' => [$do => array_values(array_filter(array_map('trim', explode(',', $with))))],
            'transition' => ['transition' => (static function () use ($kv, $with): array|string { $parts = preg_split('/\s+/', $with) ?: []; $o = $kv($with); $status = $parts[0] ?? ''; return $o === [] ? $status : ['status' => $status] + $o; })()],
            'notify' => ['notify' => (static function () use ($with): array { $parts = preg_split('/\s+/', $with, 3) ?: []; return ['who' => $parts[0] ?? '', 'kind' => $parts[1] ?? ''] + (isset($parts[2]) ? ['text' => $parts[2]] : []); })()],
            'note' => ['note' => $with],
            'dispatch' => ['dispatch' => 'assignee'],
            'link_children' => ['link_children' => 'solve'],
            default => [$do => $with],
        };
    }
    return $out;
}

/** An action's "with" as the form shows it. */
function rule_action_with_text(string $do, mixed $with): string
{
    return match ($do) {
        'set' => implode(' ', array_map(static fn ($k, $v): string => $k . '=' . $v, array_keys((array) $with), (array) $with)),
        'assign' => isset($with['queue_id']) ? 'queue_id=' . $with['queue_id'] : 'assignee=' . ($with['assignee_member_id'] ?? ''),
        'add_tags', 'remove_tags' => implode(', ', (array) $with),
        'transition' => is_array($with) ? (string) ($with['status'] ?? '') . implode('', array_map(static fn ($k, $v): string => $k === 'status' ? '' : ' ' . $k . '=' . $v, array_keys($with), $with)) : (string) $with,
        'notify' => trim(($with['who'] ?? '') . ' ' . ($with['kind'] ?? '') . ' ' . ($with['text'] ?? '')),
        'note' => (string) $with,
        default => is_string($with) ? $with : json_encode($with),
    };
}
