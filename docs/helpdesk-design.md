# Help Desk — design (Phase 0 of the new-app workflow, fitted to the Business OS)

2026-10-02 · the fourth application from us beside the kernel, and the first that is **part of every OS install**:
**the help desk**. Anyone — an employee, a customer with no OS account, an OS agent — files an issue or a request
here, and humans and agents work it to a resolution. Its own repository `/srv/apps/helpdesk` (origin
`github.com/maludb-ed/help-desk`), served as `helpdesk.<domain>` (`helpdesk.subello.com` here), built with
`htmx-php-builder` (how it is built) and fitted to `maludb-os-integration` 0.5.0 (how it fits), exactly as HR,
Projects and txtSchedules were (`/srv/apps/txtschedules/docs/txtschedules-design.md` is the nearest exemplar).

> **What this document is.** `docs/01-phase0-plan.md` (2026-10-01) is the domain plan: actors, the memory model, the
> 44 questions, the action inventory, the board, the research. It was written for a standalone application with its own
> login, its own people, its own tokens and its own assistant. This document keeps every domain decision of that plan
> and **replaces** what the kernel owns (§3 says exactly what). Where the two disagree, this one wins; where this one is
> silent, the Phase 0 plan stands.
>
> **Checkpoint.** This document, the schema in `db/`, `maludb-os.json`, `os/`, `skills/` and `deploy/` are the Phase 0
> deliverable. Phase 1 adds `docs/helpdesk-mcp-tool-surface.md`, `docs/helpdesk-action-manifest.md` and the slice
> specs in `docs/build-specs/`. **No feature PHP is written until the owner approves them together.** The decisions
> this document needs from the owner are §12.

## 1. What Help Desk is, and is not

Help Desk **owns tickets**: who asked for what, who holds it, what state it is in, every word exchanged about it,
the service level it is held to, and how long each step took. It is the Zendesk, Freshdesk or osTicket a business
would otherwise pay for, with the differences that matter here:

- **It is the OS's front door for work that needs a person or an agent.** An OS agent files a ticket when it is
  blocked, when it finds something, or when a person asks it to; a person files one from the application, by
  email, or through their assistant; a customer files one by email or from the public request form.
- **Agents work tickets beside people.** A hired OS agent holds the support role like a person, is assigned
  tickets like a person, reads them through the records MCP under its run token, and acts through the kernel's
  Actions MCP — grants, approvals and evaluation safety all the kernel's.
- **Full MCP coverage is a requirement**, not a feature: every question a screen answers is a records or activity
  tool, every button an action tool (`maludb-os-integration`, `mcp-and-api.md`).
- **Nothing of identity lives here.** The kernel signs people in; the help desk mirrors the directory. The one
  class of person the kernel does not know — the external requester with no OS account — never signs in at all:
  a token is their authority (§4).

