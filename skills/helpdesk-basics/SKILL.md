---
name: helpdesk-basics
description: How Help Desk (the business's ticketing system) is organised and which tool answers which question — tickets, requesters of three kinds, queues, statuses within categories and the allowed transitions, priority, the SLA clock, public replies versus internal notes, who sees what. Use before any question or action about a ticket, a queue or a requester.
---

# Help Desk basics

## The shape
- A **ticket** is one request: number (`HD-1042`), subject, requester, queue, assignee, status, priority, type, category, tags, custom fields.
- A **requester** is who it is for: an OS member (an employee or an invited external), an **external** known only by email (they read and
  reply through a secure link mailed to them, and by mail), or an **agent** that filed it.
- A **queue** holds tickets and the people who work them; a queue may be linked to a department, whose members are in it.
- **Status** sits inside a category: new → open → pending (waiting on the requester) / on hold → solved → closed. The
  **transitions table** says which moves are allowed and what each needs: solving needs a resolution code, on hold a hold
  reason, pending may carry a wake-up time. Closed is terminal; a requester's reply on a solved ticket within the window reopens it.
- **Priority**: low, normal, high, urgent. **SLA**: a clock per metric (first response, next response, resolution), from
  the policy that matched; it pauses in pending and on hold; `sla_state` is on_track / at_risk / breached / met / paused.
- The **conversation**: a **public reply** is mailed to the requester and the CCs; an **internal note** stays in the queue.
- A ticket's **history** is its activity rows (`ticket_history`), not a separate log.

## Which tool
| Question | Tool |
|---|---|
| A ticket, by number or words | `find_tickets`, `get_ticket` |
| What was said, what happened | `ticket_conversation`, `ticket_history` |
| What needs me / my queue | `my_queue` |
| What is new (unrouted) | `triage_queue` |
| At risk or breached | `sla_at_risk` |
| Stuck, no activity for N days | `stale_tickets` |
| Counts by status, priority, queue, assignee, category, organization | `ticket_counts` |
| Who is the requester, what did they ask before | `find_requesters`, `requester_history` |
| Seen this before | `similar_tickets` |
| Something to send | `search_canned_responses`, `search_articles` |
| Legal values and legal moves | `get_vocabulary`, `ticket_transitions` |
| Who is loaded | `agent_workload` |
| The board as JSON | `board_snapshot` |

## Who sees what
A requester sees their own tickets (and their organization's when it allows). Support staff and collaborators see every
ticket of their queues, notes included. A lead sees everything. Nobody sees another person's email or phone except
support staff on the requester card. Agents follow the same rule as people.
