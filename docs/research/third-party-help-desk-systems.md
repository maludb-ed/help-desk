# Third-party help desk systems — comparative feature inventory

Research date: 2026-10-01. Produced by a research subagent from vendor documentation and public sources; URLs are cited inline. Items that could not be verified from a primary source are marked **(unverified)**. This document is the research basis for [../01-phase0-plan.md](../01-phase0-plan.md).

Scope: Zendesk Support, Freshdesk, Jira Service Management (JSM), ServiceNow ITSM, Help Scout, osTicket, Zammad, HubSpot Service Hub, Linear, Intercom, plus open-source data models (osTicket, Zammad, FreeScout, UVdesk).

---

## 1. Per-system snapshots

### Zendesk Support
- **Ticket model**: `status` (new/open/pending/hold/solved/closed) + `custom_status_id` (custom statuses must belong to a category new/open/pending/hold/solved; none allowed in new or closed), `priority` (urgent/high/normal/low), `type` (problem/incident/question/task), `tags`, `custom_fields`, `ticket_form_id`, `brand_id`, `requester_id`, `submitter_id`, `assignee_id`, `group_id`, `organization_id`, `collaborator_ids`, `follower_ids`, `email_cc_ids`, `problem_id` (incident→problem link), `due_at` (tasks), `via` (channel), `external_id`, `satisfaction_rating`. Pending auto-reverts to Open on customer reply; On-hold is hidden from the customer; Closed is system-final and tickets cannot be reopened (a follow-up ticket is created). Merge and problem/incident linking are native; side conversations spawn email/Slack/child-ticket threads. Sources: https://developer.zendesk.com/api-reference/ticketing/tickets/tickets/ , https://developer.zendesk.com/api-reference/ticketing/tickets/custom_ticket_statuses/ , https://support.zendesk.com/hc/en-us/articles/4408832151834-Updating-and-solving-tickets , https://support.zendesk.com/hc/en-us/articles/4408835103898-Working-with-problem-and-incident-tickets , https://support.zendesk.com/hc/en-us/articles/4604286879642-Creating-side-conversations
- **Actors**: end users (requesters), agents, light agents (internal notes only), admins, groups, organizations, CCs/followers. https://support.zendesk.com/hc/en-us/articles/4408883411354-Zendesk-glossary
- **Channels**: email, web form, help center portal, messaging/chat, voice (Talk), API, social.
- **Conversation**: public replies vs internal notes; attachments; macros (one click: reply/note + set status/priority/tags/assignee/fields/followers + start side conversation).
- **Automation**: triggers (event-driven), automations (time-based, hourly), macros (manual), SLA policies with 7 metrics: First reply time, Next reply time, Periodic update, Pausable update, Requester wait time, Agent work time, Total resolution time; business hours vs calendar hours; omnichannel routing by agent status, capacity, skills, priority. https://support.zendesk.com/hc/en-us/articles/4408829459866-Defining-SLA-policies , https://support.zendesk.com/hc/en-us/articles/7412192349466-Customizing-your-SLAs-with-advanced-settings , https://support.zendesk.com/hc/en-us/articles/4408831658650-Routing-and-automation-options-for-incoming-tickets
- **Views**: saved filtered lists ("views"), shared/personal; no native kanban (see section 5). Reporting via Explore.
- **KB**: Guide/Help Center; Copilot surfaces articles. **CSAT**: sent after Solved, default 28-day window, configurable delay, custom question/scale/labels, bad-rating reasons, open-ended follow-up. https://support.zendesk.com/hc/en-us/articles/4408886173338
- **API/MCP**: REST API + webhooks. MCP *client* EAP (connect external MCP servers into action flows) launched June 10 2026 on all Suite/Support plans: https://support.zendesk.com/hc/en-us/articles/10889847373466 . A general-purpose Zendesk MCP *server* was announced at Relate (May 2026) but had not shipped as of Sept 2026 per https://tldv.io/blog/zendesk-mcp/ and https://www.geckoboard.com/blog/zendesk-mcp-the-different-options-compared-2026/ . Third-party: Swifteq (marketplace), several OSS servers.
- **AI**: Intelligent triage (intent, sentiment, language), Copilot ticket summaries, suggested replies, expand/tone tools. https://support.zendesk.com/hc/en-us/articles/5524125586330-About-Zendesk-Copilot

