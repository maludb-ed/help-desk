---
name: end-of-day-review
description: The runbook for a lead's end-of-day look at Help Desk — what is breached or at risk, unassigned, stale, waiting on us, and what to escalate — answered from the tools and written up in one short note. For the expert when a lead asks "how are we doing", or any agent given a review duty.
kind: runbook
---

# End-of-day review (a runbook)

1. `sla_at_risk` (breached first, then at risk) — per queue, who holds each, how long until due.
2. `ticket_counts` by status and by assignee; `agent_workload` — who is over capacity, who has room.
3. `triage_queue` — anything still new at the end of the day is a routing failure: say so.
4. `stale_tickets` (7 days) — list them with the last actor.
5. Needs a reply (`my_queue` for the lead, `find_tickets` with needs_reply across queues) — the requester is waiting on us.
6. Write ONE note: counts, the five worst tickets with number and one line each, the people to talk to, what to escalate.
   No requester details beyond the ticket number and subject. Change nothing; a review is a reading.
