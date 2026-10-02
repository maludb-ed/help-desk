---
name: ticket-lifecycle
description: The life of a Help Desk ticket — status categories, the allowed transitions and what each requires, what pauses the SLA clock, what a requester's reply does, what pauses for approval when an agent acts, and the one order of operations for changing a ticket. Use before any status change, assignment or reply.
---

# The ticket lifecycle

## Categories and moves
```
new ──► open ──► pending ──► open      (the requester replies, or pending_until passes)
 │        │  ▲       │
 │        ▼  │       ▼
 │      on_hold ───► solved ──► closed  (auto-close after the window; an early close needs tickets.manage)
 │                     ▲  │
 └─────────────────────┘  └──► open    (the requester replies within the reopen window, or someone reopens)
closed is terminal: a requester's reply on a closed ticket makes a follow-up ticket.
```
Always ask `ticket_transitions` for the ticket first: it lists only the moves allowed from where it is, each with
`requires` (resolution_code for solved; hold_reason for on_hold) and `may_carry` (pending_until, assignee). A move the
table forbids is refused by the handler with the table's own sentence — repeat it, do not work around it.

## What the clock does
- new and open run every target; pending and on hold pause them; solved and closed end them.
- Support's public reply meets first response and next response; the requester's reply starts next response.
- Changing priority re-aims the open targets; changing queue, category, type or organization may change the policy.

## Order of operations for an agent
1. Read (`get_ticket`, `ticket_conversation`) — 2. decide — 3. one `ticket_set_fields` for every field at once —
4. `ticket_assign` — 5. `ticket_note` with why — 6. `ticket_transition` — 7. `ticket_reply` last, because it pauses.

## What pauses for a person (the kernel's approvals)
`ticket_reply` (a public reply leaves the building), `ticket_merge`, `ticket_close` (an early close), `tickets_bulk_update`,
`ticket_spam`, `ticket_delete`, every settings save. When a call answers `pending_approval`, stop and say so; do not retry.
Notes, fields, tags, links, assignment and transitions other than close never pause.
