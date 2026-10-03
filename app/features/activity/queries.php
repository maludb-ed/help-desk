<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log: their own rows, their queues' rows with view_queue, everything with view_all, their tickets'). */
const ACTIVITY_PAGE = 50;

/** Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days), ticket. */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    if (($filters['ticket'] ?? null) !== null) {
        $where[] = 'ticket_id = :ticket';
        $args['ticket'] = (int) $filters['ticket'];
    } elseif ($filters['own'] ?? true) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_requester_id, source, action, entity_type, entity_id, ticket_id, queue_id, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** One line for a row, in words: "Priya Shah signed on". Falls back to the event name. Later slices add their events. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? ($r['actor_requester_id'] !== null ? 'A requester' : 'Help Desk');
    $words = [
        'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'directory.sync' => 'refreshed the directory',
        'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'assistant.ask' => 'asked the assistant',
        'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications',
        'queue.create' => 'added a queue', 'queue.update' => 'changed a queue', 'queue.archive' => 'archived a queue', 'queue.member_add' => 'added someone to a queue',
        'queue.member_remove' => 'removed someone from a queue', 'queue.presence' => 'changed their availability', 'organization.create' => 'added a customer company',
        'organization.update' => 'changed a customer company', 'organization.archive' => 'archived a customer company', 'requester.create' => 'added a requester',
        'requester.update' => 'changed a requester', 'requester.email_add' => 'added an address to a requester', 'requester.email_remove' => 'removed an address from a requester',
        'requester.merge' => 'merged two requesters', 'sla_policy.create' => 'added an SLA policy', 'sla_policy.update' => 'changed an SLA policy', 'sla_policy.archive' => 'archived an SLA policy',
        'business_hours.save' => 'changed a business-hours schedule', 'business_hours.holiday_add' => 'added a holiday', 'business_hours.holiday_remove' => 'removed a holiday',
        'settings.save' => 'changed Help Desk\'s settings', 'ticket.assign' => 'moved a ticket', 'status.save' => 'changed a status', 'status.archive' => 'archived a status',
        'transition.save' => 'changed which moves are allowed', 'type.save' => 'changed a ticket type', 'type.archive' => 'archived a ticket type', 'category.save' => 'changed a category',
        'category.archive' => 'archived a category', 'tag.save' => 'changed a tag', 'tag.delete' => 'deleted a tag', 'field.save' => 'changed a custom field', 'field.archive' => 'archived a custom field',
    ];
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    return $who . ' ' . ($words[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action']));
}
