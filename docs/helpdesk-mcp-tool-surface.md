# Help Desk — MCP tool surface

2026-10-03 · **Phase 1, for the checkpoint.** The read tools every question in `docs/01-phase0-plan.md` §3 (Q1–Q44) and
`docs/helpdesk-design.md` is answered by. Writes are never here: they are the action tools the kernel's Actions MCP builds
from `docs/helpdesk-action-manifest.md`.

Conventions (mcp-and-api.md): Python 3 + FastMCP, streamable HTTP at `/mcp`, loopback, one systemd unit each; `db.py` and
`server_common.py` copied from the kernel (already in `mcp/`); every query runs with `app.member_id` set transaction-locally
from the verified bearer, so the `mcp_*` views (db/015) decide the rows. Tool names are snake_case; every tool takes one
`params` object; every list tool takes `limit` (1–100, default 25); every tool carries `readOnlyHint: true`,
`openWorldHint: false`; results are JSON strings. Times are answered in UTC and in the caller's time zone (the mirror's).

Gates are what the view already enforces — the tool only trims. Words used below (db/014, the design §6):

| Word | Means |
|---|---|
| `own` | the caller's own: tickets they asked for, filed, are cc'd on or follow; their own requester row, notes to self, tokens |
| `queue` | `tickets.view_queue` in the ticket's queue (named member, or through the linked department): the queue's tickets, notes, SLA, time, rules |
| `work` | `tickets.work` in the queue (support staff), or `tickets.manage` |
| `all` | `tickets.view_all` (a lead, the admin, the super-admin) |
| `admin` | `settings.manage` |
| `member` | any admitted member (an active mirror row with a capability): the vocabulary, the queues' names, public articles |

**A requester-only caller never gets an internal note, a time entry, an SLA target, a rule or another requester's
details** — the views blank them by right; an agent is a member like a person, so an agent granted only `user` reads as a
requester would, `records_search` included (the search reads the same views). **An external by the secure link is not a
caller here**: PHP serves them from base tables through `hd_secure_link_ticket()`.

Two token shapes, one key: a person's own `mcp_` token (`mcp_resolve_token`), or the tenant's signed run token
(`ACTION_TOKEN_KEY`), for which the server asks the kernel's run-facts call once per run and offers exactly the tools
granted — fail closed; no tool acts when `is_eval`. The kernel's own token reaches `app_roles` alone (no shares in v1).

## Records server — `helpdesk_records_mcp` (role `helpdesk_records_ro`, `MCP_RECORDS_PORT`)

### Roles (kernel db/145)
`app_roles` — no arguments — answers `os.app-roles/1`: the five roles (key, name, description, capability, is_admin,
rights[]) and the fourteen rights, from `mcp_app_roles` (db/004).

