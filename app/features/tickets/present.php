<?php
declare(strict_types=1);

/** The JSON shapes and the words of a ticket — whitelists; never a body beyond an excerpt, never a token, never another requester's address. */

function present_ticket_brief(array $t): array
{
    return ['ticket_id' => $t['ticket_id'], 'number' => $t['number'], 'subject' => $t['subject'], 'requester' => ['requester_id' => $t['requester_id'], 'name' => $t['requester_name'], 'kind' => $t['requester_kind']],
            'organization' => $t['organization_id'] === null ? null : ['organization_id' => $t['organization_id'], 'name' => $t['organization_name']],
            'queue' => ['queue_id' => $t['queue_id'], 'name' => $t['queue_name']],
            'assignee' => $t['assignee_member_id'] === null ? null : ['member_id' => $t['assignee_member_id'], 'name' => $t['assignee_name'], 'kind' => $t['assignee_kind']],
            'status' => ['status_id' => $t['status_id'], 'key' => $t['status'], 'label' => $t['status_label'], 'category' => $t['status_category'], 'color' => $t['status_color']],
            'priority' => $t['priority'], 'type' => $t['type'], 'category' => $t['category_name'], 'channel' => $t['channel'], 'needs_reply' => $t['needs_reply'], 'tags' => $t['tags'],
            'sla' => ['state' => $t['sla_state'], 'next_due_at' => json_ts($t['sla_next_due_at']), 'metric' => $t['sla_next_metric'], 'words' => sla_due_words($t['sla_next_due_at'], $t['sla_state'])],
            'reply_count' => $t['reply_count'], 'last_activity_at' => json_ts($t['last_activity_at']), 'created_at' => json_ts($t['created_at'])];
}

function present_ticket(array $t, bool $internals): array
{
    $out = present_ticket_brief($t) + ['submitter_member_id' => $t['submitter_member_id'], 'due_at' => json_ts($t['due_at']), 'pending_until' => json_ts($t['pending_until']), 'hold_reason' => $t['hold_reason'],
        'resolution_code' => $t['resolution_code'], 'parent_id' => $t['parent_id'], 'merged_into_id' => $t['merged_into_id'], 'external_id' => $t['external_id'], 'reopen_count' => $t['reopen_count'],
        'custom_values' => (object) $t['custom_values'], 'first_response_at' => json_ts($t['first_response_at']), 'last_agent_reply_at' => json_ts($t['last_agent_reply_at']),
        'last_requester_reply_at' => json_ts($t['last_requester_reply_at']), 'solved_at' => json_ts($t['solved_at']), 'closed_at' => json_ts($t['closed_at']),
        'followers' => array_map(static fn (array $f): array => ['kind' => $f['kind'], 'member_id' => $f['member_id'], 'requester_id' => $f['requester_id'], 'name' => $f['member_name'] ?? $f['requester_name']], $t['followers']),
        'links' => array_map(static fn (array $l): array => ['link_id' => $l['link_id'], 'kind' => $l['kind'], 'direction' => $l['direction'], 'ticket_id' => $l['ticket_id'], 'number' => $l['number'], 'subject' => $l['subject']], $t['links']),
        'viewers' => array_map(static fn (array $v): array => ['member_id' => $v['member_id'], 'name' => $v['display_name'], 'composing' => $v['composing']], $t['viewers'])];
    if ($internals) {
        $out['policy'] = $t['sla_policy_id'] === null ? null : ['policy_id' => $t['sla_policy_id'], 'name' => $t['policy_name']];
        $out['targets'] = array_map(static fn (array $g): array => ['metric' => $g['metric'], 'target_minutes' => (int) $g['target_minutes'], 'started_at' => json_ts($g['started_at']), 'due_at' => json_ts($g['due_at']),
            'paused' => $g['paused_at'] !== null, 'met_at' => json_ts($g['met_at']), 'breached_at' => json_ts($g['breached_at']), 'state' => $g['state']], $t['targets']);
        $out['time'] = array_map(static fn (array $e): array => ['entry_id' => $e['entry_id'], 'member_id' => $e['member_id'], 'name' => $e['display_name'], 'minutes' => $e['minutes'], 'billable' => $e['billable'], 'note' => $e['note'], 'worked_on' => $e['worked_on']], $t['time']);
        $out['time_total'] = $t['time_total'];
        $out['secure_link'] = ['live' => $t['secure_link']['live'], 'expires_at' => json_ts($t['secure_link']['expires_at']), 'last_used_at' => json_ts($t['secure_link']['last_used_at']), 'rotated' => $t['secure_link']['rotated']];
    }
    return $out;
}

