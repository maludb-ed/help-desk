---
name: triage-the-queue
description: The runbook for triaging Help Desk's new tickets — read, find duplicates, decide category, type and priority from the vocabulary, route to a queue, note a suggested article, move to open, and report. For the Dispatcher's duty or any agent asked to triage.
kind: runbook
---

# Triage the queue (a runbook)

1. `triage_queue` — oldest first. Nothing there: report "nothing to triage" and stop.
2. Per ticket: `get_ticket`, `ticket_conversation`, `requester_history`, `similar_tickets`.
   - An open twin → `ticket_link` `duplicate_of` + `ticket_note` "Duplicate of HD-…"; leave it for support to merge.
   - A problem it belongs under → `ticket_link` `parent_child`.
3. `get_vocabulary` → category (follow its routing defaults), type, priority:
   urgent = several people stopped or a customer cannot transact; high = one person stopped; normal = everything else; low = a wish.
4. `ticket_set_fields` once; `ticket_add_tags` for the words support will search by.
5. `ticket_assign` to the queue (`find_queues`); to a person only by rule or the queue's default; `agent_workload` before choosing a person.
6. `search_articles` → `ticket_note` "Suggested: <title>" when one plainly answers it.
7. `ticket_transition` new → open.
8. Report: triaged N, duplicates D, left in new L (each with why, no requester details).
