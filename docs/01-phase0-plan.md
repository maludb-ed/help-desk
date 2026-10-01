# Help Desk — Phase 0 plan (memory-first)

Status: **draft for user checkpoint** (approve the memory model and the slice order before Phase 1).
Date: 2026-10-01
Method: htmx-php-builder plugin — memory-first-planning → new-app phases 0–4.
Research basis: [research/third-party-help-desk-systems.md](research/third-party-help-desk-systems.md). Section 9 lists exactly what the research changed in this plan.

The application is a help desk ticketing system used by **humans and AI agents alike**. Every feature below is a pre-packaged answer to a question in section 3; every question maps to an MCP tool in Phase 1; every screen and action lands in the action manifest. A requirement that does not trace back to a question is cut.

---

## 1. Who asks? (actors)

| Actor | Who they are | How they reach the system |
|---|---|---|
| **Requester** | A customer or employee with a problem. Usually belongs to an Organization. Many never log in; they live in email. | Email, requester portal, web form, API/MCP (when an AI acts for them) |
| **Agent** | A support person who works tickets. Member of one or more Teams. | Agent app (HTMX), command bar, email replies |
| **Light agent** | An internal colleague (engineer, account manager) who can read tickets and add internal notes but never owns a ticket or replies publicly. | Agent app, restricted |
| **Team lead / supervisor** | An agent who also watches queues, workload, SLA risk, and quality. | Agent app, dashboards, AMA page |
| **Admin** | Configures teams, people, SLA policies, business hours, automation rules, canned responses, custom fields, mailboxes, webhooks, access tokens. | Agent app settings area |
| **AI agent (external)** | A client's own AI tool or an autonomous support agent: triages, drafts, replies, resolves, or files tickets from monitoring. Has its own Person record of kind `ai_agent` and a bearer token. | The record/activity MCP servers (read) and the actions MCP exposure (write) — section 7 |
| **In-app assistant** | The unified Claude Agent SDK service behind the AMA page and the command bar. Acts *as the signed-in user*. | Localhost actions MCP + the two read servers |
| **System / automation** | SLA timers, triggers, time-based automations, email ingestion, auto-close, webhook delivery. Never a person, always logged as an actor. | Internal jobs |

Tenancy note (SaaS Plus+): the *tenant* is the company operating the help desk, which gets a dedicated database. "Organizations" below are that tenant's **customers**, a domain entity — not tenants.

---

## 2. What must be remembered? (memory model)

Named in domain language. Translation to SQL happens in Phase 1.

### 2.1 People and groups

- **Organization** — a customer company. Remembers: name, email domains (for auto-association of requesters), notes, tags, custom field values, SLA policy override, support tier, whether its members may see each other's tickets, active flag. Has many People and Tickets.
- **Person** — anyone the system knows. Remembers: name, primary email, other emails, phone, **kind** (`human` | `ai_agent` | `system`), **role** (`requester` | `light_agent` | `agent` | `admin`), organization (requesters), timezone, locale, signature, avatar, active, last seen. Auth facts (nullable `password_hash`, Google identity, TOTP secret, recovery codes, lockout counters) ship for every Person; an email-only requester simply has no credentials. Belongs to Teams; is assigned Tickets; authors Messages; owns Saved Views; holds Access Tokens.
- **Team** — a group of agents sharing a queue. Remembers: name, description, inbound mailbox, business-hours schedule, assignment method (`manual` | `round_robin` | `least_loaded`), default SLA policy, active. Members carry an `is_lead` flag.
- **Agent presence** — per agent: availability (`online` | `away` | `offline`), capacity (max concurrent open tickets), updated at. Used by load-based assignment.

### 2.2 The ticket