function present_message(array $m): array
{
    return ['message_id' => $m['message_id'], 'kind' => $m['kind'], 'author' => $m['author_member_id'] !== null ? ['member_id' => $m['author_member_id'], 'name' => $m['author_member_name'], 'kind' => $m['author_kind']]
            : ['requester_id' => $m['author_requester_id'], 'name' => $m['author_requester_name'], 'kind' => 'requester'], 'body' => $m['body_text'], 'channel' => $m['channel'], 'mentions' => $m['mentions'],
            'attachments' => $m['attachments'], 'created_at' => json_ts($m['created_at'])];
}

function present_move(array $m): array
{
    return ['status_id' => $m['status_id'], 'key' => $m['key'], 'label' => $m['label'], 'category' => $m['category'], 'move' => $m['move_label'], 'requires' => $m['requires'], 'may_carry' => $m['may_carry'], 'needs_right' => $m['needs_right'], 'may' => $m['may']];
}

/** "due in 2h 10m", "breached 40m ago", "paused", "" */
function sla_due_words(?string $dueAt, ?string $state): string
{
    if ($dueAt === null || $state === null || $state === 'n/a' || $state === 'met') {
        return '';
    }
    if ($state === 'paused') {
        return 'paused';
    }
    $diff = strtotime($dueAt) - time();
    $span = static function (int $s): string {
        $s = abs($s);
        if ($s < 3600) {
            return max(1, intdiv($s, 60)) . 'm';
        }
        if ($s < 86400) {
            return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
        }
        return intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h';
    };
    return $diff < 0 ? 'breached ' . $span($diff) . ' ago' : 'due in ' . $span($diff);
}

function excerpt(string $text, int $n = 200): string
{
    $t = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;
}

