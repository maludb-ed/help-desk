<?php
declare(strict_types=1);

/** Triggers (slice 8): after the commit of a primary write, every active trigger for the event, each in its own transaction (rule_apply); depth 1 — a trigger's actions never fire triggers. */
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/queries.php';

function automation_run_triggers(PDO $pdo, string $event, int $ticketId): array
{
    if (($GLOBALS['__rule_depth'] ?? 0) > 0 || !isset(RULE_EVENTS[$event])) {
        return [];
    }
    $GLOBALS['__rule_depth'] = 1;
    $runs = [];
    try {
        $rules = array_values(array_filter(find_rules($pdo, 'trigger', true), static fn (array $r): bool => $r['active'] && $r['event'] === $event));
        if ($rules === []) {
            return [];
        }
        $ctx = rule_ctx($pdo);
        foreach ($rules as $rule) {
            $t = rule_ticket($pdo, $ticketId, $ctx['now']);
            if ($t === null) {
                break;
            }
            if (!rule_evaluate($rule, $t, $ctx)) {
                continue;
            }
            $runs[] = ['rule_id' => $rule['rule_id'], 'name' => $rule['name']] + rule_apply($pdo, $rule, $t, current_member_id(), 'trigger');
        }
    } finally {
        $GLOBALS['__rule_depth'] = 0;
    }
    return $runs;
}
