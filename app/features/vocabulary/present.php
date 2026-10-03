<?php
declare(strict_types=1);

function present_type(array $t): array
{
    return ['type_id' => $t['type_id'], 'key' => $t['key'], 'label' => $t['label'], 'description' => $t['description'], 'sort_order' => $t['sort_order'], 'is_system' => $t['is_system'],
            'archived' => $t['archived_at'] !== null, 'ticket_count' => $t['ticket_count'], 'open_count' => $t['open_count']];
}

function present_category(array $c): array
{
    return ['category_id' => $c['category_id'], 'parent_id' => $c['parent_id'], 'name' => $c['name'], 'path' => $c['path'] ?? null, 'depth' => $c['depth'] ?? null, 'description' => $c['description'],
            'default_queue' => $c['default_queue_id'] === null ? null : ['queue_id' => $c['default_queue_id'], 'name' => $c['default_queue_name'] ?? null],
            'default_type' => $c['default_type_id'] === null ? null : ['type_id' => $c['default_type_id'], 'label' => $c['default_type_label'] ?? null],
            'default_priority' => $c['default_priority'], 'policy' => $c['sla_policy_id'] === null ? null : ['policy_id' => $c['sla_policy_id'], 'name' => $c['policy_name'] ?? null],
            'requester_visible' => $c['requester_visible'], 'sort_order' => $c['sort_order'], 'archived' => $c['archived_at'] !== null, 'ticket_count' => $c['ticket_count'] ?? null];
}

function present_tag(array $g): array
{
    return ['tag_id' => $g['tag_id'], 'slug' => $g['slug'], 'label' => $g['label'], 'usage' => $g['usage']];
}