Not in version 1: a CRM (organizations here are the customer companies tickets come from, nothing more); chat and
phone intake; webhooks to outside systems; multi-brand portals; approvals inside tickets (approvals are the
kernel's); money of any kind. The Phase 0 plan's "Later" list stands.

## 2. Who uses it (the actors, in the OS's words)

**A word about words.** In this repository **"agent" always means an OS AI agent.** The person who works tickets is
**support staff**; the one holding a ticket is its **assignee**. The Phase 0 plan's "agent", "light agent" and
"AI agent (external)" are renamed below.

| Actor | Who | How they reach the help desk |
|---|---|---|
| **Requester — a member** | An employee, or an invited external with an OS account (`is_external`) | The launcher → `helpdesk.<domain>`; email to a queue's address; their personal assistant; the command bar |
| **Requester — external, no account** | A customer, a supplier, anyone who emails support | Email; the public request form `/request`; the **secure ticket link** mailed to them (§4) |
| **Requester — an agent** | Any OS agent, for itself or on behalf of a person | `ticket_create` on the kernel's Actions MCP under its run token |
| **Collaborator** (was *light agent*) | A colleague who may read a queue's tickets and add internal notes, never reply or own | The application, restricted |
| **Support staff** (was *agent*) | Works tickets in the queues they belong to: replies, notes, transitions, assignment | The application, the command bar, email replies |
| **Lead** | Watches queues, workload, SLA risk, quality; reassigns; owns shared views and boards; edits the knowledge base | The application, reports |
| **Help Desk admin** | Queues, people in queues, statuses and transitions, SLA policies, business hours, canned responses, custom fields, mailboxes, automation, CSAT. A super-admin holds it (the kernel gives them the admin role) | The settings area |
| **An agent working tickets** | The shipped **triage** and **frontline** agents, and any agent a super-admin grants the support role | Records MCP (run token) · Actions MCP (relayed) · woken by a duty or a dispatch (§5) |
| **The kernel** | Signs people in, delivers the directory, hires and runs the agents, pauses their actions for approval, texts members (K6), answers the command bar (chat endpoint) | — |
| **System** | SLA clocks, automation rules, the mail poller, auto-close, reminders — the one timer worker | `source = 'cron'`, every row logged |

Tenancy: the business running the help desk is the tenant (one installation, one database, one memory with every
other application's episodes). Its **customers' companies** are **organizations**, a domain entity.

## 3. What changes from the Phase 0 plan — the integration gap list

In the order the `os-integration` skill prescribes (memory first).

| Phase 0 plan said | The OS says | Where |
|---|---|---|
| One **Person** entity with auth facts (password, Google, TOTP), kinds `human`/`ai_agent`/`system`, roles | Two things: the kernel's **mirror** (`members`, `department_members`, the kernel's ids, no auth) and the help desk's own **`requesters`** table (name, emails, phone, organization, locale, `member_id` nullable). A member's requester row links by `member_id` (auto-created at first sign-on or first ticket); an external has none; an agent is a member of kind `agent`. Nothing about identity is stored here | §7 |
| Login, Google, TOTP, reset, invite | `/sso` and `/sso/logout` receivers, the directory sync timer, no login form; `/login` → the launcher. Externals never log in (§4) | Phase 2 |
| **Teams** with members, mailbox, schedule, assignment method | **Queues** (the same thing, renamed so "team" stays the kernel's word for a department's people). A queue may be **linked to a kernel department** (`department_id` from the mirror): its live members who hold `support` are in the queue without being named. Membership otherwise explicit (`queue_members`, with `is_lead`) | §6, §7 |
| Roles `requester` / `light_agent` / `agent` / `admin` on the Person | Roles **published by `app_roles`**, granted by the super-admin, rights enforced by `hd_has_right()`. Scope kind **none** (one desk; queues partition the work) | §6 |
| **Access tokens** with scopes `records_read` / `activity_read` / `actions`, per-token rate limits | The contract's `mcp_access_tokens` (scope `mcp` / `api`) for a person's own Claude Desktop or tool, read-only. **No `actions` scope**: writes by anything automated go through the kernel's Actions MCP as a hired agent | §5 |
| **Two-binding actions server** (localhost + HTTPS bearer for external AI) — *decision 2 of the Phase 0 plan* | **Dropped.** The application ships no actions server; the kernel's one executes the manifest. An outside AI that must write becomes a hired agent in Agent HR, with grants, approvals and a ledger — the kernel's answer to "AI agents as working participants" | §5 |
| **Webhook subscriptions + deliveries** | **Later.** OS agents are not woken by webhooks (§5); webhooks are for outside systems, none of which exist yet. The schema keeps room; nothing is built | §10 |
| **In-app assistant** (a Claude Agent SDK service), AI assist buttons | The **command bar through the kernel's chat endpoint**; the **expert** is hired by the kernel. "Summarise", "draft a reply", "suggest a category" are utterances to the expert with the ticket as context; the application holds **no model key** and calls no model | §5 |
| **Ticket Event** table beside the activity log (the timeline) | **The activity log is the history** (the sibling rule: Projects' issues, txtSchedules' shifts). `before`/`after` carry what the timeline and the activity questions need (status from/to with the resolution code, assignee old/new, priority old/new, the rule that fired). The secure ticket page shows messages only | §7 |
| Activity log, per the builder | The contract's `activity_log` + `log_activity()` + the ingest bridge with `"application": "helpdesk"` first in every payload; `source` `web` / `agent` / `cron` / `webhook` / `portal` / `api` | Phase 2 |
| Email: IMAP poll by default | **MaluMail mailboxes**, one per queue that takes email (`support@<domain>`, `it-help@<domain>`): inbound by MaluMail's signed new-mail webhook (M2) plus a once-a-minute poll of unread mail as the safety net, the message read through MaluMail's mailbox MCP (M3), replies sent through `/v1/send` threaded (M4) — the kernel's own channels pattern (`html/channels/malumail-mail.php`). IMAP for another provider is Later | §8, slice 7 |
| Notifications by email only | Members: in-app bell, email through MaluMail, **texts through the kernel (K6)** per the person's preferences, every refusal falling back to email (txtSchedules' outbox pattern). Externals: email only, each notice carrying the secure link | slice 7 |
| An AI agent subscribes or polls `tickets_changed_since` | A hired agent is **dispatched** (§5); `tickets_changed_since` stays for a person's own tools | §5 |
| Organizations, SLA, business hours, statuses with categories and the transitions table, custom fields, canned responses, saved views and boards, knowledge base, CSAT, automation, time entries | **Unchanged** — the help desk's own, exactly as planned | `01-phase0-plan.md` §2, §5, §6 |

## 4. Requesters without an OS account — the one hard problem

