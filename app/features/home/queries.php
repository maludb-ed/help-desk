<?php
declare(strict_types=1);

/**
 * The dashboard's reads (screen `dashboard`) — through the mcp_* views only. Their shape is fixed in Phase 2 and they
 * answer nothing until slice 3 (tickets core) fills them from mcp_tickets: `needs me` = my open tickets and the unassigned
 * of my queues, by SLA risk; `my tickets` = the ones I asked for, by last change.
 */

/** What needs the member (support staff): [{ticket_id, number, subject, status, priority, due_at, queue_name, reason}]. */
function home_needs_me(PDO $pdo, int $memberId): array
{
    return [];
}

/** The member's own tickets, newest change first: [{ticket_id, number, subject, status, updated_at}]. */
function home_my_tickets(PDO $pdo, int $memberId, int $limit = 5): array
{
    return [];
}