### Freshdesk
- **Ticket model**: default fields Requester, Subject, Type, Source, Status, Priority, Group, Agent, Product, Description, Company + custom fields and dynamic forms. Statuses Open/Pending/Resolved/Closed + custom statuses; Priority Low/Medium/High/Urgent is hard-coded because it drives SLA. Parent-child tickets, linked tickets to a "tracker", merge/split (a ticket can hold only one association type). https://support.freshdesk.com/support/solutions/articles/50000010094-understand-and-customize-ticket-fields , https://support.freshdesk.com/en/support/solutions/articles/80180-merging-two-or-more-tickets-together
- **Actors**: requesters/contacts, companies, agents (full/occasional), groups, admins, CCs.
- **Channels**: email, portal/web form, chat, phone, social, API.
- **Conversation**: replies vs private notes, canned responses, scenario automations (macros).
- **Automation**: three rule types—Ticket Creation, Ticket Updates, Hourly Triggers (formerly Dispatch'r/Observer/Supervisor); Omniroute assignment: round-robin, load-based, skill-based (Enterprise). https://support.freshdesk.com/support/solutions/articles/207276-when-and-how-to-use-the-triggers-for-automations , https://support.freshdesk.com/support/solutions/folders/272636
- **Views**: ticket list with filters/saved views; kanban via marketplace apps (section 5). **CSAT**: built-in surveys (new CSAT module). https://support.freshdesk.com/support/solutions/articles/50000009790
- **MCP**: official Freshworks MCP server GA Sept 10 2026 (API-key auth). Tools: `createTicket, fetchTicket, fetchTickets, fetchSearchTickets, updateTicket, createTicketBulkUpdate, replyTicket, createTicketNote, updateConversation, createContact, fetchContact, fetchContacts, fetchContactAutocomplete, fetchAgent, fetchAgents, createAgent, updateAgent, createGroup, fetchGroup, fetchGroups, updateGroup, createCompany, fetchCompany, fetchCompanyAutocomplete, fetchSearchCompanies, updateCompany` + 9 solution (KB category/folder/article) tools. Rate limits 25/50/100 calls per min by plan; annual action caps. https://support.freshdesk.com/support/solutions/articles/50000012670
- **AI**: Freddy Copilot—ticket summary, reply suggester, auto-triage of priority/group/status; Freddy AI Agent. https://support.freshdesk.com/support/solutions/articles/50000010359-overview-of-freddy-ai-for-ticketing

### Jira Service Management (JSM Cloud)
- **Model**: customer-facing *request types* map to *work (issue) types* which map to *workflows*. Default service workflow statuses: Open, Reopened, Pending, Work in progress, Waiting for customer, Waiting for support, Escalated, Done, Canceled (customer-portal names mapped from internal statuses). Priorities: Highest/High/Medium/Low/Lowest (Jira default scheme). Resolution field separate from status. Issue links (blocks, duplicates, relates, custom), parent/sub-tasks, linked incidents/problems/changes. https://support.atlassian.com/jira-cloud-administration/docs/default-configurations-for-jira-service-management/ , https://support.atlassian.com/jira-service-management-cloud/docs/how-do-workflows-issue-types-and-request-types-connect/ , https://support.atlassian.com/jira-cloud-administration/docs/what-are-issue-statuses-priorities-and-resolutions/
- **Actors**: customers, organizations, request participants, agents, collaborators (Jira users who can comment internally), approvers, admins.
- **Channels**: portal, email, embedded widget, API, Slack/Teams (Assist), chat.
- **Conversation**: "Reply to customer" vs "Internal note"; @mentions; canned responses.
- **Automation**: Jira Automation rules; SLA goals with JQL conditions and start/pause/stop conditions (Time Limit or Negotiated Date), shown in issue view and queue columns; approvals; queues defined by JQL. https://deviniti.com/blog/enterprise-software/jira-service-management-sla/ , https://community.atlassian.com/forums/App-Central-articles/Types-of-SLA-goals-in-Jira-Cloud-and-their-setting/ba-p/2479448
- **Views**: queues (list); board and calendar views can be added to any queue since July 2025 with swimlanes, card colours, filters, multiple boards. https://community.atlassian.com/forums/Jira-Service-Management-articles/Board-and-calendar-views-now-available-in-queues/ba-p/3077812 , https://support.atlassian.com/jira-service-management-cloud/docs/manage-your-work-using-a-board/
- **KB**: Confluence spaces linked; article suggestions in portal and agent view. **CSAT**: survey sent when a request is resolved; rating scale + optional comment; CSAT report. https://support.atlassian.com/jira-service-management-cloud/docs/collect-customer-satisfaction-csat-to-get-direct-feedback-on-your-teams/
- **MCP**: Atlassian Rovo MCP server (section 6). **AI**: Rovo/virtual service agent triages and routes, summarizes ticket history, drafts replies grounded in KB, flags knowledge gaps. https://www.atlassian.com/software/jira/service-management/product-guide/tips-and-tricks/virtual-agent

### ServiceNow ITSM
- **Model**: Incident states New(1), In Progress(2), On Hold(3), Resolved(6), Closed(7), Canceled(8); On-hold reasons: Awaiting Caller, Awaiting Change, Awaiting Vendor, Awaiting Problem. Priority is read-only, computed from Impact × Urgency (3×3) → P1 Critical, P2 High, P3 Moderate, P4 Low, P5 Planning. Fields: caller, assigned_to, assignment_group, watch_list, work_notes_list, category/subcategory, CI, short_description, description, resolution code/notes. Task family: incident, problem, change, request/requested item/catalog task; parent/child; related records. https://unogeeks.com/servicenow-incident-states/ , https://www.servicenow.com/community/servicenow-ai-platform-forum/clarification-about-on-hold-reasons-in-incident/m-p/2927096 , https://www.servicenow.com/community/itsm-blog/managing-incident-priority/ba-p/2294101
- **Actors**: callers/requesters (Employee Center portal), fulfillers (itil role), assignment groups, admins; watch list (customer-facing notifications) vs work-notes list (internal).
- **Conversation**: `work_notes` (internal) vs `additional_comments` (customer-visible); attachments; templates/response templates.
- **Automation**: SLA definitions (start/stop/pause conditions, schedules, retroactive start) creating `task_sla` records; assignment rules; Flow Designer; business rules. https://www.servicenow.com/community/itsm-forum/the-complete-practitioner-s-guide-to-servicenow-slas-from/m-p/3453739
- **Views**: lists, dashboards, Performance Analytics; Visual Task Boards give a kanban over any task list **(general knowledge, not fetched)**.
- **KB**: Knowledge Management, attach/suggest articles; **CSAT**: Surveys/Assessments on resolve **(general knowledge)**.
- **MCP**: MCP Server Console (Zurich release, in AI Agent Studio): define servers whose tools are Now Assist skills, flows, scripted REST, playbooks; OAuth; role-based tool packages; Table API excluded by design; requires Now Assist SKU. https://www.servicenow.com/docs/bundle/zurich-intelligent-experiences/page/administer/mcp-server-console/concept/mcp-server-console-landing.html , https://www.servicenow.com/community/servicenow-otto-articles/enable-mcp-and-a2a-for-your-agentic-workflows-with-faqs-updated/ta-p/3373907
- **AI**: Now Assist incident summarization (auto on reassignment), resolution-notes generation on Resolve, chat summaries. https://www.nowspectrum.com/blog/now-assist-itsm

### Help Scout
- **Model**: Inbox (mailbox) → Conversation {type email/phone/chat; status active/pending/closed/spam; folder; assignee; customer; cc/bcc; tags; custom fields} → Threads {customer, message (reply), note, lineitem}. No native priority field (tags/custom fields used) **(unverified)**. https://developer.helpscout.com/mailbox-api/endpoints/conversations/get/ , https://developer.helpscout.com/webhooks/objects/conversation/
- **Actors**: customers, users (agents), teams, admins; collision detection.
- **Channels**: email, Beacon widget (chat + contact form + help search), API. **Conversation**: replies vs notes, @mentions, saved replies, attachments.
- **Automation**: workflows (automatic & manual), tags trigger workflows; no SLA engine beyond reporting **(unverified)**.
- **Views**: folders/custom views; no kanban. **KB**: Docs. **CSAT**: ratings Great/Okay/Not Good after close. Reports: Company, Conversations, Productivity, Happiness. https://www.getmacha.com/blog/help-scout-features
- **MCP**: official server (announced July 28 2026) at `https://mcp.helpscout.net/mcp`, OAuth, read-only at GA with write tools in beta; categories: conversations (incl. threads, tags, custom fields, attachments), customers, inboxes/folders/saved replies, users/teams, workflows, reports (Plus/Pro), Docs. Tool names reported by the search index (`searchConversations, getConversation, getConversationSummary, getThreads, createConversation, createReply, updateConversation, searchInboxes`) **(unverified—page body only lists categories)**. https://docs.helpscout.com/article/1779-connect-your-ai-agent-with-help-scout-to-search-conversations-and-pull-reports , https://articles.helpscout.com/blog/introducing-help-scout-mcp/
- **AI**: AI Answers (customer-facing), AI Drafts, AI Summarize, AI Assist.

### osTicket (open source, PHP/MySQL)
- **Schema** (from install SQL): `ticket(ticket_id, ticket_pid[parent], number, user_id, user_email_id, status_id, dept_id, sla_id, topic_id, staff_id, team_id, email_id, lock_id, flags, source, isoverdue, isanswered, duedate, est_duedate, reopened, closed, lastupdate)`, `ticket_status(name, state[open|closed|archived|deleted], mode, properties e.g. allowreopen)`, `ticket_priority(priority, urgency, color, ispublic)`, `help_topic` (routing defaults: dept, priority, SLA, staff/team, form), `department(sla_id, schedule_id, manager_id)`, `team(lead_id)`, `staff(dept_id, role_id, permissions)`, `user/organization`, `sla(schedule_id, grace_period)`, `thread`/`thread_entry(type, poster, recipients)`, `thread_collaborator(role)`, `thread_event` (audit), `task`, `canned_response`, `filter` (inbound rules), `queue` (custom queues w/ columns), `schedule` (business hours), `form/form_field` (custom forms). Default statuses Open, Resolved, Closed, Archived, Deleted. https://raw.githubusercontent.com/osTicket/osTicket/develop/setup/inc/streams/core/install-mysql.sql , https://docs.osticket.com/en/latest/Developer%20Documentation/Database%20ERDs.html
- Channels: email, web portal, API, phone (source). Views: custom queues (lists), no kanban. KB: FAQ. CSAT: none native. MCP: none official.

### Zammad (open source, Rails)
- **Model**: states new, open, pending reminder, pending close, closed, merged (+ custom states); priorities 1 low / 2 normal / 3 high (+ custom). Ticket fields include number, title, group_id, state_id, priority_id, owner_id, customer_id, organization_id, pending_time, escalation_at, first_response_at, close_at, tags; custom attributes via Objects. Articles are internal or public, typed (email, note, phone, web…). Merge, link (parent/child), duplicate detection, checklists, time accounting. https://user-docs.zammad.org/en/6.3/basics/service-ticket/settings/state.html , https://docs.zammad.org/en/latest/api/ticket/index.html , https://admin-docs.zammad.org/en/latest/system/objects.html
- **Actors**: roles Admin/Agent/Customer (permission sets), groups (sub-groups since 6.2), organizations. https://admin-docs.zammad.org/en/latest/manage/roles/index.html
- **Automation**: triggers (action- or time-based), scheduler, macros, core workflows (dynamic forms), text modules; SLAs with First Response, Update, Solution timers on calendars; pending/closed freeze escalation. https://admin-docs.zammad.org/en/latest/manage/slas.html
- **Views**: overviews (configurable lists per role); no kanban (open request https://github.com/zammad/zammad/issues/363 ). KB built-in. CSAT: not found natively.
- **MCP**: community only (e.g. https://github.com/jamesdeane2/Zammad-MCP ). **AI**: AI Ticket Summary, AI Writing Assistant, AI Agents invoked from triggers/scheduler/macros. https://zammad.com/en/product/features

### HubSpot Service Hub
- **Model**: tickets are CRM objects in pipelines; default Support Pipeline statuses New, Waiting on contact, Waiting on us, Closed; multiple pipelines, custom stages. Default properties: Ticket name/description, Pipeline, Status, Priority, Category (AI-set), Source, Owner, Create/Close date, First agent email response date, Last customer reply date, Resolution, Language (AI), SLA properties (Time to first response / Time to close SLA due date and status: Active SLA, Due soon, Overdue). Associations to contacts, companies, deals, conversations; split/merge tickets. https://knowledge.hubspot.com/tickets/customize-ticket-pipelines-and-statuses , https://knowledge.hubspot.com/properties/hubspots-default-ticket-properties , https://knowledge.hubspot.com/help-desk/manage-tickets-in-help-desk
- **Actors**: contacts, companies, users/owners, teams; seat types.
- **Channels**: email, chat, phone, SMS, WhatsApp, social, forms. **Conversation**: replies vs comments (internal), snippets/templates.
- **Automation**: workflows, pipeline automations, routing with capacity limits, SLAs.
- **Views**: help desk workspace with table / split / board layouts; board drag changes status; card settings per board view. https://knowledge.hubspot.com/records/set-up-automations-in-the-updated-board-view
- **KB** + feedback surveys (CSAT/NPS/CES). **MCP**: `mcp.hubspot.com` (section 6). **AI**: Breeze Customer Agent, ticket summaries/names, sentiment, language, category, reply recommendations. https://www.hubspot.com/products/artificial-intelligence/use-cases/review-and-route-tickets

### Linear (triage/kanban reference)
- **Model**: status categories Triage, Backlog, Unstarted (Todo), Started (In Progress), Completed (Done), Canceled, Duplicate (system-managed); custom statuses within categories. Priority: No priority, Low, Medium, High, Urgent. Relations: blocks/blocked by, related, duplicate; parent/sub-issues (inherit team/priority/project); customer requests attach to issues. https://linear.app/docs/configuring-workflows , https://linear.app/docs/priority , https://linear.app/docs/issue-relations , https://linear.app/docs/parent-and-sub-issues
- **Triage**: inbox for issues from integrations/other teams; actions Accept, Decline (→Canceled), Mark as duplicate (merges attachments & customer requests), Snooze; triage responsibility rotation; Triage Intelligence suggests assignee/labels and flags duplicates. https://linear.app/docs/triage
- **SLAs**: rule-based (first matching rule), business-day aware; statuses Low/Medium/High risk, Breached, Achieved, Failed; notifications 24h before and on breach. https://linear.app/docs/sla
- **Board**: section 5. **MCP**: section 6.

### Intercom
- **Model**: conversations (states open/snoozed/closed; parts; notes; @mentions; tags; attributes; team inboxes; SLAs; ratings) and tickets (types Customer, Back-office [internal, optionally shared], Tracker [one-to-many, never shared]) with four state categories Submitted, In progress, Waiting on customer (pauses SLA), Resolved, plus custom states; customer reply moves Waiting→In progress; tickets link to conversations and other tickets. No multi-level priority; conversations carry a priority flag **(from API memory, unverified)**. https://www.intercom.com/help/en/articles/9730130-how-ticket-states-work , https://www.intercom.com/help/en/articles/6436600-tickets-explained , https://www.intercom.com/help/en/articles/410-helpdesk-glossary
- **Automation**: Workflows (trigger → conditions → actions incl. open/close tickets), assignment round-robin or balanced, SLAs first response / next response / resolution with business hours, macros.
- **Views**: inbox views/folders; no kanban. **KB**: Help Center. **CSAT**: conversation ratings.
- **MCP**: `https://mcp.intercom.com/mcp` (section 6). **AI**: Fin AI Agent (autonomous), Copilot (answers, suggested replies, summaries). https://www.intercom.com/help/en/articles/8587194-how-to-use-copilot

### FreeScout / UVdesk (open-source data models)
- **FreeScout** (Help Scout clone, Laravel): Conversation {type email/phone/chat; status active/pending/closed/spam; state draft/published/deleted; mailboxId; assignee; customer; cc/bcc; tags; customFields; threads}; Thread {type customer/message/note/lineitem; status; state; source; body; attachments}; Customer, Mailbox (folders), User, Tag, CustomField (singleline/multiline/dropdown/date/number), Timelog. Webhooks: convo.created/assigned/deleted/moved/status, convo.customer.reply.created, convo.agent.reply.created, convo.note.created, customer.created/updated. https://api-docs.freescout.net/
- **UVdesk** (Symfony): entities Ticket, TicketStatus, TicketPriority, TicketType, Thread, Attachment, Tag, SupportLabel, SupportGroup, SupportTeam, SupportRole, SupportPrivilege, SavedReplies, SavedFilters, EmailTemplates, TicketRating, User/UserInstance, Website, AgentActivity. Ticket fields: source, mailboxEmail, subject, isNew/isReplied/isReopened/isStarred/isTrashed/isAgentViewed/isCustomerViewed, customerRepliedAt, responseSlaLevel, resolveSlaLevel; relations status, priority, type, customer, agent, supportGroup, supportTeam, collaborators (M:N), tags, labels, threads, ratings. https://github.com/uvdesk/core-framework/tree/master/Entity

---

## 2. Status workflows (exact state names)

| System | States | Notes |
|---|---|---|
| Zendesk | New → Open ↔ Pending / On-hold → Solved → Closed | Custom statuses map to categories new/open/pending/hold/solved; Closed terminal (follow-up ticket) |
| Freshdesk | Open ↔ Pending → Resolved → Closed (+ custom) | Customer reply reopens; Pending pauses SLA |
| JSM (default service workflow) | Open, Reopened, Pending, Work in progress, Waiting for customer, Waiting for support, Escalated, Done, Canceled | Fully configurable per workflow; separate Resolution field |
| ServiceNow Incident | New(1), In Progress(2), On Hold(3) [reason: Awaiting Caller/Change/Vendor/Problem], Resolved(6), Closed(7), Canceled(8) | Closed auto after N days in Resolved |
| Help Scout / FreeScout | active, pending, closed, spam | Shared-inbox model |
| osTicket | Open, Resolved, Closed, Archived, Deleted | Status has underlying `state` open/closed/archived/deleted + reopen rules |
| Zammad | new, open, pending reminder, pending close, closed, merged | Pending states carry a `pending_time`; escalation frozen while pending/closed |
| HubSpot | New, Waiting on contact, Waiting on us, Closed | Pipeline stages; multiple pipelines |
| Linear | Triage → Backlog → Todo → In Progress → Done / Canceled / Duplicate | Categories fixed; custom statuses inside categories |
| Intercom tickets | Submitted → In progress ↔ Waiting on customer → Resolved (+ custom per category) | Conversations separately open/snoozed/closed |

## 3. Priority schemes

| System | Values | Derivation |
|---|---|---|
| Zendesk | Low, Normal, High, Urgent | Manual / triggers / AI |
| Freshdesk | Low, Medium, High, Urgent (fixed) | Drives SLA targets |
| JSM | Lowest, Low, Medium, High, Highest (customizable scheme) | Manual / automation |
| ServiceNow | P1 Critical … P5 Planning (read-only) | Impact × Urgency matrix |
| Help Scout / FreeScout | none native | Tags/custom fields |
| osTicket | Low, Normal, High, Emergency (table-driven, `priority_urgency`) | Help topic / dept defaults |
| Zammad | 1 low, 2 normal, 3 high (+custom) | Manual / triggers |
| HubSpot | Low, Medium, High, Urgent **(unverified exact labels)** | Property |
| Linear | No priority, Low, Medium, High, Urgent | Drives default SLAs |
| Intercom | priority flag only | — |

---

## 4. Superset feature list (union across systems)

**Ticket**: id + human number; subject/title; description; status (category + custom status within category; terminal "closed" distinct from "solved/resolved"; pending/on-hold with reason and optional `pending_until`); priority; type (question/incident/problem/task/request); source channel; requester, submitter, assignee, group/team, organization/company, brand/mailbox; CCs/followers/collaborators/watch list; tags; custom fields + per-type forms (dynamic/conditional fields); due date; SLA timestamps (first_response_at, next_response_due, resolution_due, escalation_at, breach flags); relations: parent/child, linked/related, blocks/blocked-by, incident→problem (tracker), duplicate-of, merged-into; follow-up ticket after close; external_id; audit/event log of field changes; locks/collision detection; time tracking.

**Conversation**: public reply vs internal note; message types (email, phone, chat, web, note, system/line-item); attachments; @mentions; canned responses/saved replies/text modules; macros (reply + field changes in one action); side conversations (child threads to third parties); drafts; signatures; rich text; redaction.

**People**: customers (portal accounts), organizations/companies with domain mapping and shared ticket visibility; agents, light agents/collaborators (internal notes only), admins; groups/departments (nested), teams, roles with granular permissions; skills; capacity/availability status; on-call/triage responsibility rotation.

**Automation**: event triggers (on create/update with conditions → actions); time-based automations/scheduler; assignment rules (round robin, load-based, skill-based, capacity limits); help-topic/form defaults (dept, priority, SLA, assignee); inbound filters (spam, routing); macros; webhooks; business hours calendars; approvals (JSM/ServiceNow); AI actions as automation steps (Zammad).

**SLA**: policies by condition (priority, type, org, group…); metrics: first reply, next reply, periodic/pausable update, requester wait, agent work, total resolution; pause states; calendars; grace periods; escalation levels/notifications; risk status (Linear's Low/Medium/High risk, Breached, Achieved, Failed); SLA visible on list, card, and ticket.

**Views & boards**: saved filtered lists (shared/personal), queues, folders; sort/group; bulk actions; kanban board grouped by status (also priority/assignee/group/custom field); swimlanes; WIP/column limits; manual rank within column; card customization; calendar view; dashboards; reporting metrics: volume, first response time, resolution time, SLA attainment, backlog/age, reopen rate, CSAT, agent workload/productivity, AI containment/deflection.

**KB / self-service**: articles with categories/collections, agent-side article suggestions, insert-link in reply, AI answers over KB, portal with ticket status tracking, article-from-ticket.

**CSAT**: survey on solve/resolve (delay, 28-day window), configurable scale (good/bad, 1–5, stars, Great/Okay/Not Good), bad-rating reasons, free-text comment, per-agent/team reporting; NPS/CES (HubSpot).

**API / MCP**: REST + webhooks; OAuth 2.1 remote MCP servers (Atlassian, HubSpot, Intercom, Linear, Help Scout, Freshworks); read-only vs read-write endpoints (Linear); rate/annual action limits (Freshdesk); confirmation for writes (HubSpot `manage_crm_objects`); role-scoped tool packages (ServiceNow).

**AI**: auto-triage (intent/category, sentiment, language, priority, group), duplicate/related detection (Linear), summaries on handoff/reassignment, resolution-note generation, suggested replies grounded in KB, tone/expand/translate, autonomous AI agent with escalation, AI actions inside triggers.

---

## 5. Kanban patterns in service desks

- **JSM**: since July 2025 any queue can be shown as a list, board, or calendar; board columns = workflow statuses, with swimlanes, card colours, quick filters and multiple boards; dragging a card performs the workflow transition. (Older Confluence doc still says "service projects have no boards"—superseded.) Column min/max limits exist on Jira Software boards; whether JSM queue boards expose them is **unverified**. https://community.atlassian.com/forums/Jira-Service-Management-articles/Board-and-calendar-views-now-available-in-queues/ba-p/3077812 , https://support.atlassian.com/jira-service-management-cloud/docs/get-started-with-boards/
- **Linear**: any issue view can be a board; columns default to Status but may be Project, Priority, Cycle, Label, SLA status; swimlanes by team/cycle/assignee/initiative/project health; board order is shared with list order; keyboard moves put the card at top of the column, mouse drops respect position; board not available in Triage/Inbox; no WIP limits; descriptions not shown on cards. Triage is a separate inbox with Accept/Decline/Duplicate/Snooze. https://linear.app/docs/board-layout
- **Zendesk** (no native board): SweetHawk Kanban (late 2025, $5/agent): columns from any field (status, custom field, group), per-column WIP limits that visually flag breaches, cards convertible to tickets, filters by assignee/group/brand. Kanban Pro (GrowthDot): status columns, >10 personal/shared boards, WIP limits, apply macros from board. Kabana (OSS): one board per group, one column per status. https://sweethawk.com/zendesk-apps/kanban , https://growthdot.com/zendesk-kanban-pro/ , https://github.com/rgueldem/kabana
- **Freshdesk**: marketplace Kanban Board app (free) and Kanban Board Pro (up to 10 boards × 15 columns; group by status/agent/priority/group/type/custom field/tags; swimlanes; SLA colour strips on cards; inline reply/note; optimistic UI with rollback). Freshworks announced a native kanban in the community ("coming soon"); ship status **unverified**. Sibling Freshservice has a native board: columns = statuses, vertical drag to stack-rank, column (WIP) limit highlights overflow, filters, private/shared boards. https://www.taroo.in/apps/kanban-board-pro/ , https://support.freshservice.com/support/solutions/articles/50000004013-kanban-board-for-tickets-service-requests
- **HubSpot**: board layout per pipeline in the help desk workspace; drag = status change; card settings per view. https://knowledge.hubspot.com/records/set-up-automations-in-the-updated-board-view
- **Zammad**: lists only; kanban is an open feature request (#363). **ServiceNow**: Visual Task Boards (general knowledge). **Help Scout / Intercom / osTicket**: no board.
- **Trello/SendBoard help-desk templates**: Backlog → To Do → In Progress → Awaiting Response → Ready for Review → Done (or Inbox / Responded-Scheduled / In Progress / Awaiting Feedback); swimlanes by priority, query type, or support tier. https://www.sendboard.com/blog/ultimate-kanban-template-help-desk

**Common semantics to adopt**: column = one value of the grouping field (default status); drag across columns = set that field (status transitions may be gated by workflow rules and may prompt for required fields, e.g. pending-until or resolution); drag within column = manual rank (persist a per-board position); WIP limit = soft, highlight column; cards show number, subject, requester/org, priority, assignee avatar, SLA/age indicator, tags, unread/customer-replied marker; closed/solved column capped to recent N or hidden; swimlanes by assignee, priority, group, or organization; multiple saved boards (personal/shared) built on top of saved filters.

---

## 6. Vendor MCP servers and exposed tools

| Vendor | Endpoint / auth | Tools (names) |
|---|---|---|
| **Atlassian Rovo MCP** (Jira/JSM) | Cloud-hosted; OAuth 2.1 or API token; JSM tools API-token only | Jira read: `getJiraIssue, listJiraProjects, listJiraProjectIssueTypesMetadata, getJiraIssueTypeMetaWithFields, listJiraIssueTransitions, listJiraIssueLinkTypes, listJiraIssueRemoteIssueLinks, listJiraIssueWorklogs, lookupJiraAccountId, listJiraIssueAssignableUsers, listJiraIssueComments, listJiraIssueChangelogs, getJiraCurrentUser, getJiraUser, listJiraStatuses, listJiraProjectComponents, getJiraProjectVersions, listJiraBoards, getJiraBoardConfig, getJiraBoardIssueData, listJiraFilters, listJiraDashboards, downloadJiraIssueAttachment`; write: `createJiraIssue, editJiraIssue, transitionJiraIssue, addOrEditJiraIssueComment, addOrEditJiraIssueWorklog, createJiraIssueLink, manageJiraSprint, createJiraBoard, watchJiraIssue, uploadAttachmentToJiraIssue, createJiraIssueRemoteIssueLink, convertJiraIssueHierarchy`; search: `searchJiraIssuesUsingJql`; delete (off by default): `deleteJiraIssue, deleteJiraComment, deleteJiraIssueAttachment`; JSM: `getJsmOpsAlerts, getJsmOpsScheduleInfo, getJsmOpsTeamInfo, updateJsmOpsAlert` (ops/on-call only—no request/queue/SLA tools). https://developer.atlassian.com/cloud/rovo-mcp/guides/supported-tools/ |
| **Freshworks (Freshdesk)** | GA 2026-09-10; API key | Listed in §1 Freshdesk (tickets, conversations, contacts, agents, groups, companies, solutions). https://support.freshdesk.com/support/solutions/articles/50000012670 |
| **HubSpot** | `mcp.hubspot.com`, OAuth 2.0 PKCE | `get_user_details, get_organization_details, discover_hubspot_schema, search_crm_objects, get_crm_objects, manage_crm_objects (create/update incl. tickets, with user confirmation), manage_segment, query_crm_data (SQL), search_properties, get_properties, manage_custom_properties, manage_custom_pipelines, search_owners, search_conversations, get_conversation_channel_metadata, tool_guidance, submit_feedback` + marketing/content tools. https://developers.hubspot.com/docs/apps/developer-platform/build-apps/integrate-with-the-remote-hubspot-mcp-server |
| **Intercom** | `https://mcp.intercom.com/mcp`; US-hosted only | `search_conversations, get_conversation, search_contacts, get_contact, list_companies, get_company, search, fetch, list_articles, search_articles, get_article, create_article, update_article` — read-only for conversations; cannot reply or create tickets. https://www.speakeasy.com/use-cases/mcp-governance/catalog/intercom , https://www.usecarly.com/blog/intercom-mcp/ |
| **Linear** | `https://mcp.linear.app/mcp` (+ `/mcp/readonly`; `/sse` deprecated); OAuth 2.1, bearer/API key, Okta | Official docs list capabilities not names ("finding, creating, updating issues, projects, comments"; 2026 adds initiatives, milestones, project updates/labels). Gateway listings show `list_issues, get_issue, create_issue, update_issue, list_comments, create_comment, create_project, get_project, list_projects, update_project, create_issue_label, list_issue_labels, list_teams, get_team, list_users, get_user` **(names unverified against official source)**. https://linear.app/docs/mcp , https://www.mintmcp.com/linear |
| **Help Scout** | `https://mcp.helpscout.net/mcp`; OAuth; read-only GA, write in beta | Categories: conversations/threads, customers, inboxes/folders/saved replies, users/teams, workflows, reports, Docs (names unverified). https://docs.helpscout.com/article/1779-connect-your-ai-agent-with-help-scout-to-search-conversations-and-pull-reports |
| **ServiceNow** | MCP Server Console (Zurich); OAuth; role-based tool packages; Now Assist SKU | Tools are admin-defined from Now Assist skills, flows, scripted REST, playbooks; no fixed public tool list. Community servers expose e.g. `create_incident, update_incident`. https://www.servicenow.com/docs/bundle/zurich-intelligent-experiences/page/administer/mcp-server-console/concept/mcp-server-console-landing.html |
| **Zendesk** | MCP *client* EAP (June 2026); MCP *server* announced May 2026, not shipped as of Sept 2026 | Third-party only (Swifteq marketplace; OSS servers covering tickets, comments, users, orgs, groups, macros, views, triggers). https://support.zendesk.com/hc/en-us/articles/10889847373466 , https://tldv.io/blog/zendesk-mcp/ |
| Zammad / osTicket | community only | e.g. https://github.com/jamesdeane2/Zammad-MCP |

Design takeaways for the MCP surface: separate read-only and read-write endpoints (Linear); explicit `list_transitions` + `transition` rather than free status writes (Atlassian); bulk update tool (Freshdesk `createTicketBulkUpdate`); reply vs note as distinct tools (Freshdesk `replyTicket`/`createTicketNote`); schema/field discovery tools (HubSpot `discover_hubspot_schema`, `get_properties`; Atlassian `getJiraIssueTypeMetaWithFields`); confirmation gate on writes (HubSpot); rate and action quotas per tenant.

---

## 7. Recommended features for the new system

**Must-have**
- Ticket core: number, subject, description, status with category (new/open/pending/on-hold/solved/closed) + custom statuses per category, pending_until, priority (4 levels), type, source, requester/organization, assignee + group, CCs/followers, tags, custom fields with per-type forms, due date, audit trail, merge, parent/child + linked + duplicate relations.
- Conversation: public reply vs internal note, attachments, @mentions, canned responses, macros.
- People: customers, organizations, agents, light agents (notes only), admins, groups, roles/permissions, availability/capacity.
- Intake: email (inbound parse + threading), web form/portal, REST API, MCP.
- Automation: event triggers, time-based automations, assignment (manual, round-robin, load-based), business-hours calendar, SLA policies (first reply, next reply, resolution; pause on pending) with breach flags and escalation triggers.
- Views: saved filters (shared/personal), list + kanban over any saved filter (columns by status default; drag = transition; manual rank; soft WIP limits; swimlanes by assignee/priority/group; closed column capped), per-agent workload counts.
- Reporting basics: volume, FRT, resolution time, SLA attainment, backlog age, CSAT.
- CSAT: survey on solve with configurable scale + comment + bad-rating reasons.
- MCP server: read-only and read-write endpoints; tools for search/list/get/create/update ticket, list_transitions/transition, reply, add_note, assign, tag, merge, link, list_views/get_view_tickets, get_board, move_card, KB search; schema discovery; write confirmation metadata; per-tenant rate limits; webhooks.

**Should-have**
- Triage inbox with Accept/Decline/Duplicate/Snooze and triage rotation (Linear pattern); skill-based routing; side conversations; problem/incident (tracker) tickets that close children; approvals; time tracking; KB with article suggestions and insert-link; portal ticket status tracking; AI auto-triage (category/priority/sentiment/language), summaries on handoff, suggested replies grounded in KB; SLA risk statuses (at-risk/breached/achieved) on cards.

**Later**
- Calendar view; NPS/CES; AI agent autonomous resolution with escalation; MCP client (calling external tools from automations, Zendesk pattern); role-scoped MCP tool packages (ServiceNow pattern); multi-brand/multi-mailbox; Visual Task Board–style freeform boards unrelated to status; omnichannel chat/voice.
