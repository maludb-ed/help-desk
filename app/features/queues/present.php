<?php
declare(strict_types=1);

/** The JSON shapes of a queue and its members — whitelists, never a raw row. */

function queue_method_words(array $q): string
{
    if ($q['assignment_method'] === 'default_assignee') {
        return $q['default_assignee_name'] !== null ? 'Goes to ' . $q['default_assignee_name'] : 'Default assignee (none named)';
    }
    return ASSIGNMENT_METHODS[$q['assignment_method']] ?? $q['assignment_method'];
}

function present_queue(array $q): array
{
    return ['queue_id' => $q['queue_id'], 'key' => $q['key'], 'name' => $q['name'], 'description' => $q['description'],
            'department' => $q['department_id'] === null ? null : ['department_id' => $q['department_id'], 'name' => $q['department_name'], 'members_join' => $q['include_department']],
            'assignment_method' => $q['assignment_method'], 'assignment' => queue_method_words($q),
            'default_assignee' => $q['default_assignee_member_id'] === null ? null : ['member_id' => $q['default_assignee_member_id'], 'name' => $q['default_assignee_name'], 'kind' => $q['default_assignee_kind']],
            'schedule' => $q['business_hours_id'] === null ? null : ['schedule_id' => $q['business_hours_id'], 'name' => $q['schedule_name']],
            'policy' => $q['sla_policy_id'] === null ? null : ['policy_id' => $q['sla_policy_id'], 'name' => $q['policy_name']],
            'email_address' => $q['email_address'], 'sort_order' => $q['sort_order'], 'is_default' => $q['is_default'], 'mine' => $q['mine'], 'i_lead' => $q['i_lead'],
            'open_count' => $q['open_count'], 'member_count' => $q['member_count'], 'leads' => $q['lead_names']];
}

function present_queue_member(array $m): array
{
    return ['member_id' => $m['member_id'], 'name' => $m['display_name'], 'kind' => $m['member_kind'], 'is_lead' => $m['is_lead'], 'how' => $m['how']];
}
