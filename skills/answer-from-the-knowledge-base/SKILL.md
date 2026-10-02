---
name: answer-from-the-knowledge-base
description: The runbook for answering a Help Desk ticket from the knowledge base and the canned responses — find, verify it matches, write the reply, set pending, and hand over when the base has no answer. For the frontline agent's duty or any agent asked to answer a ticket.
kind: runbook
---

# Answer from the knowledge base (a runbook)

1. `get_ticket`, `ticket_conversation`, `ticket_history`, `requester_history` — what exactly is asked, what was already said.
2. `search_articles` with the requester's own words, then with the category's. Read the article; it must answer THIS
   question, not a neighbour of it. `search_canned_responses` for a fitting text.
3. No match, or a match you doubt → `ticket_note` ("No article covers X; tried A, B") and `ticket_assign` to a person. Stop.
4. A match → write the reply per `writing-to-requesters`, naming the article; `ticket_reply` (it may pause for approval —
   say so and stop on `pending_approval`).
5. `ticket_transition` to pending with a note saying what you expect back; when the requester confirms, to solved (`resolved`).
6. The requester disagrees or writes back a second time → `ticket_assign` to a person with a note. Never argue in replies.
