<?php
declare(strict_types=1);

/** Who is told of what (slice 7): the table every slice reads. kind → [who, the person's words (NOTICE_KINDS), the subject line's verb]. */
function notify_kinds(): array
{
    return ['created' => ['the requester (+ CCs)', 'A ticket I asked for is opened', 'opened'], 'replied' => ['the requester + CCs, or the assignee', 'A requester replies on a ticket I hold', 'replied'],
            'noted' => ['the members named in a note', 'A note is added on a ticket I hold', 'noted'], 'mentioned' => ['the members named', 'Someone mentions me', 'mentioned you'], 'assigned' => ['the new assignee', 'A ticket is assigned to me', 'assigned to you'],
            'cc_added' => ['the person copied', 'I am added as a CC', 'copied you'], 'new_in_queue' => ["the queue's leads", 'A new ticket lands in my queue', 'landed in your queue'], 'followed_changed' => ['followers', 'A ticket I follow changes', 'changed'],
            'sla_warning' => ["the assignee and the queue's leads", 'A response or resolution is about to be late', 'is about to be late'], 'sla_breached' => ["the assignee and the queue's leads", 'A response or resolution is late', 'is late'],
            'pending_elapsed' => ['the assignee', "A pending ticket's wait runs out", 'waited long enough'], 'rated' => ['the assignee', 'A requester rates a ticket I worked', 'was rated'], 'escalated' => ['the person an agent handed it to', 'A ticket is escalated', 'was handed to you'],
            'survey' => ['the requester', 'A survey answer comes in', 'how did we do?']];
}
