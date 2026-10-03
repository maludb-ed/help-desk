<?php
declare(strict_types=1);

function present_policy(array $p): array
{
    return ['policy_id' => $p['policy_id'], 'name' => $p['name'], 'description' => $p['description'], 'sort_order' => $p['sort_order'], 'conditions' => (object) $p['conditions'], 'matches' => $p['matches'] ?? null,
            'targets' => (object) $p['targets'], 'schedule' => $p['business_hours_id'] === null ? null : ['schedule_id' => $p['business_hours_id'], 'name' => $p['schedule_name']],
            'warning_percent' => $p['warning_percent'], 'active' => $p['active']];
}

function present_schedule(array $s): array
{
    return ['schedule_id' => $s['schedule_id'], 'name' => $s['name'], 'timezone' => $s['timezone'], 'is_default' => $s['is_default'], 'summary' => $s['summary'],
            'intervals' => (object) $s['intervals'], 'holidays' => array_map(static fn (array $h): array => ['date' => $h['holiday'], 'name' => $h['name']], $s['holidays'])];
}