- **Ticket** — one request. Remembers: human number (`HD-1042`), subject, requester, submitter (who created it; differs when an agent files on someone's behalf), organization (derived from requester, overridable), assignee, team, **status**, **priority**, **type**, **category**, **channel** of origin (`email` | `portal` | `web_form` | `api` | `mcp` | `chat` | `phone` | `assistant`), tags, custom field values, due date (optional, manual), **pending until** (a snooze/reminder instant: when it passes, the ticket returns to `open`), **hold reason** (`awaiting_requester` | `awaiting_third_party` | `awaiting_internal` | `awaiting_problem`), resolution code (`resolved` | `workaround` | `no_action` | `duplicate` | `spam` | `cannot_reproduce`), parent ticket, merged-into ticket, external id (for monitoring systems and integrations), board position (manual rank within a board column), reopen count, and timestamps: created, first agent response, last agent reply, last requester reply, last activity, solved, closed. Derived at read time: **needs reply** (last requester reply is newer than last agent reply) and **SLA risk** (section 2.4).
- **Status** (reference data, admin-editable, seeded with six) — label, **category** (`new` | `open` | `pending` | `on_hold` | `solved` | `closed`), color from the locked vocabulary, sort order (= board column order), default flag, shown on board, and whether the SLA clock `runs` or `pauses` in it. Category governs behaviour; label is cosmetic. Admins may add custom statuses inside the `open`, `pending`, `on_hold`, and `solved` categories only (never `new` or `closed`). This is the Zendesk / Linear "custom statuses within fixed categories" model.
- **Allowed transitions** — a table of (from category → to category) pairs with the fields each transition requires (e.g. → `solved` requires a resolution code; → `pending` may carry a pending-until; → `on_hold` requires a hold reason). The same table drives the UI, the board drop rules, and the MCP `ticket_transitions` tool.
- **Priority** — fixed four: `low`, `normal`, `high`, `urgent`. SLA targets key off it.
- **Ticket Type** (reference) — seeded `question`, `incident`, `problem`, `task`; admin may add. A `problem` ticket is the parent of many `incident` tickets; solving the problem can solve its children (automation, Extended).
- **Category** — hierarchical topic (Billing › Refunds). Remembers routing defaults: team, type, priority, SLA policy (the osTicket "help topic" idea).
- **Tag** — slug + label + usage count.
- **Custom Field Definition** — key, label, type (`text` | `number` | `date` | `select` | `multi_select` | `checkbox`), options, applies to (`ticket` | `organization` | `person`), optional restriction to ticket types (per-type forms), required-on-solve, requester-visible, sort. Values live as a validated JSON document on the owning entity.

Status lifecycle (categories):

```
new ──► open ──► pending ──► open      (requester reply or pending-until elapsed: pending → open automatically)
 │        │  ▲       │
 │        ▼  │       ▼
 │      on_hold ───► solved ──► closed  (auto-close N days after solved; manual close allowed)
 │                     ▲  │
 └─────────────────────┘  └──► open    (requester replies or agent reopens within window)

closed is terminal: a requester reply on a closed ticket creates a follow-up ticket linked to it.
Triage shortcuts on a `new` ticket: accept (→ open + assign), decline (→ solved, no_action), duplicate (→ merge), snooze (→ pending with pending-until).
```

### 2.3 The conversation

- **Message** — one entry in a ticket's conversation. Remembers: author, **kind** (`public_reply` | `internal_note`), body (plain text + sanitised HTML), channel, created/edited at, email `Message-ID` / `In-Reply-To` for threading, mentioned People. Has Attachments.
- **Ticket Event** — one structured change on a ticket, append-only: who, when, field, before, after, channel, and the automation rule that caused it (if any). This is the ticket's own audit trail, rendered in the timeline. (The activity log, section 2.8, captures the same facts for cross-cutting activity questions; both are written in the same transaction.)
- **Attachment** — filename, MIME type, size, SHA-256, storage path, uploader. Belongs to a Message or an Article.
- **Follower** — a Person attached to a ticket as `cc` (requester side, receives public replies) or `follower` (agent side, internal notifications only).
- **Ticket Link** — directed relation between tickets: `parent_child`, `related`, `duplicate_of`, `merged_into`, `blocks`, `follow_up_of`.
- **Ticket Viewer** — who has the ticket open right now (person, ticket, last seen). Powers collision warnings ("Maria is replying"). Rows expire; nothing historical lives here (history is in the activity log).
- **Time Entry** — ticket, agent, minutes, billable flag, note, date.
- **Satisfaction Rating** — ticket, requester, score, bad-rating reason, comment, survey sent at, responded at, one-time token.

### 2.4 Service levels and time

- **Business Hours Schedule** — name, timezone, weekly intervals, holiday dates. Teams reference one.
- **SLA Policy** — name, ordered match conditions (organization, team, type, priority, tags), per-priority targets in minutes for **first response**, **next response**, and **resolution**, whether targets count business hours or calendar hours, warning threshold (percent of target elapsed), active flag. First matching policy applies at ticket creation and is re-evaluated when routing fields change.
- **SLA Target** (per ticket, one row per metric) — metric, due at, elapsed seconds, paused at, met at, breached at, policy. The clock pauses in statuses whose category pauses it. Derived **risk state** for display and tools: `on_track` | `at_risk` (past the warning threshold) | `breached` | `met` | `n/a`.

### 2.5 Working faster

- **Canned Response** (macro) — title, body with placeholders (`{{ticket.number}}`, `{{requester.first_name}}`, `{{agent.signature}}`), scope (`personal` | `team` | `global`), owner, team, folder, optional side-effects (set status, priority, assignee, add tags), usage count.
- **Saved View** — name, owner, scope (`personal` | `team` | `global`), filter conditions (JSON, bounded vocabulary), sort, visible columns, sidebar order, and a **layout**: `list` or `board`. A board is a saved view rendered as columns, so "my team's board" and "my team's queue" are the same remembered filter. Board settings on the view: column field (`status` by default; `priority` | `assignee` | `team` | `type` allowed), swimlane field (none | priority | team | assignee | type | organization), WIP limit per column, whether solved cards show and for how many days.
- **Automation Rule** — kind (`trigger` on create/update | `time_based` | `assignment`), conditions and actions as JSON over a bounded vocabulary, order, active, run count, last run. Examples: "incident from a Gold org → priority urgent, team Platform"; "solved for 4 days → closed"; "pending for 7 days with no reply → send reminder"; "SLA at risk → notify team lead".

### 2.6 Self-service and quality

- **Knowledge Base Section** (hierarchical) and **Article** — title, slug, body, status (`draft` | `published` | `archived`), visibility (`public` | `internal`), author, tags, view count, helpful/unhelpful counts, published at.
- **Article Link** — ticket ↔ article with kind `suggested` | `sent` | `created_from`.
- **CSAT Settings** — scale (`good_bad` | `five_point`), question text, send delay after solve, response window in days, bad-rating reason list, which teams/orgs are surveyed.

### 2.7 Channels and access

- **Mailbox** — a team's inbound address, ingestion method (IMAP poll or inbound webhook), signature, active.
- **Inbound Email** — raw stored message (Message-ID, from, to, subject, body, headers, received at), processing status, resulting ticket/message. Nothing is thrown away before it is linked.
- **Outbound Email** — recipient, subject, template, MaluMail message id, delivery status, related ticket/message.
- **Notification** — recipient, kind (`assigned` | `replied` | `mentioned` | `sla_warning` | `sla_breached` | `rated` | `pending_elapsed` | …), ticket, read at, delivered via (`in_app` | `email`).
- **Access Token** — owner Person, label, hashed secret, scopes (`records_read` | `activity_read` | `actions`), expiry, last used, revoked at, **rate limit** (calls per minute) and rolling usage counters. This is how external AI agents and client tools authenticate to the MCP servers.
- **Webhook Subscription** — owner, target URL, secret, subscribed events (`ticket.created` | `ticket.updated` | `ticket.replied` | `ticket.status_changed` | `ticket.assigned` | `ticket.sla_breached` | …), filter (team/org), active. **Webhook Delivery** — subscription, event, payload, attempts, last status, next retry. Lets AI agents and integrations react instead of polling.

### 2.8 Activity memory (the log, from day one)

Every request writes at least one row: **when**, **who** (person id + kind, plus token id when the actor came through MCP/API, plus rule id for automations), **what** (event name from a fixed vocabulary: `screen.entered`, `ticket.created`, `ticket.replied`, `ticket.status_changed`, `ticket.assigned`, `macro.applied`, `board.card_moved`, `mcp.tool_called`, `webhook.delivered`, `auth.login`, …), **where** (screen id / route), **which** (entity type + id, with ticket id denormalised for every ticket-scoped event), **before/after** values, channel, request id, session id, IP, user agent, and for MCP calls the tool name and argument summary. Ingested into MaluDB continuously. It is what answers "what did the AI agent do yesterday?" and "when did Maria start working on HD-1042?".

---

## 3. What do they ask? (question inventory)

**R** = record question (PostgreSQL, records MCP server). **A** = activity question (MaluDB, activity MCP server). The tool column is the *preliminary* name that Phase 1 will finalise; it is here so Phase 1 is mechanical.

### 3.1 Requester

| # | Question | Mem | Preliminary tool |
|---|---|---|---|
| Q1 | What tickets have I raised, and what is each one's status? | R | `list_tickets(requester_id)` |
| Q2 | What is the latest on ticket HD-1042? Has anyone replied? | R | `get_ticket`, `ticket_conversation` |
| Q3 | When should I expect a response? | R | `get_ticket` (next SLA due) |
| Q4 | Is there an article that already answers my question? | R | `search_articles` |
| Q5 | What tickets has my company raised? (when org visibility is on) | R | `list_tickets(organization_id)` |
| Q6 | When did I submit this and when did someone first look at it? | A | `record_history(ticket)` |

### 3.2 Agent

| # | Question | Mem | Preliminary tool |
|---|---|---|---|
| Q7 | What is assigned to me that needs my reply right now? | R | `agent_queue(me, needs_reply)` |
| Q8 | What is unassigned in my team's queue, and what is new in triage? | R | `list_tickets(team_id, unassigned)`, `triage_queue(team_id)` |
| Q9 | Which of my tickets are at risk of breaching SLA, or already have? | R | `sla_at_risk(assignee_id, within_minutes)` |
| Q10 | Show me everything on this ticket: replies, notes, changes, links, time logged. | R | `get_ticket`, `ticket_conversation`, `ticket_timeline` |
| Q11 | Who is this requester, what organization, what have they asked before? | R | `find_person`, `person_ticket_history` |
| Q12 | Have we seen this problem before? Similar tickets? | R | `search_tickets(text)`, `similar_tickets(ticket_id)` |
| Q13 | Is there a canned response or article I can use here? | R | `search_canned_responses`, `search_articles` |
| Q14 | What is this organization's SLA and support tier? | R | `get_organization` |
| Q15 | What are my open counts by status and priority? | R | `ticket_counts(assignee_id, group_by)` |
| Q16 | What did I resolve this week? | R | `list_tickets(assignee_id, solved_between)` |
| Q17 | What are the valid statuses, priorities, types, categories, teams, custom fields right now? | R | `get_vocabulary` |
| Q18 | What can this ticket move to from where it is, and what does each move require? | R | `ticket_transitions(ticket_id)` |
| Q19 | Is someone else looking at or replying to this ticket right now? | R | `ticket_viewers(ticket_id)` |
| Q20 | What have I done today? | A | `actor_timeline(me, today)` |
| Q21 | Who last touched this ticket and what did they change? | A | `record_history(ticket)` |
| Q22 | Which tickets did I look at but not act on? | A | `actor_timeline(me, screen.entered)` |

### 3.3 Team lead / supervisor

| # | Question | Mem | Preliminary tool |
|---|---|---|---|
| Q23 | How is the backlog right now: by status, priority, age, team? (**the kanban board answers this**) | R | `ticket_counts(group_by)`, `backlog_aging`, `board_snapshot(view_id)` |
| Q24 | Who is overloaded and who has capacity? | R | `agent_workload(team_id)` |
| Q25 | Which tickets breach in the next N hours, team-wide? | R | `sla_at_risk(team_id, within_minutes)` |
| Q26 | What are first-response and resolution times this period versus last? | R | `metrics_summary(period, compare)` |
| Q27 | What is the CSAT trend, and which tickets were rated bad and why? | R | `csat_summary`, `list_ratings(score)` |
| Q28 | What is stuck — no activity for N days? | R | `stale_tickets(days)` |
| Q29 | Which categories and organizations generate the most tickets? | R | `ticket_counts(group_by=category\|organization, period)` |
| Q30 | How many tickets reopened this month, and which? | R | `list_tickets(reopened=true, period)` |
| Q31 | Who reassigned HD-1042, when, and was there a note? | A | `record_history(ticket)` |
| Q32 | When did Maria start working on HD-1042 and how long was she in it? | A | `actor_timeline(person, ticket)` |
| Q33 | What did the AI agent do yesterday — which tickets did it touch, what did it change? | A | `actor_timeline(actor_kind=ai_agent)` |
| Q34 | Which automation rules fired on this ticket? | A/R | `record_history(ticket)`, `rule_run_log` |
| Q35 | Who changed the SLA policy last month, from what to what? | A | `record_history(sla_policy)` |

### 3.4 Admin

| # | Question | Mem | Preliminary tool |
|---|---|---|---|
| Q36 | What teams, agents, policies, schedules, rules, mailboxes, webhooks exist and are they active? | R | `list_teams`, `list_people(role)`, `list_sla_policies`, `list_automation_rules`, `list_mailboxes`, `list_webhooks` |
| Q37 | Which rules fire the most, and which never fire? | R | `rule_run_stats` |
| Q38 | Which access tokens exist, who owns them, when were they last used, are any near their rate limit? | R | `list_access_tokens` |
| Q39 | What configuration changed in the last 30 days, by whom? | A | `config_change_log` |
| Q40 | Did inbound email ingestion or webhook delivery fail on anything? | R | `list_inbound_email(status=failed)`, `list_webhook_deliveries(status=failed)` |

### 3.5 External AI agent

Everything in 3.1–3.3 that its role allows, plus:

| # | Question | Mem | Preliminary tool |
|---|---|---|---|
| Q41 | Give me the full structured ticket so I can act on it. | R | `get_ticket(include=all)` |
| Q42 | What changed since my last poll? | R | `tickets_changed_since(timestamp)` |
| Q43 | What can I legally set on a ticket (vocabulary, custom-field schema, allowed transitions)? | R | `get_vocabulary`, `ticket_transitions` |
| Q44 | What have *I* (this token) done, so I can undo or avoid repeating? | A | `actor_timeline(me)` |

### 3.6 Long tail

Both read servers also expose one guarded `records_search` / `activity_search` tool (single validated SELECT, read-only role, row cap, statement timeout) with a schema summary in the description, so questions nobody pre-packaged are still answerable.

---

## 4. What do they *do*? (the action inventory, feeding the action manifest)

Creates and updates execute immediately with undo; destructive actions confirm.

| Actor | Actions |
|---|---|
| Requester | submit ticket, reply, add attachment, confirm resolution (solve), reopen, rate (CSAT), update profile |
| Light agent | internal note, follow / unfollow, mention |
| Agent | create ticket (on behalf), reply, internal note, change status (via allowed transitions, with resolution code / hold reason / pending-until as required), change priority / type / category, assign to self / agent / team, add or remove tags, set custom fields, follow / unfollow, add CC, link / merge / split, triage accept / decline / duplicate / snooze, apply canned response, log time, attach file, send CSAT survey, convert to article, move card on board, bulk update from a list view |
| Supervisor | reassign, bulk reassign, escalate (priority + team), edit team views and boards, set WIP limits, export CSV |
| Admin | CRUD on organizations, people, teams, statuses, transitions, types, categories, tags, custom fields, schedules, SLA policies, canned responses, automation rules, mailboxes, webhooks, access tokens, CSAT settings; invite agent; deactivate person |
| AI agent (external) | the agent set above, scoped by its account's role; plus `create_ticket` with an external id from monitoring |

Every one of these is a PHP endpoint under `public/{feature}/`, and the actions MCP server calls that same endpoint. Nothing writes around PHP.

---

## 5. Features, ordered for Phase 3 vertical slices

Tiering: **Core** ships in the first release; **Extended** follows; **Later** is designed in the Phase 1 schema but not built until asked. Every slice builds against the complete Phase 1 schema — no schema changes mid-stream.

### Phase 2 (before any slice): auth + shell
Login (email/password + Google), TOTP 2FA, reset, the nxl shell, sidebar stubs for every feature below, the command bar, activity logging, empty dashboard.

### Core slices (in order)

| # | Slice | Answers | Notes |
|---|---|---|---|
| 1 | **Organizations** | Q5, Q14, Q29 | **Exemplar slice** — plain CRUD, built by the planning-class model; every later CRUD slice replicates it. |
| 2 | **People** (requesters, light agents, agents, admins, AI-agent accounts) | Q11, Q36 | CRUD + role + organization + team membership; invite agent; AI-agent accounts get their token in slice 14. |
| 3 | **Teams** | Q8, Q24, Q36 | CRUD + members + assignment method + schedule. |
| 4 | **Reference data**: statuses + transitions, ticket types, categories, tags | Q17, Q18 | Templated CRUD slices (Haiku-class) except transitions, which is a matrix editor. |
| 5 | **Business hours + SLA policies** | Q9, Q14, Q25 | Two CRUD slices; the SLA engine itself lands in slice 6. |
| 6 | **Tickets core** | Q1–Q3, Q7–Q12, Q15–Q19, Q21, Q41, Q42 | The bespoke centre: list with filters/sort/search, create, detail with conversation + composer (reply/note, attachments, mentions), inline field changes through allowed transitions (status with resolution code / hold reason / pending-until, priority, type, category, assignee, team, tags, custom fields), followers/CCs, events timeline, SLA target computation and risk display, needs-reply marker, viewer presence, triage accept/decline/snooze, auto number. Planning-class builds it. |
| 7 | **Saved views + standard queues** | Q7, Q8, Q9, Q28 | "My tickets", "Unassigned", "Triage", "Team queue", "At risk", "Stale"; custom views in the sidebar. Built before the board because a board *is* a saved view. |
| 8 | **Kanban board** | Q23 | Section 6. Planning-class. |
| 9 | **Canned responses** | Q13 | CRUD + "insert into composer" + placeholders + side-effects. |
| 10 | **Custom fields** | Q17, Q43 | Definitions CRUD + rendering on ticket/org/person forms + per-type forms + validation. |
| 11 | **Requester portal** | Q1–Q4, Q6 | Submit, my tickets, ticket view + reply, confirm/reopen, profile. Same shell, requester navigation. |
| 12 | **Email channel** | — (intake) | Mailboxes CRUD, inbound ingestion job (IMAP poll by default), threading by Message-ID, reply-above-line parsing, outbound notifications via MaluMail, follow-up on closed. |
| 13 | **Notifications + mentions** | — | In-app bell + email; `@mention` in notes; pending-elapsed and SLA notifications. |
| 14 | **Access tokens + webhooks + MCP settings** | Q36, Q38, Q40, Q44 | Token CRUD with scopes and rate limits; webhook subscriptions + delivery log + retry job; the settings page publishing the MCP URLs. Required before Phase 4. |

### Extended slices

| # | Slice | Answers |
|---|---|---|
| 15 | **Automation**: triggers, time-based automations (auto-close, pending-elapsed, reminders), assignment rules (round-robin / least-loaded with capacity), SLA warning/breach escalations, problem-solves-incidents — plus the job runner | Q34, Q37 |
| 16 | **Knowledge base**: sections, articles, portal search, suggest/send/convert from ticket | Q4, Q13 |
| 17 | **CSAT surveys** with settings (scale, delay, window, bad-rating reasons) | Q27 |
| 18 | **Reporting dashboards**: volume, first response, resolution, SLA attainment, reopen rate, CSAT, agent workload, backlog aging, by team/category/org | Q23–Q30 |
| 19 | **Linked / merged / split tickets, follow-ups, duplicate detection on create** | Q10, Q12, Q30 |
| 20 | **Time tracking** | Q10 |
| 21 | **Bulk actions on list views; export CSV** | — |
| 22 | **AI assist in the agent app**: summarise ticket, draft reply grounded in KB and canned responses, suggest category/priority/team on create — delivered by the unified assistant service through the MCP servers, surfaced as buttons in the composer and the create form | Q10, Q12, Q13 |

### Later (schema designed now, build on request)
Phone/chat channels as intake; web-form embed builder; agent presence + capacity UI; skills-based routing; side conversations; approvals; impact × urgency priority derivation; multi-brand / multiple portals; triage responsibility rotation; AI actions inside automation rules; calendar view; NPS/CES.

### Phase 4: MCP servers + unified assistant
Records server, activity server, actions server (two exposures, section 7), AMA page, command bar wired to the assistant, verify every Q1–Q44 and every action by utterance.

---

## 6. The kanban board (design)

The board is the supervisor's and agent's answer to Q23 ("what does our open work look like right now?") and the fastest way to perform the most common action (change status).

- **A board is a saved view with `layout = board`.** Its filter decides which tickets appear; its board settings decide columns, swimlanes, and limits. Default boards: "Team board" (team filter, status columns) and "My board" (assignee = me). Personal and shared boards both live in the sidebar under Views.
- **Columns** = values of the column field, default **status**: statuses marked "show on board", in status sort order. Default: New · Open · Pending · On hold · Solved (solved shows cards from the last N days, default 3). **Closed is excluded** — the board shows open work. Column header shows count and, when a WIP limit is set, `count / limit` in `danger` when exceeded (soft limit, never blocks).
- **Swimlanes** (optional): none, priority, team, assignee, type, organization. Swimlanes render as stacked boards with a lane header, so the page never scrolls horizontally beyond the column strip.
- **Card**: ticket number + subject (link to detail), requester name with organization in `<small>`, priority badge (locked colours: urgent `danger`, high `warning`, normal `info`, low `secondary`), assignee avatar or "Unassigned", **SLA risk strip** on the card edge (`danger` breached, `warning` at risk, none on track), next SLA due as a countdown chip, a **needs-reply marker** when the requester spoke last, up to three tags, age. Every card is `ticket-card-{id}`.
- **Drag between columns** = status change: `POST /tickets/{id}/status` — the *same* endpoint the detail page uses, so allowed transitions, required fields, SLA clock, triggers, events, and logging are identical. A drop into a status whose transition requires a field (resolution code for `solved`, hold reason for `on_hold`, pending-until for `pending`) expands the card inline to collect it (no modal); cancel snaps the card back. The response returns the re-rendered card; column counts refresh via `HX-Trigger: ticketsChanged`. A drop that the transition table forbids is rejected server-side and the card snaps back with the reason.
- **Drag within a column** = manual rank (`board_position`), persisted by `POST /board/reorder`.
- **Drag-and-drop is a JS island** (SortableJS, the official HTMX sortable pattern), allowed by the tech-stack upgrade rule. **Fallback and mobile**: each card has a "Move to…" select listing only allowed transitions that performs the same POST, so the board is fully usable without drag.
- **Filters** in the page header: assignee, "mine", priority, type, text search, applied on top of the view's filter; the swimlane selector lives there too.
- **Freshness**: the column strip re-fetches on `hx-trigger="every 30s"` and on `ticketsChanged`; SSE upgrade only if the user asks for live multi-user boards.
- **Mobile (375px)**: the column strip scrolls horizontally *inside* `#board-columns` with scroll-snap (the page itself never scrolls sideways); columns are full-width panes; header filters collapse into the page-header offcanvas.
- **Ids**: `board-columns`, `board-column-{status_id}`, `board-column-{status_id}-count`, `ticket-card-{id}`, `board-filter-assignee`, …
- **For AI agents**: `board_snapshot(view_id)` on the records server returns the same columns and cards as JSON; `board_move_card` on the actions server performs the same transition.

---

## 7. Full MCP coverage (the AI-agent requirement)

Per the plugin's mcp-servers and chat-actions skills, the app ships:

1. **`helpdesk_records_mcp`** (client-facing, read-only, PostgreSQL read-only role) — one tool per R question above, plus `records_search`. Discovery tools (`get_vocabulary`, `ticket_transitions`) exist so callers never guess field values.
2. **`helpdesk_activity_mcp`** (client-facing, read-only, MaluDB read-only role) — `actor_timeline`, `record_history`, `config_change_log`, `rule_run_log`, `who_touched`, plus `activity_search`.
3. **`helpdesk_actions_mcp`** — `navigate` + one tool per action in section 4 (`ticket_create`, `ticket_reply`, `ticket_note`, `ticket_transition`, `ticket_assign`, `ticket_set_fields`, `ticket_add_tags`, `ticket_apply_macro`, `ticket_link`, `ticket_merge`, `ticket_log_time`, `tickets_bulk_update`, `board_move_card`, …) + `undo_last`. Every tool POSTs to the app's own PHP endpoint with a signed action token. Status changes go through `ticket_transition`, which validates against the allowed-transitions table, never a free status write. Merge, bulk update, and deactivation carry `destructiveHint` and require an explicit `confirm: true` argument.

**Decision needed (deviation from the skill):** the chat-actions skill says the actions server is localhost-only and never exposed, because it acts as the signed-in user. The user's requirement — AI agents as working participants — needs **external write access**. Recommendation: one actions-server codebase with **two bindings**:

- *localhost, trusted* — for the in-app assistant (command bar + AMA), identity from the PHP-minted action token, exactly as the skill specifies;
- *HTTPS behind Apache, bearer-token* — for external AI agents. The bearer token (scope `actions`) resolves to a Person of kind `ai_agent` with role `agent`, `light_agent`, or `requester`; the server mints the action token for that Person and calls the same PHP endpoints. Same validation, same authorization, same activity log, with `actor_kind = ai_agent` and the token id on every row. Per-token rate limits apply.

Nothing bypasses PHP, the read servers stay strictly read-only, and every AI action is attributable and undoable. Tenant isolation remains at the database level (SaaS Plus+). The alternative — read-only MCP for external AI, writes only through humans — does not meet the stated requirement. This mirrors what the market converged on: Linear ships separate read-only and read-write MCP endpoints; Freshworks, Atlassian, and HubSpot all expose write tools with confirmation metadata.

Supporting design points:

- `get_vocabulary` and `ticket_transitions` (Q17/Q18/Q43) exist specifically so AI callers fill statuses, priorities, categories, teams, and custom fields correctly and only attempt legal moves.
- `tickets_changed_since` (Q42) gives polling agents a cheap delta; **webhooks** (slice 14) let them subscribe instead.
- The settings screen (slice 14) publishes the two read-server URLs and the actions URL, and manages tokens, rate limits, and webhooks.

---

## 8. Traceability check (AMA lens, both directions)

- **Question → memory:** every Q above traces to entities in section 2. Q32 ("how long was Maria in the ticket") requires `screen.entered` events with ticket id — present in 2.8. Q42 requires `last_activity_at` on tickets — present in 2.2. Q19 requires Ticket Viewer — added in 2.3.
- **Memory → question:** Agent presence (2.1) is justified only by load-based assignment (slice 15) and Q24; it stays in the schema, UI deferred. Inbound Email raw store (2.7) is justified by Q40 and by never losing a customer message. Time Entry (2.3) is justified by Q10 and billing exports; tier Extended. Webhook Delivery (2.7) is justified by Q40.
- **Feature with no question:** none found. "Export CSV" (slice 21) is an action, not a question, and is kept because supervisors predictably ask for it.

---

## 9. What the third-party research changed in this plan

The comparison across Zendesk, Freshdesk, JSM, ServiceNow, Help Scout, osTicket, Zammad, HubSpot, Linear, and Intercom confirmed the core shape (status categories, four priorities, reply-versus-note, SLA on first/next/resolution, saved views) and changed the following:

| Change | Source pattern | Where in this plan |
|---|---|---|
| Added the **light agent** role (notes only, never owns) | Zendesk light agents, JSM collaborators | 1, 2.1, 4 |
| Added **pending until** (snooze / reminder that auto-reopens) and **hold reason** | Zammad pending reminder, ServiceNow on-hold reasons, Linear snooze | 2.2 |
| Added an explicit **allowed-transitions table** with required fields per transition, and a `ticket_transitions` tool instead of free status writes | Atlassian `listJiraIssueTransitions` / `transitionJiraIssue`; JSM workflows | 2.2, Q18, Q43, 7 |
| Custom statuses allowed only inside `open` / `pending` / `on_hold` / `solved` | Zendesk custom-status rules | 2.2 |
| **Triage shortcuts** on new tickets: accept, decline, duplicate, snooze; a "Triage" standard queue | Linear triage inbox | 2.2, 4, slice 7 |
| **Boards are a layout of saved views** (filter + board settings), with multiple personal/shared boards; column field may be status, priority, assignee, team, or type | JSM queue boards, Linear board layout, Freshservice / Kanban Pro | 2.5, 6 |
| Solved column capped to recent N days; closed hidden; soft WIP limits highlight, never block | Freshservice, SweetHawk, Kanban Pro | 6 |
| Card carries an **SLA risk strip** and a **needs-reply marker**; added derived SLA risk state (`on_track` / `at_risk` / `breached` / `met`) and a policy warning threshold | Linear SLA risk statuses, Kanban Pro SLA colour strips, UVdesk `customerRepliedAt` | 2.2, 2.4, 6 |
| Drop into a column that needs a field expands the card inline to collect it | JSM transition screens, HubSpot board automations | 6 |
| **Ticket Viewer** presence for collision warnings | Help Scout collision detection, osTicket locks | 2.3, Q19 |
| **Category carries routing defaults** (team, type, priority, SLA) | osTicket help topics | 2.2 |
| Custom field definitions may be restricted to ticket types (per-type forms) | Freshdesk dynamic forms, Zendesk ticket forms, osTicket forms | 2.2 |
| Added `external_id` on tickets for monitoring systems | Zendesk `external_id` | 2.2 |
| **CSAT settings** (scale, delay, window, bad-rating reasons) rather than hard-coded good/bad | Zendesk CSAT, Help Scout Great/Okay/Not Good | 2.6, slice 17 |
| **Webhook subscriptions + delivery log** so agents and integrations subscribe instead of polling | FreeScout webhooks, Zendesk webhooks, every vendor REST API | 2.7, Q40, slice 14 |
| **Per-token rate limits** and usage counters | Freshdesk MCP rate limits, Linear | 2.7, Q38 |
| `tickets_bulk_update` action; reply and note stay separate tools | Freshdesk `createTicketBulkUpdate`, `replyTicket` / `createTicketNote` | 7 |
| Destructive tools require `confirm: true` and carry `destructiveHint` | HubSpot `manage_crm_objects` confirmation; Atlassian delete tools off by default | 7 |
| Added **AI assist slice** (summary, grounded draft reply, suggested triage) using the existing assistant service | Zendesk Copilot, Freddy, Now Assist, Rovo | slice 22 |
| Problem-solves-incidents automation; duplicate detection on create | Zendesk problem/incident, Linear Triage Intelligence | slices 15, 19 |
| Moved to Later: skills routing, side conversations, approvals, impact × urgency, multi-brand, triage rotation, AI actions in rules, calendar view, NPS/CES | Zendesk, JSM, ServiceNow, Linear, HubSpot | 5 |

Market facts worth knowing: no mainstream help desk has a native kanban except JSM (since July 2025), HubSpot, and Freshservice; Zendesk and Freshdesk rely on marketplace apps. Official vendor MCP servers exist for Freshdesk (September 2026, read-write), Atlassian (read-write), HubSpot (read-write with confirmation), Linear (separate read-only and read-write endpoints), Help Scout (read-only at GA), and Intercom (read-only); Zendesk's own server had not shipped as of September 2026. This system's three-server design with discovery tools, transition validation, and a separate write exposure is at or ahead of that bar.

---

## 10. Decisions for the checkpoint

1. **Approve the memory model** (section 2) and the **slice order** (section 5), including the Core / Extended / Later tiering.
2. **External AI write access** — approve the two-binding actions server (section 7), or keep AI strictly read-only.
3. **Requester portal** — requesters can sign in (same auth, role `requester`); email-only requesters exist with no credentials. Confirm.
4. **Statuses as admin-editable reference data** seeded with six categories, with a transitions table (versus a fixed enum). Confirm.
5. **Ticket number format** — `HD-1042` with a configurable prefix. Confirm or give the prefix.
6. **Email intake** — IMAP polling of a team mailbox by default; inbound webhook if the mail provider offers one. Confirm, and name the mail provider if known.
7. **Kanban drag-and-drop as a JS island** (SortableJS) with a select-based fallback. Confirm.
8. **Boards as saved views** (one entity, two layouts) rather than a separate board entity. Confirm.