/** A history row in words: "Marco assigned it to Frontline (agent)". */
function history_sentence(array $r): string
{
    $who = $r['actor_name'] ?? ($r['actor_requester_id'] !== null ? 'The requester' : 'Help Desk');
    if (($r['source'] ?? '') === 'agent') {
        $who .= ' (agent)';
    } elseif (($r['source'] ?? '') === 'cron') {
        $who = 'Help Desk';
    }
    $a = $r['after'] ?? [];
    $b = $r['before'] ?? [];
    switch ($r['action']) {
        case 'ticket.create': return $who . ' filed it' . (!empty($a['on_behalf_of']) ? ' on behalf of someone' : '') . ' (' . ($a['channel'] ?? 'web') . ')';
        case 'ticket.reply': return $who . ' replied' . (isset($a['excerpt']) ? ': “' . excerpt((string) $a['excerpt'], 80) . '”' : '');
        case 'ticket.note': return $who . ' added a note' . (isset($a['excerpt']) ? ': “' . excerpt((string) $a['excerpt'], 80) . '”' : '');
        case 'ticket.transition':
            $s = 'Status ' . ucfirst(str_replace('_', ' ', (string) ($b['status'] ?? '?'))) . ' → ' . ucfirst(str_replace('_', ' ', (string) ($a['status'] ?? '?')));
            if (!empty($a['resolution_code'])) { $s .= ' (' . (RESOLUTION_CODES[$a['resolution_code']] ?? $a['resolution_code']) . ')'; }
            if (!empty($a['hold_reason'])) { $s .= ' (' . (HOLD_REASONS[$a['hold_reason']] ?? $a['hold_reason']) . ')'; }
            if (!empty($a['pending_until'])) { $s .= ' (until ' . format_ts((string) $a['pending_until'], member_timezone(), 'D g:i A') . ')'; }
            return $s . ' — ' . $who . (($a['via'] ?? '') !== '' && $a['via'] !== 'screen' ? ', ' . $a['via'] : '');
        case 'ticket.assign':
            $parts = [];
            if (array_key_exists('assignee_member_id', $a)) { $parts[] = $a['assignee_member_id'] === null ? 'unassigned it' : 'assigned it to ' . ($a['assignee_name'] ?? ('#' . $a['assignee_member_id'])) . (($a['assignee_kind'] ?? '') === 'agent' ? ' (agent)' : ''); }
            if (array_key_exists('queue_id', $a) && ($b['queue_id'] ?? null) !== $a['queue_id']) { $parts[] = 'moved it to ' . ($a['queue_name'] ?? ('queue #' . $a['queue_id'])); }
            return $who . ' ' . ($parts === [] ? 'changed the assignment' : implode(' and ', $parts)) . (($a['via'] ?? 'manual') !== 'manual' ? ' (' . $a['via'] . ')' : '');
        case 'ticket.set_fields': return $who . ' changed ' . implode(', ', array_map(static fn (string $k): string => str_replace('_', ' ', $k), array_keys(array_diff_key($a, ['via' => 1]))));
        case 'ticket.tag': return $who . ' tagged it: ' . implode(', ', (array) ($a['tags'] ?? []));
        case 'ticket.untag': return $who . ' removed a tag: ' . implode(', ', (array) ($a['tags'] ?? []));
        case 'ticket.follow': return $who . ' follows it';
        case 'ticket.unfollow': return $who . ' stopped following it';
        case 'ticket.cc_add': return $who . ' added a CC';
        case 'ticket.cc_remove': return $who . ' removed a CC';
        case 'ticket.link': return $who . ' linked it (' . strtolower(LINK_KINDS[$a['kind'] ?? ''] ?? ($a['kind'] ?? 'related')) . ' ' . ($a['to_number'] ?? '#' . ($a['to_ticket_id'] ?? '?')) . ')';
        case 'ticket.unlink': return $who . ' removed a link';
        case 'ticket.merge': return isset($a['into_ticket_id']) ? $who . ' merged it into ' . ($a['into_number'] ?? ('#' . $a['into_ticket_id'])) : $who . ' merged ' . ($a['from_number'] ?? 'a ticket') . ' into this one';
        case 'ticket.close': return $who . ' closed it' . (!empty($a['reason']) ? ' (' . (RESOLUTION_CODES[$a['reason']] ?? $a['reason']) . ')' : '');
        case 'ticket.reopen': return $who . ' reopened it';
        case 'ticket.confirm': return $who . ' confirmed it is resolved';
        case 'ticket.spam': return $who . ' marked it as spam';
        case 'ticket.log_time': return $who . ' logged ' . (int) ($a['minutes'] ?? 0) . ' minutes';
        case 'ticket.time_delete': return $who . ' removed a time entry';
        case 'ticket.attach': return $who . ' attached ' . ($a['filename'] ?? 'a file');
        case 'ticket.attachment_delete': return $who . ' removed an attachment';
        case 'ticket.secure_link_rotate': return $who . ' rotated the secure link';
        case 'ticket.dispatch': return 'Dispatched to ' . ($a['agent_name'] ?? 'the agent') . ' — ' . ($a['status'] ?? 'sent') . (($a['via'] ?? '') === 'duty' ? ' (its duty picks it up)' : '');
        case 'rule.fire': return 'Rule “' . ($a['rule_name'] ?? '#' . ($a['rule_id'] ?? '?')) . '” fired';
        case 'ticket.sla_warning': return 'SLA warning: ' . str_replace('_', ' ', (string) ($a['metric'] ?? ''));
        case 'ticket.sla_breach': return 'SLA breached: ' . str_replace('_', ' ', (string) ($a['metric'] ?? ''));
        case 'ticket.delete': return $who . ' deleted it';
        default: return $who . ' ' . str_replace(['ticket.', '_', '.'], ['', ' ', ' '], (string) $r['action']);
    }
}

/** Is this history row an internal (notes, dispatches, time, SLA, rules)? A requester-only caller never sees it. */
function history_is_internal(array $r): bool
{
    return in_array($r['action'], ['ticket.note', 'ticket.dispatch', 'ticket.log_time', 'ticket.time_delete', 'ticket.sla_warning', 'ticket.sla_breach', 'rule.fire', 'ticket.secure_link_rotate', 'ticket.spam'], true);
}
