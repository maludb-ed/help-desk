<?php
declare(strict_types=1);

function present_rule(array $r): array
{
    return ['rule_id' => $r['rule_id'], 'name' => $r['name'], 'kind' => $r['kind'], 'event' => $r['event'], 'conditions' => (object) $r['conditions'], 'actions' => array_values($r['actions']), 'sort_order' => $r['sort_order'], 'active' => $r['active'],
            'run_count' => $r['run_count'], 'last_run_at' => json_ts($r['last_run_at']), 'built_in' => $r['built_in'], 'words' => rule_words($r)];
}

function present_rule_run(array $r): array
{
    return ['run_id' => $r['run_id'], 'ticket_id' => $r['ticket_id'], 'number' => $r['number'], 'subject' => $r['subject'], 'outcome' => $r['outcome'], 'detail' => $r['detail'], 'ran_at' => json_ts($r['ran_at'])];
}

/** "When a ticket is filed · priority is urgent · set priority, assign" — the rule in a line. */
function rule_words(array $r): string
{
    $when = match ($r['kind']) { 'trigger' => RULE_EVENTS[$r['event']] ?? 'On an event', 'time_based' => 'Every minute', default => 'On create and queue change' };
    $conds = [];
    foreach (['all', 'any'] as $k) {
        foreach ((array) ($r['conditions'][$k] ?? []) as $c) {
            $v = $c['value'] ?? null;
            $conds[] = ($c['field'] ?? '?') . (isset($c['args']) ? ' ' . $c['args'] : '') . ' ' . (RULE_OPS[$c['op'] ?? 'eq'] ?? $c['op']) . ' ' . (is_array($v) ? implode('/', array_map('strval', $v)) : (is_bool($v) ? ($v ? 'true' : 'false') : (string) $v));
        }
    }
    $acts = array_map(static fn (array $a): string => (string) array_key_first($a), $r['actions']);
    return $when . ($conds ? ' · ' . implode(' · ', $conds) : ' · always') . ' → ' . ($acts ? implode(', ', $acts) : 'nothing');
}

/** "3 min ago", "2 h ago", "4 d ago". */
function ago_words(?string $ts): string
{
    if ($ts === null) {
        return 'never';
    }
    $s = max(0, time() - strtotime($ts));
    if ($s < 60) {
        return 'just now';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . ' min ago';
    }
    if ($s < 86400) {
        return intdiv($s, 3600) . ' h ago';
    }
    return intdiv($s, 86400) . ' d ago';
}