### People, organizations, queues
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_members` | Looking up a person or an agent by name — support staff, a lead, an agent — to assign, follow or mention. **Call before `ticket_assign` to a person.** `q` alone answers a plain list (`member_id`, `display_name`) | Q24, Q36 | `mcp_members`, `mcp_queue_members` | `q?`, `kind?` (human, agent), `queue_id?` (members of that queue), `limit` | member (names, kind, roles); no email but one's own |
| `find_requesters` | Who a requester is: by name, email or organization; an external, a member, an agent. **Call before `ticket_create` on behalf of someone.** `q` alone answers a plain list | Q11, Q41 | `mcp_requesters`, `mcp_organizations` | `q?`, `organization_id?`, `kind?`, `limit` | own; `queue`: everyone, with email and phone |
| `requester_history` | What a requester asked before: their tickets (newest first) with status and outcome, their organization, how many open | Q11 | `mcp_requesters`, `mcp_tickets`, `mcp_satisfaction_ratings` | `requester_id` or `email`, `limit` | own; `queue` |
| `find_organizations` | The customer companies: by name or domain, with tier, SLA override, domains, open ticket count; or one in full | Q5, Q14, Q29 | `mcp_organizations`, `mcp_tickets` | `q?`, `organization_id?`, `limit` | `queue`; a member of the organization sees their own |
| `find_queues` | The queues, each with its department, assignment method, default assignee (a person or an agent), schedule and policy, open count, whether the caller holds it and leads it. **Call before `ticket_assign` to a queue** | Q8, Q36 | `mcp_queues`, `mcp_queue_members` | `q?`, `mine?`, `include_members?` | member (names and counts); `queue` (members) |
| `agent_workload` | Who holds how many open tickets per queue, by priority and SLA state, against their capacity and availability — who is overloaded, who has room | Q24 | `mcp_tickets`, `mcp_queue_members`, `mcp_members` | `queue_id?`, `include_agents?` (OS agents, default true), `limit` | `queue`; `all` across queues |

### The vocabulary
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `get_vocabulary` | Every legal value at once: statuses with category and whether the clock runs, the transitions table, types, categories with path and routing defaults, tags, custom field definitions, priorities, hold reasons, resolution codes, channels. **Call before setting any field**; `q` alone resolves a status, category or type by name | Q17, Q43 | `mcp_ticket_statuses`, `mcp_status_transitions`, `mcp_ticket_types`, `mcp_categories`, `mcp_tags`, `mcp_custom_field_defs` | `q?`, `part?` (statuses, transitions, types, categories, tags, fields) | member (requester-visible categories and fields); `queue` (all) |
| `ticket_transitions` | What THIS ticket may move to from where it is, each with `requires` (resolution_code, hold_reason), `may_carry` (pending_until, assignee) and the right it needs — and whether the caller may make each move. **Call before `ticket_transition`** | Q18, Q43 | `mcp_tickets`, `mcp_ticket_statuses`, `mcp_status_transitions`, `hd_transition()` | `ticket_id` | sees the ticket; `may` reflects `work` |

### Tickets
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `find_tickets` | Any list of tickets by words, number, status category or status, queue, assignee (an id, `me`, `none`), requester, organization, priority, type, category, tag, needs-reply, SLA state, channel, dates. Each row: number, subject, requester, queue, assignee (person or agent), status, priority, `needs_reply`, `sla_state`, next due, tags, last activity. **`q` alone answers a plain list** (`ticket_id`, `label` = "HD-1042 — subject") for the resolver | Q1, Q5, Q7, Q8, Q16, Q30 | `mcp_tickets` | `q?`, `status_category?[]`, `status?`, `queue_id?`, `assignee?`, `requester_id?`, `organization_id?`, `priority?[]`, `type_id?`, `category_id?`, `tag?`, `needs_reply?`, `sla_state?`, `channel?`, `created_from?`, `created_to?`, `solved_from?`, `solved_to?`, `reopened?`, `sort?`, `limit` | own; `queue`; `all` |
| `get_ticket` | One ticket in full: every field, requester with organization, assignee, status with category, SLA targets with state and due (queue holders), tags, custom values, followers and CCs, links (parent, children, duplicates, follow-ups), time logged (queue), the last message's excerpt, counts, who is viewing now | Q2, Q3, Q10, Q41 | `mcp_tickets`, `mcp_requesters`, `mcp_sla_targets`, `mcp_ticket_followers`, `mcp_ticket_links`, `mcp_time_entries`, `mcp_ticket_viewers`, `mcp_article_links` | `ticket_id` or `number` | sees the ticket; internals: `queue` |
| `ticket_conversation` | The messages of a ticket in order — public replies to whoever sees it, internal notes to the queue — each with author (person, agent or requester), channel, attachments (name, size, type) | Q2, Q10 | `mcp_messages`, `mcp_attachments` | `ticket_id`, `kind?` (public_reply, internal_note), `since?`, `limit` (default 50) | sees the ticket; notes: `queue` |
| `ticket_viewers` | Who has the ticket open right now and whether they are typing (collision warning) | Q19 | `mcp_ticket_viewers` | `ticket_id` | `queue` |
| `my_queue` | What the caller should do next: tickets assigned to them that are open, needs-reply first, then by SLA due; then unassigned tickets of their queues; counts at the top. **An agent's duty starts here** | Q7, Q8 | `mcp_tickets` | `include_unassigned?` (default true), `limit` | own assignment; `queue` for the unassigned |
| `triage_queue` | Every ticket still in the `new` category, oldest first, with requester, organization, channel, the first message's excerpt and likely duplicates | Q8 | `mcp_tickets`, `mcp_messages` | `queue_id?`, `limit` | `queue` |
| `sla_at_risk` | Tickets whose SLA is breached or at risk, soonest due first — per queue, per assignee or team-wide — with the metric, due time and minutes left | Q9, Q25 | `mcp_tickets`, `mcp_sla_targets` | `queue_id?`, `assignee?` (`me` or an id), `state?` (at_risk, breached, both), `within_minutes?`, `limit` | `queue`; `all` |
| `stale_tickets` | Open tickets with no activity for N days, oldest first, with the last actor | Q28 | `mcp_tickets`, `mcp_activity_log` | `days` (default 7), `queue_id?`, `limit` | `queue`; `all` |
| `similar_tickets` | Tickets like this one — same requester or organization, matching words in the subject, same category — open ones first (duplicate and problem detection) | Q12 | `mcp_tickets` | `ticket_id` or `text`, `open_only?`, `limit` | `queue` |
| `tickets_changed_since` | The cheap delta for a person's own tool: tickets whose `updated_at` passed a timestamp, with what changed last | Q42 | `mcp_tickets`, `mcp_activity_log` | `since`, `queue_id?`, `limit` | own; `queue`; `all` |
| `ticket_counts` | Counts grouped by status, status category, priority, queue, assignee, type, category, organization, channel or SLA state, for a period — the dashboard's numbers | Q15, Q23, Q29 | `mcp_tickets` | `group_by` (one of those), `queue_id?`, `assignee?`, `period?` (today, week, month, from/to), `open_only?` | own counts; `queue`; `all` |
| `backlog_aging` | Open tickets bucketed by age (today, 1–3 days, 4–7, 8–30, older), per queue and priority | Q23 | `mcp_tickets` | `queue_id?` | `queue`; `all` |
| `board_snapshot` | A saved view rendered as the board renders it: the columns (status by default; priority, assignee, queue or type) in order with WIP limit and count, the cards in rank order with SLA strip, needs-reply, assignee, tags | Q23 | `mcp_saved_views`, `mcp_tickets`, `mcp_ticket_statuses` | `view_id` or `system_key` (team_board), `swimlane?` | the view's audience; rows as `find_tickets` |
| `find_views` | The saved views and boards the caller may open: the standard ones, their own, their queues', with layout and filters. **Call before `board_move_card`** | Q23 | `mcp_saved_views` | `q?`, `layout?`, `limit` | member |

### Working faster, knowledge, service levels
| Tool | Call it when | Answers | Reads | Key params | Gate |
|---|---|---|---|---|---|
| `search_canned_responses` | A canned response that fits: by words in title or body, folder, scope; the text with its placeholders and side-effects. **Call before `canned_apply`** | Q13 | `mcp_canned_responses` | `q?`, `folder?`, `scope?`, `limit` | `work` |
| `search_articles` | Knowledge base articles by words, section, tag, visibility; a published article in full by id or slug; the sections. **Call before replying** | Q4, Q13 | `mcp_articles`, `mcp_kb_sections` | `q?`, `article_id?` or `slug?`, `section_id?`, `visibility?`, `status?` (kb.edit: drafts), `limit` | member (public); `kb.read` (internal); `kb.edit` (drafts) |
| `find_sla_policies` | The SLA policies in match order with their conditions, per-priority targets and warning threshold, and the business-hours schedules with intervals and holidays | Q14, Q36 | `mcp_sla_policies`, `mcp_business_hours` | `q?`, `policy_id?` | `queue` |
| `find_rules` | The automation rules — kind, event, conditions, actions, active, run count, last run — which fire most and which never | Q34, Q36, Q37 | `mcp_automation_rules`, `mcp_rule_runs` | `q?`, `kind?`, `active?`, `with_stats?` (period), `limit` | `queue` |
| `rule_runs` | What a rule did, or every rule that touched a ticket: outcome and detail per run | Q34 | `mcp_rule_runs` | `rule_id?` or `ticket_id?`, `outcome?`, `since?`, `limit` | `queue` |
| `metrics_summary` | The period's numbers against the one before: volume, first-response and resolution medians, SLA attainment per metric, reopen rate, CSAT, by queue | Q26 | `mcp_tickets`, `mcp_sla_targets`, `mcp_satisfaction_ratings` | `period` (week, month, from/to), `compare?`, `queue_id?` | `reports.view`; a queue's holder for their queue |
| `csat_summary` | The CSAT trend and the ratings themselves — bad ones with their reason and comment | Q27 | `mcp_satisfaction_ratings`, `mcp_tickets` | `period?`, `queue_id?`, `score?` (bad, good, all), `limit` | `queue`; own rating |
| `time_entries` | Time logged on a ticket, or by a person over a period, billable split | Q10 | `mcp_time_entries` | `ticket_id?` or `member_id?`, `from?`, `to?`, `limit` | own; `queue` |
| `agent_dispatches` | What was handed to which agent for a ticket — when, by whom, how (chat, wake, duty), the run and what came back | Q33 | `mcp_agent_dispatches` | `ticket_id?` or `agent_member_id?`, `since?`, `limit` | own (the agent); `queue` |
| `find_mailboxes` | The queues' mailboxes and the inbound mail that failed, bounced or was ignored, with the error | Q36, Q40 | `mcp_mailboxes`, `mcp_inbound_email` | `failures_only?`, `since?`, `limit` | `admin` |
| `my_notifications` | The caller's own in-app notifications, unread first | — | `mcp_notifications` | `unread_only?`, `limit` | own |
| `my_tokens` | The caller's own MCP tokens: label, scope, last used, expiry — never the value | Q38 | `mcp_my_tokens` | — | own |
| `records_search` | The long tail: one SELECT over the `mcp_*` views (the docstring lists every view), 5 s, 200 rows; refuses writes, a second statement, `set_config`, `current_setting`, `mcp_admit_agent`, `hd_secure_link_*`, `hd_requester_for_*`, `mcp_resolve_token`, `pg_*`, `dblink`, large objects | Q-long-tail | every `mcp_*` view | `sql`, `limit` | the views' own rules |

**Resolve mode.** The kernel's entity resolver (`mcp/application_actions.py`) sends only `q` and reads a plain list of
rows carrying the id and label field: `find_tickets`, `find_members`, `find_requesters`, `find_organizations`,
`find_queues`, `get_vocabulary` (statuses, categories, types by name), `search_canned_responses`, `search_articles`,
`find_views` answer that shape when given `q` alone. Names match at the start of a word.

## Activity server — `helpdesk_activity_mcp` (role `helpdesk_activity_ro`, `MCP_ACTIVITY_PORT`)

One view, `mcp_activity_log` (own rows; the queue's rows with `view_queue`; everything with `view_all`), plus names from
`mcp_members` and ticket facts from `mcp_tickets`, `mcp_queues`.

| Tool | Call it when | Answers | Key params | Gate |
|---|---|---|---|---|
| `ticket_history` | A ticket's timeline in words and facts: created, replied, noted, status from → to with the code, assigned old → new, priority old → new, tags, links, merges, the rule that fired, who was dispatched, SLA warnings — newest or oldest first | Q6, Q10, Q21, Q31, Q34 | `ticket_id` or `number`, `order?`, `limit` (default 100) | sees the ticket; internals: `queue` |
| `actor_timeline` | What a person, an agent or a requester did over a period — including which tickets they looked at and did not act on (`screen.view` with a ticket) — and, for `actor_kind = agent`, what every agent did yesterday | Q20, Q22, Q32, Q33, Q44 | `member_id?` (default: the caller) or `actor_kind?` (human, agent), `from?`, `to?`, `action?`, `ticket_id?`, `limit` | own; others: `queue` for the queue's rows, `all` |
| `who_touched` | Who last touched a ticket and what they changed; or everyone who touched it, with the first and last time | Q21, Q31 | `ticket_id`, `limit` | sees the ticket |
| `queue_activity` | A queue's activity feed for a period: the actions, by kind and by person, with counts (the lead's "what happened in IT this week") | Q23, Q33 | `queue_id`, `from?`, `to?`, `action?`, `limit` | `queue` |
| `config_change_log` | What configuration changed in a period and by whom: queues, statuses, transitions, policies, hours, fields, rules, mailboxes, settings — before and after | Q35, Q39 | `from?`, `to?`, `entity_type?`, `limit` | `admin`; `queue` for queue rows |
| `activity_search` | The long tail: one SELECT over `mcp_activity_log` (and the three fact views), 5 s, 200 rows, the same refusals as `records_search` | — | `sql`, `limit` | the view's own rule |

## Coverage of the question inventory (Q1–Q44 → tools)

1 `find_tickets(requester=me)` · 2 `get_ticket` + `ticket_conversation` · 3 `get_ticket` (next due) · 4 `search_articles` ·
5 `find_tickets(organization_id)` · 6 `ticket_history` · 7 `my_queue` · 8 `my_queue` (unassigned) + `triage_queue` · 9 `sla_at_risk(assignee=me)` ·
10 `get_ticket` + `ticket_conversation` + `ticket_history` + `time_entries` · 11 `find_requesters` + `requester_history` · 12 `similar_tickets` ·
13 `search_canned_responses` + `search_articles` · 14 `find_organizations` + `find_sla_policies` · 15 `ticket_counts(assignee=me)` · 16 `find_tickets(assignee=me, solved_from/to)` ·
17 `get_vocabulary` · 18 `ticket_transitions` · 19 `ticket_viewers` · 20 `actor_timeline(me, today)` · 21 `who_touched` · 22 `actor_timeline(me, action=screen.view)` ·
23 `ticket_counts` + `backlog_aging` + `board_snapshot` · 24 `agent_workload` · 25 `sla_at_risk(queue_id)` · 26 `metrics_summary` · 27 `csat_summary` · 28 `stale_tickets` ·
29 `ticket_counts(group_by=category|organization)` · 30 `find_tickets(reopened)` · 31 `ticket_history` / `who_touched` · 32 `actor_timeline(member, ticket)` ·
33 `actor_timeline(actor_kind=agent)` + `agent_dispatches` · 34 `rule_runs` + `ticket_history` · 35 `config_change_log` · 36 `find_queues` + `find_members` + `find_sla_policies` + `find_rules` + `find_mailboxes` ·
37 `find_rules(with_stats)` · 38 `my_tokens` · 39 `config_change_log` · 40 `find_mailboxes(failures_only)` · 41 `get_ticket` · 42 `tickets_changed_since` · 43 `get_vocabulary` + `ticket_transitions` ·
44 `actor_timeline(me)` · the long tail: `records_search`, `activity_search`; the kernel: `app_roles`.

## What the log must carry for the activity tools (the manifest's log-payload rules)

Every ticket-scoped row carries **`ticket_id`** and **`queue_id`**. `ticket.create` — `after.number`, `after.requester_id`,
`after.queue_id`, `after.priority`, `after.channel`, `after.on_behalf_of` · `ticket.reply` / `ticket.note` — `after.message_id`,
`after.kind`, `after.excerpt` (200 chars), `after.attachments` (count), `after.recipients` (count, a reply) · `ticket.transition` —
`before.status` / `after.status` (keys), `after.category`, `after.resolution_code`, `after.hold_reason`, `after.pending_until`,
`after.via` (screen, board, triage, rule, requester, auto) · `ticket.assign` — `before`/`after.assignee_member_id` (+ names and kind),
`before`/`after.queue_id`, `after.via` (manual, round_robin, least_loaded, default, rule, triage) · `ticket.set_fields` —
`before`/`after` of the changed fields only (priority, type_id, category_id, due_at, custom values by key) · `ticket.tag` / `.untag` —
`after.tags[]` · `ticket.link` / `.unlink` — `after.kind`, `after.to_ticket_id` · `ticket.merge` — `after.into_ticket_id`,
`after.messages_moved` · `ticket.close` / `.reopen` / `.spam` / `.delete` — `after.reason`, `after.via` · `ticket.dispatch` —
`after.agent_member_id`, `after.via`, `after.run_id`, `after.status` · `ticket.sla_warning` / `.sla_breach` — `after.metric`,
`after.due_at` · `rule.fire` — `after.rule_id`, `after.outcome`, `after.actions[]` · `canned.apply` — `after.canned_id`, `after.side_effects` ·
`board.move` — `before`/`after.status` or the column field, `after.position` · `requester.create` / `.merge` / `.update` —
`after.kind`, `after.into_requester_id`, changed fields (never a note's text) · `mail.inbound` — `after.mailbox_id`, `after.message_id`,
`after.outcome`, `after.ticket_id` · `mail.outbound` — `after.to` (count), `after.template` · `rating.submit` — `after.score`,
`after.bad_reason` · `survey.send` — `after.requester_id` · `*.save` of configuration — `before`/`after` of the changed fields ·
`screen.view` on a ticket page — `after.ticket_id` (Q22, Q32). **A message body, a secure token, a token value, an email address of
another requester is never in a payload.**

## Entity resolution for the action tools (the registry's `resolve` block — `deploy/kernel-registry-helpdesk.json`)

| Entity param | Tool | query param | id field | label field |
|---|---|---|---|---|
| `ticket`, `parent`, `duplicate_of`, `merge_into`, `other_ticket` | `find_tickets` | `q` (a number or words) | `ticket_id` | `label` |
| `queue` | `find_queues` | `q` (name) | `queue_id` | `name` |
| `assignee`, `member`, `follower`, `on_behalf_of`, `lead` | `find_members` | `q` (name) | `member_id` | `display_name` |
| `requester`, `cc` | `find_requesters` | `q` (name or email) | `requester_id` | `display_name` |
| `organization` | `find_organizations` | `q` (name or domain) | `organization_id` | `name` |
| `status` | `get_vocabulary` (statuses) | `q` (label) | `status_id` | `label` |
| `category` | `get_vocabulary` (categories) | `q` (name) | `category_id` | `path` |
| `type` | `get_vocabulary` (types) | `q` (label) | `type_id` | `label` |
| `canned` | `search_canned_responses` | `q` (title) | `canned_id` | `title` |
| `article` | `search_articles` | `q` (title) | `article_id` | `title` |
| `view` | `find_views` | `q` (name) | `view_id` | `name` |
| `policy` | `find_sla_policies` | `q` (name) | `policy_id` | `name` |
| `rule` | `find_rules` | `q` (name) | `rule_id` | `name` |
| `mailbox` | `find_mailboxes` | `q` (address) | `mailbox_id` | `address` |

`find_members`, `find_requesters`, `find_organizations`, `find_queues` are this application's directory tools (as HR's
`find_people`), on the records server — so an action's `assignee` or `requester` resolves without leaving it. A ticket is
addressed by its number when someone types ("HD-1042"), and by id in every table.

## Size
36 records tools (`app_roles` included), 6 activity tools, one guarded search each.