The kernel's rules: *application users are not OS users*; *never a password, a login form or an account of the
application's own*; *the hand-off token is the only way in for a person* — and, for a page someone with no account
must reach, **a token is the authority** (the kernel's old signing page and public forms followed that rule).
External requesters are the people the owner named as a primary source of tickets, and most of them will never
have an OS account.

**Recommended (D-A): externals are not accounts — email and the secure ticket link.**

- A ticket from an external begins as an **email to a queue's address** or as a submission of the **public request
  form** `/request` (name, email, subject, description, attachments; CSRF; an IP and address rate limit; honeypot).
  The requester row is keyed by the email address.
- Every ticket mints a **secure link** `/t/<48 hex>` — the token hashed in the database, rotatable, dead N days after
  the ticket closes (setting; default 90). It is mailed in every notice to an external requester. On that page they
  see the public conversation (never notes, never names of other requesters), reply, attach, confirm the resolution
  or reopen, and rate. Possession of the mailbox is the authority, as it is for the email reply itself.
- **No "all my tickets" page for an external in version 1.** The Extended slice *magic link* adds `/my`: they type
  their address, a one-time link is mailed, and a short requester session (no password) lists their tickets.
- The public paths — `/request`, `/t/`, later `/my`, `/kb` for public articles, and the mail webhook
  `/channels/malumail/mail` — are named in the vhost's allow-list; nothing else answers without a session.

**Also supported (D-B): an invited external.** Since the kernel's db/159 an external member with a grant can launch
an application. A B2B customer the business wants to give a full requester experience is invited in the kernel (by a
person; the help desk does not write the directory), granted the Requester role, and signs in through the launcher
like an employee; their requester row links by `member_id`, and tickets they emailed earlier under the same address
are theirs. D-A and D-B coexist with no extra code: a requester row is a member's when it has a `member_id`.

**Rejected:** inviting every customer as a member (heavy, and the help desk would need `directory_writes`, which is
HR's alone by decision); a password of the help desk's own (forbidden by the contract).

## 5. Agents and the help desk — the OS requirement, in detail

**Submitting.** `ticket_create` on the Actions MCP: under a run token the requester is the agent (`channel =
agent`); `on_behalf_of` names a member when the agent files for a person (their assistant filing "my laptop
fan is loud"); `external_id` lets a monitoring agent file idempotently. Every other action — reply, note,
transition, assign, tag, fields, link, merge, log time, apply a canned response, move a card — is one tool each,
POSTing to the same handler a button uses.

**Approval categories** (the kernel pauses an agent's call; a person doing the same is never paused):

| Action | Category | Why |
|---|---|---|
| `ticket_reply` (a public reply — it is mailed to the requester) | `external_send` | An agent's words leave the building. **Default: pause.** The owner relaxes it per agent or per queue by editing the policy in the kernel (a policy may name one agent), e.g. the frontline agent in the IT queue once trusted |
| `ticket_merge`, `requester_merge` | `other` | Irreversible joins |
| `ticket_spam`, `ticket_delete`, `article_delete`, `canned_delete` | `deletion` | |
| `ticket_close` (manual close before the auto-close window), `bulk_update` | `other` | |
| `*_save` of settings: queues, statuses, transitions, SLA policies, business hours, automation rules, mailboxes, CSAT | `other` | Configuration |
| `ticket_note`, `ticket_transition` (except close), `ticket_assign`, `ticket_set_fields`, `ticket_add_tags`, `ticket_link`, `ticket_log_time`, `board_move_card`, `ticket_create` | — | The triage and frontline agents must do these alone |

**Working — how an agent learns it has a ticket.** The kernel wakes an agent by a duty (cron), by a message on the
orchestrator tree, or by one turn through the chat endpoint. An application cannot message an agent today
(`message_send` is an agent's tool, and the chat endpoint needs an acting member). So:

1. **Duties, version 1** — each agent hired to work tickets has a duty (`*/5 * * * *` for triage, `*/10` for
   frontline) whose instructions are: call `my_queue`, work what is there, stop. Nothing in the kernel changes.
2. **Dispatch when there is a person** — when a person assigns a ticket to an agent from a screen (or the triage
   agent's `ticket_assign` lands on a frontline agent while a person's command-bar turn is running), the help desk
   calls the kernel's chat endpoint `?agent=<id>` with `X-Acting-Member` = that person and the utterance "Ticket
   HD-1042 was assigned to you" — one turn, ledgered, answered. Recorded in `agent_dispatches` (ticket, agent,
   run id, status) so Q33 has it.
3. **K8 (kernel, owed)** — `POST /api/v1/agents/wake.php` with the application token: `{agent_id, subject, body,
   reference}` puts a `request` message from the application into the agent's inbox, and the runner's message
   loop wakes it. Then every assignment, every reply on an agent's own ticket and every SLA warning on an agent's
   ticket wakes it within seconds, with no acting member needed. Until K8, (1) and (2).

**Escalation into the help desk — K9 (kernel, owed).** The kernel's `escalation_raise` accepts `open_ticket` and
says honestly that no ticket is opened. When a help desk is installed (catalog key `helpdesk`, its registry
loaded), the kernel honours it: it POSTs `ticket_create` to the help desk as the escalating agent (requester = the
agent, queue = the one linked to the agent's department, else the default queue; `on_behalf_of` = the escalation's
`to_member`), and writes the ticket number on the escalation. A person's assistant then sees the escalation in its
inbox *and* a ticket exists for the humans.

**Shipped agents** (`maludb-os.json` `agents[]`, hired on install for a default application):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Help Desk Expert | The command bar: answers "what is open for me", "how do I …", summarises a ticket, drafts a reply (returns words — the person sends), suggests category/priority/queue on the create form; files a ticket **for the asker**; adds a note; replies **as the asker** when asked to | all records tools, `ticket_history`, `actor_timeline` | `ticket_create`, `ticket_reply`, `ticket_note`, `ticket_set_fields`, `ticket_add_tags`, `ticket_transition`, `canned_apply` | `ticket_reply` (external_send) |
| `triage` — Dispatcher | A duty every 5 minutes: every `new` ticket → category, priority, type, queue, likely duplicates linked, a suggested article noted; assigns to a **queue**, and to a person or agent only where an assignment rule says so; never replies | `triage_queue`, `get_ticket`, `similar_tickets`, `search_articles`, `get_vocabulary`, `find_queues`, `agent_workload` | `ticket_set_fields`, `ticket_add_tags`, `ticket_assign`, `ticket_link`, `ticket_note`, `ticket_transition` (new → open) | nothing |
| `frontline` — First-line Support | A duty every 10 minutes: its queue's open tickets assigned to it → an answer grounded in the knowledge base and canned responses, sent with `ticket_reply`; when unsure or the requester pushes back, `ticket_assign` to a person (the queue's lead by default) with a note saying why | `my_queue`, `get_ticket`, `ticket_conversation`, `search_articles`, `search_canned_responses`, `requester_history`, `ticket_transitions` | `ticket_reply`, `ticket_note`, `ticket_transition`, `ticket_assign`, `canned_apply` | `ticket_reply` until the owner relaxes it |

Any department's lead agent or a person's assistant can be granted the Requester role (to file) or Support (to
work a queue) by the super-admin — the kernel's ordinary grant.

**Skills** (`skills/`): `helpdesk-basics` (what a ticket, queue, status category, SLA target *is*; which tool
answers what), `ticket-lifecycle` (the transitions table, what each move requires, what pauses), `writing-to-
requesters` (tone; never a secret, a token, another requester's name, an internal note's content); runbooks
(`kind: runbook`): `triage-the-queue`, `answer-from-the-knowledge-base`, `end-of-day-review` (at-risk, stale,
unassigned, what to escalate).

## 6. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; ordered as shown). The base role's
**key is `user`** on purpose: the kernel's installer grants the standing departments the role keyed `member`,
`user` or `write` — any other naming would make it pick the wrong one.

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `user` | Requester | write | `tickets.create`, `tickets.view_own`, `kb.read` |
| `collaborator` | Collaborator | write | Requester's + `tickets.view_queue`, `tickets.note` (in the queues they belong to) |
| `support` | Support staff | write | Collaborator's + `tickets.work` (reply, transition, assign, fields, tags, link, time, canned) in their queues, `views.personal` |
| `lead` | Lead | write | Support's + `tickets.view_all`, `tickets.manage` (merge, spam, bulk, reassign across queues, close early), `views.shared`, `kb.edit`, `reports.view`, `queues.members` |
| `admin` | Help Desk admin (is_admin) | admin | every right + `settings.manage` |

**The one rule** (`hd_can_see_ticket(ticket_id)`, PL/pgSQL, one query; the `mcp_*` views and the PHP gates both ask
it, caller checks as uncorrelated sets tested once per statement):

- a ticket is seen by its requester, its submitter, its CCs and followers (by `member_id` for members; an external
  reaches theirs only through the link);
- by a collaborator, support member or lead **of its queue** (named, or through the linked department);
- by anyone with `tickets.view_all`; a super-admin holds every right;
- an organization's other members see its tickets only when the organization allows it (setting) and they are
  members with the Requester role (externals-by-link never see another ticket);
- internal notes, time entries, SLA internals, automation details and other requesters' details are **never** on the
  secure page, in an email to a requester, or in a tool answer to a requester-only caller;
- an agent sees what its member id may see — the same function, no special case; the run-facts gate decides which
  tools it is offered; `is_eval` refuses every write.

Nobody sees another person's email or phone except support staff on the requester card; a notice carries the
ticket's facts and a link, never a note.

## 7. Memory model — what Help Desk remembers

**Record memory** (PostgreSQL 17, `<tenant>_helpdesk`, db/001–0NN):

| Area | Tables | Notes |
|---|---|---|
| The mirror | `members`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state` | the kernel's ids; `members.roles text[]` from the claims and `access[]` |
| Roles | `hd_rights`, `hd_roles`, `hd_role_rights`, `mcp_app_roles` | `hd_has_right(right)`; queue membership is data, not a right |
| People | `organizations` (name, domains, tier, SLA override, org visibility, custom values), `requesters` (**`member_id` nullable**, kind `member`/`external`/`agent`, emails, phone, locale, timezone, signature, last seen), `requester_emails` | a member's row is made at first sign-on or first ticket; an email maps to a requester by `requester_emails`, else to an organization by domain, else new |
| Queues | `queues` (name, `department_id` nullable, assignment method, schedule, default SLA policy, default assignee incl. an agent, mailbox), `queue_members` (`is_lead`), `queue_presence` (availability, capacity) | presence UI deferred; capacity used by least-loaded assignment |
| Vocabulary | `ticket_statuses` (category, color, order, on board, `clock_runs`), `status_transitions` (from category → to category, required fields), `ticket_types`, `categories` (hierarchical, routing defaults), `tags`, `custom_field_defs` | seeded: six statuses, four types, the transitions of the Phase 0 plan §2.2 |
| The ticket | `tickets` (number `HD-1042`, subject, requester, submitter, organization, assignee **member or agent**, queue, status, priority, type, category, channel, tags, custom values jsonb, due, pending_until, hold reason, resolution code, parent, merged_into, external_id, board_position, reopen count, the timestamps), `ticket_followers` (cc / follower), `ticket_links`, `ticket_viewers` (expiring), `ticket_sequences` | `needs_reply` and `sla_risk` derived in the views; number from a sequence with a configurable prefix |
| The conversation | `messages` (public_reply / internal_note, body text + sanitised HTML, channel, Message-ID / In-Reply-To, mentions), `attachments` (sha256, stored under `storage/attachments/<ticket>/`, served through the gate) | |
| Service levels | `business_hours` (weekly intervals, holidays, timezone), `sla_policies` (match conditions, per-priority targets, business or calendar hours, warning %), `sla_targets` (per ticket per metric: due, elapsed, paused, met, breached) | the clock pauses in a status whose `clock_runs` is false; recomputed by the worker and on each change |
| Working faster | `canned_responses` (placeholders, scope, side-effects, usage), `saved_views` (filter, sort, columns, layout list/board, board settings, WIP limits), `automation_rules` (trigger / time_based / assignment; conditions and actions over a bounded vocabulary; run counts), `rule_runs` | a board **is** a saved view |
| Requesters' doors | `ticket_links_secure` (ticket, token hash, expires, rotated), `request_form_submissions` (rate-limit ledger), `csat_settings`, `satisfaction_ratings` | the secure link (§4); the magic link's `requester_sessions` is Extended |
| Email | `mailboxes` (address, queue, MaluMail webhook secret **as a reference**, signature, active), `inbound_email` (raw headers, Message-ID, from, to, subject, body, status, resulting ticket/message), `outbound_email` | nothing is thrown away before it is linked; reply-above-the-line parsing |
| Knowledge | `kb_sections`, `articles` (draft/published/archived, public/internal, counts), `article_links` (suggested / sent / created_from) | public articles at `/kb` |
| Talking | `notifications` (in-app), `notification_prefs`, `notification_outbox` (email and text as independent rows; K6 for texts) | txtSchedules' outbox, copied |
| Agents | `agent_dispatches` (ticket, agent member, kind, run id, status, answered at) | §5 |
| Time | `time_entries` | Extended |
| Tokens | `mcp_access_tokens` | the contract's; a person's own |

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, shipped to the tenant's one
MaluDB as `activity` episodes tagged `helpdesk`. **There is no ticket-events table**: a ticket's timeline is
`SELECT … FROM mcp_activity_log WHERE entity_type = 'ticket' AND entity_id = …`. Events (the manifest names them):
`ticket.create|reply|note|transition|assign|set_fields|tag|untag|link|unlink|merge|split|follow|unfollow|snooze|
reopen|close|spam|delete`, `ticket.sla_warning|sla_breach` (the worker), `ticket.dispatch` (an agent woken),
`rule.fire`, `canned.apply`, `board.move`, `view.save`, `requester.create|merge|update`, `organization.*`,
`queue.*`, `status.*`, `transition.*`, `sla_policy.*`, `mailbox.*`, `mail.inbound|outbound|bounce`, `article.*`,
`rating.submit`, `survey.send`, `token.mint|revoke`, `member.sign_on`, `directory.sync`, `screen.view`. A message
body is never in an activity row (a 200-character excerpt at most); a secure token never.

## 8. The kernel's part, and the application's doors

- **Sign-on and the mirror**: the contract (`sign-on-and-directory.md`), the kit copied from txtSchedules.
- **Roles**: `app_roles` on the records server; the kernel's token admitted to it alone (`roles-and-rights.md`).
- **Agents**: run tokens on the two read servers (run-facts gate, fail closed); the Actions MCP from the registry;
  the approval hook before every action with a category; the chat endpoint for the command bar and for dispatch (2);
  duties for the shipped agents (hired by `--hire-agents`).
- **K6 texts**: a member's notices by text when they chose it; every refusal falls back to email; no Twilio key here.
- **Email is the application's own** (the kernel offers no mail service): MaluMail mailboxes created by the owner
  (or through MaluMail's mailbox MCP once the API key is set), each with its new-mail webhook pointed at
  `https://helpdesk.<domain>/channels/malumail/mail` and its signing secret in `config/.env` by reference; the
  application holds `MALUMAIL_API_KEY`, `MAIL_FROM`, `MAIL_FROM_NAME` (optional env, as txtSchedules does).
- **Public doors** (allow-listed in the vhost; everything else needs a session): `/request`, `/t/<token>`, `/kb`,
  `/channels/malumail/mail`, `/api/v1/health`. Each is CSRF-protected or signature-checked, rate-limited, and
  logged with `source = 'portal'` or `'webhook'`.

## 9. Screens (the nxl look; every requester screen designed at 375 px first; no modals; cards for named things,
tables for ledgers; every name a link; every page a way back)

**Requesters (members):** Home — my open tickets, "New request" (the form suggests a category and an article as
you type, through the expert), the knowledge base search · My tickets · Ticket (conversation, reply, attach,
confirm / reopen, rate) · Settings (how I am told: email, text, in-app).

**Externals (public):** Request form · Secure ticket page · Public knowledge base · (Extended) My tickets by magic
link.

**Support staff:** Inbox — the standard views (My tickets · Needs my reply · Unassigned in my queues · Triage ·
At risk · Stale) and saved views, as a list or a **board** (Phase 0 plan §6, SortableJS vendored as Projects did,
a "Move to…" select as the same POST) · Ticket detail — conversation with composer (reply / note, canned responses,
mentions, attachments), the fields panel with inline changes through allowed transitions (resolution code, hold
reason, pending-until collected inline), requester card with history and organization, followers / CCs, links,
time, SLA strip, the timeline from the activity log, collision warning · Create (on behalf of, for an agent, from
an email) · Requester and organization pages · Canned responses · Knowledge base editor.

**Leads:** Queue dashboard (backlog by status / priority / age, workload, at risk, stale) · Reports (volume, first
response, resolution, SLA attainment, reopen rate, CSAT, workload, aging; each a table and a CSV) · Shared views
and boards · Queue members.

**Admin:** Queues (link a department, mailbox, schedule, assignment method, default assignee incl. an agent) ·
Statuses and the transitions matrix · Types, categories, tags · Custom fields · Business hours · SLA policies ·
Canned response folders · Automation rules (with run counts and the last 30 days' runs) · Mailboxes (and the
inbound failures) · CSAT settings · Agents (which agents work here, their last dispatch and run — read-only; hiring
and grants link to the kernel) · My MCP tokens.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | **built 2026-10-02:** this document and the owner's answers (§14); the schema **db/001–015** proven on a scratch database (`db/proof/phase0_proof.sql`, 81 checks); the kit (Projects' unscoped identity, roles and sign-on files; txtSchedules' newer MCP common files, HTTP helper and sync), proven without a kernel (`tests/phase0/run.sh`: 15 checks — fixture sync, hand-off, replay, audience, unknown member, tampered signature, sign-out notice, health); `maludb-os.json` (every port env in `env.required`), `os/{expert,triage,frontline}.md`, six skills (three runbooks), `deploy/` templates with the public doors, `deploy/ROOT_STEPS.sh` (the port pin first); the installer's `plan` reads the repository clean | the owner's go on §12 — **given 2026-10-02** |
| 1 | `docs/helpdesk-mcp-tool-surface.md` (≈32 records tools + 6 activity tools + `app_roles`), `docs/helpdesk-action-manifest.md` (≈45 screens, ≈75 actions, ≈12 pausing for agents), `mcp/action_registry.json`, the slice specs in `docs/build-specs/` (`sso-shell` and the ten slices; **tickets-core is the exemplar**) | **approved together, before any PHP** |
| 2 | `/sso`, `/sso/logout`, the mirror and its timer, `members.roles` from the claims and the feed, `hd_has_right()`, the ingest bridge, the shell (phone-first, command bar through the chat endpoint), `/api/v1/health`, the vhost with the public allow-list; installed beside the kernel | hand-off replay refused; an unknown member refused; a revoked grant shuts every door within a minute; 375 and 1280 |
| 3 | the slices, in order: **(1) queues and people** (queues, members, linked departments, organizations, requesters, business hours, SLA policies — the CRUD exemplar for workers) → **(2) the vocabulary** (statuses, the transitions matrix, types, categories, tags, custom fields) → **(3) tickets core — the exemplar slice** (list with filters and search, create for every requester kind, detail, conversation and composer, attachments, transitions with required fields, assign to a person or an agent, followers, links and merge, triage shortcuts, SLA targets and risk, needs-reply, viewers, the secure link minted, `my_queue` for agents) → **(4) views and the board** → **(5) working faster** (canned responses with placeholders and side-effects, custom fields on the forms, bulk update, time entries) → **(6) the requesters' doors** (the public form, the secure ticket page, the member home, CSAT survey and settings) → **(7) email and notifications** (MaluMail mailboxes, the webhook and poll, threading, reply-above-the-line, the outbox: email, K6 texts, the bell, mentions, pending-elapsed and SLA notices, follow-up on closed) → **(8) automation and the worker** (triggers, time-based rules, assignment methods incl. to an agent, SLA escalations, problem-solves-incidents, auto-close, **dispatch**, the one timer) → **(9) the knowledge base** (sections, articles, public pages, suggest / send / convert) → **(10) reports and settings** (the dashboards, rule stats, inbound and delivery failures, the Agents page, MCP tokens) | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database, the installed application never touched |
| 4 | the two MCP servers with the run-facts gate, `app_roles`, the registry wrapper (`deploy/kernel-registry-helpdesk.json`), the three agents declared with their grants and duties; the kernel's Actions-MCP contract proved against its own `application_actions.register()` with a stubbed hook | the command bar answers "what needs my reply" (`my_queue`); an agent's `ticket_reply` pauses; the triage agent's duty files nothing it should not |
| 5 | the owner's `app_install.php apply` (ports pinned first — §13), DNS and TLS for `helpdesk.<domain>`, the MaluMail mailbox and its webhook, the K6 sender, `install_default_applications.sh` gaining `helpdesk` (K10), the hires and duties; the end-to-end proof | **a customer emails support@, the triage agent routes it, a person replies, the customer answers through the secure link, the ticket is solved and rated; an OS agent files a ticket and a person closes it** |

**Extended** (after version 1, each its own spec): magic-link requester sessions and "my tickets" for externals ·
webhooks for outside systems · K8 dispatch replacing the duties' polling · public article feedback · side
conversations · skills-based routing · IMAP intake for a non-MaluMail provider. **Later**: the Phase 0 plan's list.

Size: eleven specs (sso-shell + ten slices), about 45 screens, 75 actions, 32 records tools, 6 activity tools —
larger than txtSchedules (seven slices, 69 actions, 31 tools), which took two days on the exemplar and workers;
three days is the honest estimate, the tickets-core slice being half of it.

## 11. What the kernel owes this application (kernel repo, each its own spec and migration)

| # | Item | Why the application cannot do it | Until then |
|---|---|---|---|
| **K8** | **Wake an agent from an application** — `POST /api/v1/agents/wake.php` (application token; `agent_id`, `subject`, `body`, `reference`): a `request` message into the agent's inbox from the application, the runner's message loop wakes it; refused for an agent that holds no grant on the application | messaging is an agent's tool; the chat endpoint needs an acting member | duties every 5–10 minutes; dispatch through the chat endpoint when a person acted |
| **K9** | **`escalation_raise(open_ticket)` opens a help desk ticket** when `helpdesk` is installed: the kernel POSTs `ticket_create` as the escalating agent through its own Actions-MCP path, records the ticket number on `agent_escalations` (a nullable `ticket_ref text` column, additive) | only the kernel sees an escalation | the escalation reaches the assistant's inbox as today; nobody files a ticket |
| **K10** | **Help Desk is a default application**: `bin/install_default_applications.sh` `DEFAULTS=(hr projects helpdesk)`; the standing departments granted `user` (Requester) at install by `--grant-standing-departments`; the three agents hired by `--hire-agents` | the owner's rule for defaults is the kernel's script | `app_install.php apply … --hire-agents --grant-standing-departments` by hand |
| **K11** | **The installer refuses a half-rendered template** (found 2026-10-02): a `deploy/` file whose rendered text still holds `{{…}}` must stop `apply` before `a2ensite`, and `apply` must run `apachectl configtest` before `reload`; the port env keys an application forgets in `env.required` are written anyway when the template names them | txtSchedules' vhost went live with `{{MCP_RECORDS_PORT}}` in it and Apache has been down on every name since 06:13 UTC today | the owner fills the two ports by hand (the commands in the session report) |
| K12 | Optional, later: an agent's `rights` in run facts carry the help desk's **queue membership**? No — queue membership is the application's data; the run-facts contract stands | — | — |

## 12. Decisions the owner is asked for (recommendation first)

1. **Externals** — D-A (secure link + email, no accounts) as the rule, D-B (an invited external member) available on
   top. Confirm, or name a different door.
2. **Roles and names** — Requester (`user`), Collaborator, Support staff, Lead, Help Desk admin; "agent" reserved
   for OS agents. Confirm the words.
3. **Scope** — one help desk per installation (scope kind none), queues optionally linked to departments; **not**
   a department-scoped application. Confirm.
4. **Email** — MaluMail mailboxes per queue, webhook + poll, the application holding the MaluMail key; the first
   address `support@subello.com`. Confirm, and name any other queue address.
5. **Agent replies pause by default** (`external_send`), relaxed per agent or queue by you. Confirm.
6. **The shipped agents** — expert, triage (`*/5`, assigns to queues, to people only by rule), frontline (`*/10`,
   answers from the knowledge base, hands to a person when unsure). Confirm the three and their cadences.
7. **The history is the activity log** (no ticket-events table), the sibling rule. Confirm.
8. **Webhooks and external AI write access dropped** from version 1 (OS agents only). Confirm.
9. **Numbering** `HD-1042`, prefix configurable. Confirm or give the prefix.
10. **Catalog** — business area `Everyday`, category `other`, icon `feather-life-buoy`, criticality `high`. Confirm.
11. **Kernel items K8–K11** built in the kernel repo beside this application, K11 first. Approve.
12. **Ports pinned** before `apply`: `APP_INTERNAL_PORT=8182`, `MCP_RECORDS_PORT=8825`, `MCP_ACTIVITY_PORT=8826`
    (the HR/Projects block; the installer's picker would collide with Reservations while Apache is down). Confirm.

## 13. Ports, names and files

- Repository `/srv/apps/helpdesk`; database `<tenant>_helpdesk` (`subello_helpdesk` here); roles `helpdesk_rw`,
  `helpdesk_records_ro`, `helpdesk_activity_ro`; MaluDB: the tenant's one memory, episodes tagged `helpdesk`.
- `helpdesk.<domain>`; `APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` **all three in `env.required`**
  and all three written to `config/.env` before `apply` (§12.12); `deploy/` files are templates (`{{APP_FQDN}}`,
  `{{APP_DIR}}`, `{{APP_INTERNAL_PORT}}`, `{{MCP_RECORDS_PORT}}`, `{{MCP_ACTIVITY_PORT}}`).
- Units: `helpdesk-records-mcp`, `helpdesk-activity-mcp`, `helpdesk-activity-ingest` (+ timer, every minute),
  `helpdesk-directory-sync` (+ timer, every minute), `helpdesk-worker` (+ timer, every minute: the outbox, the mail
  poll, SLA clocks and warnings, pending-elapsed, auto-close, time-based rules, dispatch).
- `maludb-os.json`: `catalog_key helpdesk`, `business_area Everyday`, `category other`, `criticality high`, no
  `scopes`, `sso`, `directory {reads: true, writes: false}`, `assistant {command_bar: true, agent: expert}`,
  `agents [expert, triage, frontline]` each `hired_on_install: true`, `approvals` (§5), endpoints (records,
  activity, web, health), services (above); no `shares`, no `reads` in version 1.
- Vhost allow-list (public): `/request`, `/t/`, `/kb`, `/channels/malumail/mail`, `/api/v1/health`, `/mcp/records`,
  `/mcp/activity`.

## 14. The owner's answers (2026-10-02 — rules, not questions)

| # | Decision | Where it lives |
|---|---|---|
| D1 | **Externals have no account**: email and the secure ticket link `/t/<token>` are their authority; the public form `/request`; an invited external member may also use the launcher (D-A with D-B on top) | §4; `requesters.member_id` nullable; `ticket_links_secure`; the vhost allow-list |
| D2 | **Roles**: Requester (key `user`), Collaborator, Support staff, Lead, Help Desk admin; "agent" means an OS agent | §6; `hd_roles`; `app_roles` |
| D3 | **One desk per installation** (scope kind none); queues, optionally linked to a kernel department | §3; `queues.department_id` |
| D4 | **Email is MaluMail**: a mailbox per queue, the signed new-mail webhook plus a one-minute poll, threaded replies; the application holds the MaluMail key; first address `support@subello.com` | §8; slice 7; `mailboxes` |
| D5 | **An agent's public reply pauses** (`ticket_reply` = `external_send`) until the owner relaxes the policy per agent or queue; triage actions never pause | §5; `maludb-os.json` `approvals[]` |
| D6 | **Three shipped agents**: expert (command bar), triage (`*/5`, assigns to queues, to people only by rule), frontline (`*/10`, answers from the knowledge base, hands to a person when unsure); hired on install | §5; `os/{expert,triage,frontline}.md`; `agents[]` |
| D7 | **The activity log is the history** — no ticket-events table | §7 |
| D8 | **No webhooks and no external write tokens in version 1**: OS agents act through the kernel's Actions MCP; a person's own token is read-only | §3, §5 |
| D9 | **Numbering `HD-1001` onward**, prefix a setting, one sequence | `ticket_sequences`, `app_settings` |
| D10 | **Catalog**: business area Everyday, category other, icon `feather-life-buoy`, criticality high | `maludb-os.json` |
| D11 | **Kernel items K8–K11 are approved**, built in the kernel repo beside this application, K11 first | §11 |
| D12 | **Ports pinned before `apply`**: `APP_INTERNAL_PORT=8182`, `MCP_RECORDS_PORT=8825`, `MCP_ACTIVITY_PORT=8826`, all three in `env.required` | §13; `config/.env` |

## 15. State

**Phase 0 — built and proven, 2026-10-02.** The schema (`db/001`–`015`, 2,000 lines: the mirror and the acting member;
the activity log with `ticket_id` and `queue_id` on every row; tokens; five roles and fourteen rights with `hd_has_right()`;
settings and organizations with domains; requesters of three kinds with `hd_requester_for_member/email()`; business hours
with `hd_business_add_minutes()` and SLA policies; queues held by name or by department (`hd_held_queue_ids()`,
`hd_queue_ids_with_right()`); the vocabulary with the transitions table and `hd_transition()`; tickets with numbering,
the policy match, the SLA clock in triggers (start, meet, pause, resume, re-aim), the message trigger (support's reply
meets first/next response; the requester's reply starts next response and reopens pending or solved-in-window), tags,
followers, links, viewers, attachments, the secure link (`hd_secure_link_mint/ticket()`, hashes only, rotation, expiry
at close); canned responses, saved views (eight standard ones seeded, the team board among them), automation rules (two
housekeeping rules seeded) and runs, time entries; the form ledger, CSAT, mailboxes, inbound and outbound mail, the
knowledge base; notifications, preferences, the outbox, agent dispatches; the visibility functions; 36 `mcp_*` views and
`mcp_activity_log`). **Proof:** `db/proof/phase0_proof.sql` — 81 checks, every actor of §2 (a requester, support through a
department, a named collaborator who follows, a lead, the super-admin, an invited external and their organization, an
agent assignee and an agent requester, an unadmitted agent, an unknown id, nobody), the life of a ticket (reply, pending,
the requester's reply reopening, solve, reopen, close, re-aim on priority), the secure link, the activity rule.
`tests/phase0/run.sh` — the kit against `php -S` and the fixture: 15 checks. **96 green.**

**Decisions taken while building** (conservative readings; the owner may overturn any):
1. A **requester-only caller sees the vocabulary** (statuses, transitions, types, visible categories, queues' names) so the
   create form and `get_vocabulary` work for everyone; queue *membership*, SLA policies and automation need `tickets.view_queue`.
2. **An invited external's requester row is claimed by the organization whose domain their address carries**; an employee's
   is claimed by none. An external by email is claimed the same way at first contact.
3. **Solving ends every open clock** (first response included): a solved ticket owes no response.
4. **A move inside one category** (open → a custom open status) is always allowed; the transitions table governs moves
   between categories only; custom statuses may not be `new` or `closed`; built-in statuses keep their category.
5. **`needs_reply` is strict** (the requester's reply strictly newer than support's); the proof dates the two a second apart.
6. The **secure-link opener and the writer's functions are revoked from PUBLIC** — a function is PUBLIC-executable by
   default and the records role must not open a link or mint one.
7. **Sequences survive a rollback**: the proof checks the number's shape and step, not an absolute `HD-1001`.
8. `maludb-os.json` lists **no MCP endpoints yet** (they come back in Phase 4 when the servers answer) and carries each
   agent's `duty` for the record; the installer does not read `duty` yet — the owner creates the two duties in Agent HR
   (ROOT_STEPS 3) until it does.
9. **The installer's plan** confirmed the port rule: with no `config/.env`, `MCP_RECORDS_PORT` and `MCP_ACTIVITY_PORT`
   have "no value known" and the vhost would be installed with `{{…}}` unfilled — D12's pin (ROOT_STEPS 0) is what prevents
   a repeat of 2026-10-02's outage until K11 lands.

Next: **Phase 1** — `docs/helpdesk-mcp-tool-surface.md`, `docs/helpdesk-action-manifest.md`, `mcp/action_registry.json`
and the eleven specs in `docs/build-specs/` (sso-shell and the ten slices, tickets-core the exemplar), for the checkpoint.

Found the same day, outside this application: Apache down on every name from 06:13 UTC because txtSchedules'
installed vhost still held `{{MCP_RECORDS_PORT}}` / `{{MCP_ACTIVITY_PORT}}` (K11); the owner restored it at 16:08 UTC.
