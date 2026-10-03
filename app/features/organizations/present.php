<?php
declare(strict_types=1);

function present_organization(array $o): array
{
    return ['organization_id' => $o['organization_id'], 'name' => $o['name'], 'support_tier' => $o['support_tier'], 'domains' => $o['domains'], 'tags' => $o['tags'],
            'policy' => $o['sla_policy_id'] === null ? null : ['policy_id' => $o['sla_policy_id'], 'name' => $o['policy_name']],
            'members_see_each_other' => $o['members_see_each_other'], 'notes' => $o['notes'], 'open_count' => $o['open_count'], 'ticket_count' => $o['ticket_count'],
            'people_count' => $o['people_count'], 'created_at' => json_ts($o['created_at']), 'updated_at' => json_ts($o['updated_at'])];
}
