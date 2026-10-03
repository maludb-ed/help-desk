<?php
declare(strict_types=1);

function present_status(array $s): array
{
    return ['status_id' => $s['status_id'], 'key' => $s['key'], 'label' => $s['label'], 'category' => $s['category'], 'color' => $s['color'], 'sort_order' => $s['sort_order'],
            'is_default' => $s['is_default'], 'on_board' => $s['on_board'], 'clock_runs' => $s['clock_runs'], 'is_system' => $s['is_system'], 'archived' => $s['archived_at'] !== null,
            'ticket_count' => $s['ticket_count'] ?? null];
}

function present_transition(?array $r): ?array
{
    return $r === null ? null : ['from_category' => $r['from_category'], 'to_category' => $r['to_category'], 'requires' => $r['requires'], 'may_carry' => $r['may_carry'], 'needs_right' => $r['needs_right'], 'label' => $r['label']];
}
