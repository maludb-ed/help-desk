# Help Desk Action Manifest

2026-10-03 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve
> against. A screen or action missing here cannot be reached by voice or by an agent — unfinished design, like a question
> with no tool. Pairs with `db/` (tables), `docs/helpdesk-mcp-tool-surface.md` (read tools) and the slice specs in
> `docs/build-specs/`. Generated into `mcp/action_registry.json` by `bin/build_action_registry.php`; an action whose file
> does not exist yet is registered but not exposed.

## Conventions

### Screens
- **Ids** are kebab-case `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-helpdesk.conf`: `/{x}/new` → `form.php`, `/{x}/{id}/edit` →
  `form.php?id=`, `/{x}/{id}` → `view.php?id=`. A requester screen is designed at 375 px first; every screen is a page,
  never a modal.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id` on `#page-content`, so "that", "this
  ticket" and "assign it to Marco" resolve against the page the user is on.
- **Public doors** (`/request`, `/t/{token}`, `/kb`) are screens with no session; their POSTs are **not actions** (no agent
  reaches them) and are listed apart at the end.

### Actions
- **Name** `{entity}_{verb}`; the **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with
  `ticket_id` and `queue_id`) and what the kernel's approval policies match — so an action an agent's call must pause on
  has **its own log event**. `.delete` destroys a record; `.remove` detaches something; `.archive` keeps it out of the way.
- **Endpoints** are POST to `/{base}/{file}` with `require_post()`, `verify_csrf()` (or the relayed action token), the
  right, the ticket's visibility (`require_can_see_ticket`) or workability (`require_can_work_ticket`), and `log_activity()`.
  Handlers report through `emit_action_status()`; a create's `location` ends in the new record's id.
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the
  last parameter). A param named for a record (`ticket`, `queue`, `assignee`, `member`, `requester`, `organization`,
  `status`, `category`, `type`, `canned`, `article`, `view`, `policy`, `rule`, `mailbox`, …) accepts an id or a name,
  resolved through Help Desk's own tool (`docs/helpdesk-mcp-tool-surface.md`, resolution table); a `ticket` also accepts its
  number. Times are ISO 8601 in the caller's zone or UTC. `any field of X` = X's fields, all optional, a partial update.
- **Who** is enforced in the endpoint: `own` = the record's own person (its requester, its author, the token's owner);
  `member` = any admitted member (file a ticket, follow); `queue` = `tickets.view_queue` in the ticket's queue; `note` =
  `tickets.note` in the queue; `work` = `tickets.work` in the ticket's queue, or `tickets.manage`; `manage` =
  `tickets.manage`; `lead` = `queues.members` / `views.shared` / `kb.edit` / `reports.view` as the row says; `admin` =
  `settings.manage`. A super-admin holds every right. An agent is granted like a person.
- **Undo** is the inverse `undo_last` applies; — means it cannot be undone and the reply says so.
- **Confirm (✔)** = the command bar asks first (destructive, or it reaches other people).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it: `external_send`
  (words leave the building — a public reply, a canned reply, an article sent, a survey), `deletion`, `other` (merges, an
  early close, bulk changes, configuration). People are never paused. The categories are exactly `maludb-os.json`
  `approvals[]`.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in `docs/helpdesk-mcp-tool-surface.md`; **a message body, a
  secure token, a token value or another requester's address is never in a payload.**

### Result contract
```json
{"status": "success", "did": "Assigned HD-1042 to Marco", "record_id": 1042, "undo_id": "act_31", "refresh": "ticketChanged"}
{"status": "pending_approval", "did": "The reply on HD-1042 waits for a person's approval", "approval_request_id": 17}
{"status": "error", "message": "HD-1042 cannot move from Closed."}
```

## Home, my tickets and settings

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `dashboard` | `/` | their home: what needs them (support), their open tickets (anyone), a new request button, the knowledge base search |
| `my-tickets` | `/my-tickets` | the tickets they asked for, filed, are cc'd on or follow, by state (params: `state`) |
| `settings` | `/settings/` | how they are told (email, text, which events) (params: `tab`) |
| `tokens` | `/settings/tokens/` | their own access tokens for the two MCP servers |
| `notifications` | `/notifications` | their in-app notifications, unread first |
| `activity` | `/activity` | their own recent activity, or one ticket's history (params: `ticket`, `period`) |
| `general-settings` | `/settings/general` | the business's Help Desk settings: numbering prefix, auto-close and reopen windows, secure-link life, the default queue, the request form on or off, organization visibility |
| `agents` | `/settings/agents` | which agents work here, their queues, their last dispatch and run (read-only; hiring and grants link to the kernel) |

Actions (base `/settings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `prefs_save` | `prefs.php` | email_enabled (yes or no), text_enabled (yes or no), kinds[] (the events to be told about), text_kinds[] (the events to be texted about), digest (yes or no) | restore prior | | | `prefs.save` | own |
| `token_mint` | `tokens/mint.php` | **label**, scope (mcp or api) | revoke it | | | `token.mint` | own |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own |
| `notification_read` | `notifications/read.php` | notification (empty marks all read) | — | | | `notification.read` | own |
| `settings_save` | `general.php` | ticket_prefix (capital letters and digits up to 8), auto_close_days (0 to 365), reopen_window_days (0 to 365), secure_link_days (1 to 3650), default_queue, request_form_enabled (yes or no), org_visibility_default (yes or no), timezone | restore prior | | other | `settings.save` | admin |

## Organizations and requesters

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `organizations-list` | `/organizations/` | the customer companies as cards with tier and open tickets (params: `q`, `tier`) |
| `organization-add` | `/organizations/new` | to add a customer company |
| `organization-view` | `/organizations/{id}` | one organization: domains, people, tickets, SLA, settings |
| `organization-edit` | `/organizations/{id}/edit` | to change an organization |
| `requesters-list` | `/requesters/` | the people who ask — externals, members, agents — as cards (params: `q`, `kind`, `organization`) |
| `requester-view` | `/requesters/{id}` | one requester: contact details, organization, their tickets, notes, their addresses |
| `requester-edit` | `/requesters/{id}/edit` | to change a requester's details or organization |
| `requester-add` | `/requesters/new` | to add an external requester by hand (params: `organization`) |

Actions (base `/organizations/`, requesters under `/requesters/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `organization_create` | `/organizations/save.php` | **name**, support_tier, domains[] (email domains that claim new requesters), policy (an SLA override), members_see_each_other (yes or no or empty for the default), notes, tags[] | organization_archive | | | `organization.create` | queue |
| `organization_update` | `/organizations/save.php` | **organization**, any field of organization_create | restore prior | | | `organization.update` | queue |
| `organization_archive` | `/organizations/archive.php` | **organization** | restore | ✔ | | `organization.archive` | manage |
| `requester_create` | `/requesters/save.php` | **display_name**, **email**, phone, organization, locale, timezone, notes | — | | | `requester.create` | queue |
| `requester_update` | `/requesters/save.php` | **requester**, any field of requester_create | restore prior | | | `requester.update` | queue |
| `requester_email_add` | `/requesters/email.php` | **requester**, **email** | requester_email_remove | | | `requester.email_add` | queue |
| `requester_email_remove` | `/requesters/email-remove.php` | **requester**, **email** | requester_email_add | ✔ | | `requester.email_remove` | queue |
| `requester_merge` | `/requesters/merge.php` | **requester** (the one that goes away), **into** (the one that stays) | — | ✔ | other | `requester.merge` | manage |

## Queues

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `queues-list` | `/settings/queues/` | the queues as cards: department, members, open count, assignment method |
| `queue-add` | `/settings/queues/new` | to add a queue |
| `queue-view` | `/settings/queues/{id}` | one queue: its members and leads, defaults, mailbox, policy, hours |
| `queue-edit` | `/settings/queues/{id}/edit` | to change a queue |

Actions (base `/settings/queues/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `queue_create` | `save.php` | **name**, key (letters and underscores or empty to derive it), description, department (a kernel department whose members join the queue), include_department (yes or no), assignment_method (manual or round_robin or least_loaded or default_assignee), default_assignee (a person or an agent), schedule (a business-hours schedule), policy (an SLA policy), email_address, sort_order | queue_archive | | other | `queue.create` | admin |
| `queue_update` | `save.php` | **queue**, any field of queue_create | restore prior | | other | `queue.update` | admin |
| `queue_archive` | `archive.php` | **queue** (its open tickets move to the default queue) | restore | ✔ | other | `queue.archive` | admin |
| `queue_member_add` | `member.php` | **queue**, **member** (a person or an agent), is_lead (yes or no) | queue_member_remove | | | `queue.member_add` | lead |
| `queue_member_remove` | `member-remove.php` | **queue**, **member** | queue_member_add | ✔ | | `queue.member_remove` | lead |
| `queue_presence_set` | `presence.php` | availability (online or away or offline), capacity (max open tickets or empty) | restore prior | | | `queue.presence` | own |

## Service levels and business hours

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `sla-list` | `/settings/sla/` | the SLA policies in match order and the business-hours schedules |
| `sla-policy-add` | `/settings/sla/new` | to add a policy |
| `sla-policy-edit` | `/settings/sla/{id}/edit` | to change a policy's conditions, targets or threshold |
| `business-hours-edit` | `/settings/sla/hours` | to change a schedule's weekly hours and holidays (params: `schedule`) |

Actions (base `/settings/sla/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `sla_policy_create` | `save.php` | **name**, description, sort_order (lower matches first), organizations[] (match these organizations or any), queues[] (match these queues or any), types[] (match these types or any), priorities[] (match these priorities or any), tags[] (match tickets carrying any of these), targets (per priority the minutes for first_response and next_response and resolution as JSON), schedule (business hours or empty for calendar time), warning_percent (1 to 99) | sla_policy_archive | | other | `sla_policy.create` | admin |
| `sla_policy_update` | `save.php` | **policy**, any field of sla_policy_create | restore prior | | other | `sla_policy.update` | admin |
| `sla_policy_archive` | `archive.php` | **policy** | restore | ✔ | other | `sla_policy.archive` | admin |
| `business_hours_save` | `hours.php` | schedule (to change an existing one), **name**, **timezone**, intervals (weekday and opens and closes as JSON), is_default (yes or no) | restore prior | | other | `business_hours.save` | admin |
| `holiday_add` | `holiday.php` | **schedule**, **holiday** (a date), name | holiday_remove | | | `business_hours.holiday_add` | admin |
| `holiday_remove` | `holiday-remove.php` | **schedule**, **holiday** (a date) | holiday_add | | | `business_hours.holiday_remove` | admin |

## The vocabulary: statuses, transitions, types, categories, tags, custom fields

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `statuses-list` | `/settings/statuses/` | the statuses in board order with their category and clock, and the transitions matrix |
| `status-add` | `/settings/statuses/new` | to add a custom status inside open or pending or on hold or solved |
| `status-edit` | `/settings/statuses/{id}/edit` | to rename or recolour a status, change its order, whether it is on the board, whether the clock runs |
| `transitions` | `/settings/statuses/transitions` | the matrix of allowed moves between categories and what each requires |
| `vocabulary` | `/settings/vocabulary/` | the ticket types, the categories tree with routing defaults, and the tags (params: `tab`) |
| `category-add` | `/settings/vocabulary/categories/new` | to add a category (params: `parent`) |
| `category-edit` | `/settings/vocabulary/categories/{id}/edit` | to change a category's parent, defaults or visibility |
| `fields-list` | `/settings/fields/` | the custom field definitions for tickets, organizations and requesters |
| `field-add` | `/settings/fields/new` | to add a custom field (params: `applies_to`) |
| `field-edit` | `/settings/fields/{id}/edit` | to change a custom field |

Actions (base `/settings/statuses/`, the vocabulary under `/settings/vocabulary/`, fields under `/settings/fields/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `status_save` | `/settings/statuses/save.php` | status (to change an existing one), **label**, category (open or pending or on_hold or solved for a new one), color (primary or secondary or success or info or warning or danger or dark), sort_order, on_board (yes or no), clock_runs (yes or no) | restore prior | | other | `status.save` | admin |
| `status_archive` | `/settings/statuses/archive.php` | **status** (a custom one with no open tickets) | restore | ✔ | other | `status.archive` | admin |
| `transition_save` | `/settings/statuses/transition.php` | **from_category**, **to_category**, allowed (yes or no), requires[] (resolution_code or hold_reason), may_carry[] (pending_until or assignee), needs_right (tickets.work or tickets.manage), label | restore prior | | other | `transition.save` | admin |
| `type_save` | `/settings/vocabulary/type.php` | type (to change an existing one), **label**, description, sort_order | restore prior | | other | `type.save` | admin |
| `type_archive` | `/settings/vocabulary/type-archive.php` | **type** | restore | ✔ | other | `type.archive` | admin |
| `category_save` | `/settings/vocabulary/category.php` | category (to change an existing one), **name**, parent, description, default_queue, default_type, default_priority (low or normal or high or urgent), policy, requester_visible (yes or no), sort_order | restore prior | | other | `category.save` | admin |
| `category_archive` | `/settings/vocabulary/category-archive.php` | **category** | restore | ✔ | other | `category.archive` | admin |
| `tag_save` | `/settings/vocabulary/tag.php` | tag (to rename an existing one), **label**, slug | restore prior | | | `tag.save` | manage |
| `tag_delete` | `/settings/vocabulary/tag-delete.php` | **tag** (removed from every ticket) | — | ✔ | deletion | `tag.delete` | admin |
| `field_save` | `/settings/fields/save.php` | field (to change an existing one), **label**, key (letters and underscores or empty to derive it), **field_type** (text or number or date or select or multi_select or checkbox), options[] (for select and multi_select), **applies_to** (ticket or organization or requester), types[] (show for these ticket types only), required_on_solve (yes or no), requester_visible (yes or no), sort_order | restore prior | | other | `field.save` | admin |
| `field_archive` | `/settings/fields/archive.php` | **field** | restore | ✔ | other | `field.archive` | admin |

## Tickets

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `inbox` | `/inbox` | the tickets of their queues as a list through a saved view or ad-hoc filters, with needs-reply and SLA (params: `view`, `q`, `status`, `queue`, `assignee`, `priority`, `sort`) |
| `ticket-add` | `/tickets/new` | to file a ticket for themself, on behalf of someone, or from an email (params: `requester`, `queue`, `category`, `on_behalf_of`, `from_inbound`) |
| `ticket-view` | `/tickets/{id}` | one ticket: the conversation and composer, the fields, the requester card, followers, links, time, SLA, the timeline |
| `ticket-edit` | `/tickets/{id}/edit` | to change a ticket's subject, requester, organization, type, category, custom fields or due date |
| `ticket-merge` | `/tickets/{id}/merge` | to merge this ticket into another (params: `into`) |

Actions (base `/tickets/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `ticket_create` | `save.php` | **subject**, **body** (the first message), requester (a requester or an email or empty for the caller), on_behalf_of (a member the ticket is for), queue (empty routes by category then the default), category, type, priority (low or normal or high or urgent), tags[], custom (field values as JSON), due_at, external_id (a monitoring system's own key), cc[] (addresses or requesters), as_note (yes files the body as an internal note) | ticket_delete | | | `ticket.create` | member |
| `ticket_update` | `save.php` | **ticket**, subject, requester, organization, type, category, due_at, custom (field values as JSON), external_id | restore prior | | | `ticket.update` | work |
| `ticket_reply` | `reply.php` | **ticket**, **body**, attachments[] (attachment ids uploaded first), cc[] (add these to the ticket), then_status (pending or solved or open after sending), resolution_code (when then_status is solved) | — (a sent reply cannot be unsent) | | external_send | `ticket.reply` | work or own (the requester replies on their own) |
| `ticket_note` | `note.php` | **ticket**, **body**, mentions[] (members named), attachments[] | — | | | `ticket.note` | note or work |
| `ticket_transition` | `transition.php` | **ticket**, **status** (a status allowed from where it is), resolution_code (resolved or workaround or no_action or duplicate or spam or cannot_reproduce when moving to solved), hold_reason (awaiting_requester or awaiting_third_party or awaiting_internal or awaiting_problem when moving to on hold), pending_until (when snoozing), note | restore prior | | | `ticket.transition` | work |
| `ticket_assign` | `assign.php` | **ticket**, assignee (a person or an agent or me or none), queue (move it to another queue), note | restore prior | | | `ticket.assign` | work |
| `ticket_set_fields` | `fields.php` | **ticket**, priority (low or normal or high or urgent), type, category, due_at, custom (field values as JSON) | restore prior | | | `ticket.set_fields` | work |
| `ticket_add_tags` | `tags.php` | **ticket**, **tags[]** | ticket_remove_tag | | | `ticket.tag` | work |
| `ticket_remove_tag` | `untag.php` | **ticket**, **tag** | ticket_add_tags | | | `ticket.untag` | work |
| `ticket_follow` | `follow.php` | **ticket**, member (empty follows it oneself) | ticket_unfollow | | | `ticket.follow` | note or member on their own |
| `ticket_unfollow` | `unfollow.php` | **ticket**, member (empty is oneself) | ticket_follow | | | `ticket.unfollow` | own |
| `ticket_cc_add` | `cc.php` | **ticket**, **cc** (an email or a requester) | ticket_cc_remove | | | `ticket.cc_add` | work or own |
| `ticket_cc_remove` | `cc-remove.php` | **ticket**, **cc** | ticket_cc_add | | | `ticket.cc_remove` | work |
| `ticket_link` | `link.php` | **ticket**, **other_ticket**, **kind** (parent_child or related or duplicate_of or blocks or follow_up_of) | ticket_unlink | | | `ticket.link` | work |
| `ticket_unlink` | `unlink.php` | **ticket**, **other_ticket**, **kind** | ticket_link | | | `ticket.unlink` | work |
| `ticket_merge` | `merge.php` | **ticket** (the one that goes away), **merge_into** (the one that stays), note | — | ✔ | other | `ticket.merge` | manage |
| `ticket_close` | `close.php` | **ticket**, resolution_code (when it is not solved yet), note | ticket_reopen | ✔ | other | `ticket.close` | manage |
| `ticket_reopen` | `reopen.php` | **ticket**, note | — | | | `ticket.reopen` | work or own (within the window) |
| `ticket_confirm` | `confirm.php` | **ticket** (the requester confirms it is resolved) | ticket_reopen | | | `ticket.confirm` | own |
| `ticket_spam` | `spam.php` | **ticket** (solved as spam and the requester's address suppressed) | ticket_reopen | ✔ | deletion | `ticket.spam` | manage |
| `ticket_delete` | `delete.php` | **ticket** (gone for good with its messages) | — | ✔ | deletion | `ticket.delete` | admin |
| `ticket_log_time` | `time.php` | **ticket**, **minutes** (1 to 1440), billable (yes or no), note, worked_on (a date) | time_entry_delete | | | `ticket.log_time` | work |
| `time_entry_delete` | `time-delete.php` | **entry** | — | ✔ | | `ticket.time_delete` | own or manage |
| `attachment_add` | `attachments/save.php` | **ticket**, **file** (multipart), message (to attach to an existing message) | attachment_delete | | | `ticket.attach` | work or own |
| `attachment_delete` | `attachments/delete.php` | **attachment** | — | ✔ | deletion | `ticket.attachment_delete` | manage |
| `secure_link_rotate` | `secure-link.php` | **ticket** (a new link for the requester and the old one dead) | — | ✔ | | `ticket.secure_link_rotate` | work |
| `ticket_dispatch` | `dispatch.php` | **ticket** (handed again to its agent assignee for one turn) | — | | | `ticket.dispatch` | work |

## Views and the board

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `board` | `/board` | a saved view as columns — status by default — drag to change status, with WIP and swimlanes (params: `view`, `swimlane`, `assignee`, `priority`, `q`) |
| `views-list` | `/views/` | the saved views and boards they may open: standard, their own, their queues' |
| `view-add` | `/views/new` | to save the current filters as a view or a board (params: `layout`, `from`) |
| `view-edit` | `/views/{id}/edit` | to change a view's filters, columns, layout or board settings |

Actions (base `/views/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `view_create` | `save.php` | **name**, scope (personal or queue or global), queue (for a queue view), filters (JSON over the bounded vocabulary), sort_by, sort_dir (asc or desc), columns[], layout (list or board), board (column_field and swimlane and wip and solved_days as JSON) | view_delete | | | `view.create` | `views.personal` or lead for shared |
| `view_update` | `save.php` | **view**, any field of view_create | restore prior | | | `view.update` | own or lead for shared |
| `view_delete` | `delete.php` | **view** (never a standard one) | — | ✔ | | `view.delete` | own or lead for shared |
| `board_move_card` | `board/move.php` | **ticket**, **column** (the status or other column value to drop into), position (the rank within the column), resolution_code, hold_reason, pending_until | restore prior | | | `board.move` | work |
| `board_reorder` | `board/reorder.php` | **ticket**, **position** (the rank within its column) | restore prior | | | `board.reorder` | work |

## Working faster: canned responses and bulk changes

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `canned-list` | `/canned/` | the canned responses by folder: personal, their queues', global (params: `q`, `folder`) |
| `canned-add` | `/canned/new` | to write a canned response with placeholders and side-effects |
| `canned-edit` | `/canned/{id}/edit` | to change a canned response |
| `bulk-update` | `/bulk` | to change several tickets at once from a list selection (params: `tickets`) |

Actions (base `/canned/`, bulk under `/bulk/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `canned_create` | `/canned/save.php` | **title**, **body**, folder, scope (personal or queue or global), queue (for a queue response), side_effects (status and priority and assign and add_tags as JSON) | canned_delete | | | `canned.create` | work for personal; lead for queue and global |
| `canned_update` | `/canned/save.php` | **canned**, any field of canned_create | restore prior | | | `canned.update` | own or lead |
| `canned_delete` | `/canned/delete.php` | **canned** | — | ✔ | deletion | `canned.delete` | own or lead |
| `canned_apply` | `/canned/apply.php` | **ticket**, **canned**, send (yes sends it as a public reply and no returns the rendered text), apply_side_effects (yes or no) | — | | external_send | `canned.apply` | work |
| `tickets_bulk_update` | `/bulk/update.php` | **tickets[]**, status, assignee, queue, priority, add_tags[], remove_tags[], note | restore prior | ✔ | other | `ticket.bulk_update` | manage |

## Email and the inbound queue

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `mailboxes-list` | `/settings/mailboxes/` | the queues' mailboxes and their last poll |
| `mailbox-add` | `/settings/mailboxes/new` | to add a mailbox for a queue |
| `mailbox-edit` | `/settings/mailboxes/{id}/edit` | to change a mailbox's queue, signature or polling |
| `inbound-list` | `/settings/mailboxes/inbound` | the inbound mail that failed, bounced or was ignored, to retry or dismiss (params: `status`, `mailbox`) |

Actions (base `/settings/mailboxes/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `mailbox_create` | `save.php` | **address**, **display_name**, **queue**, webhook_secret_ref (the env key holding the webhook secret), signature, poll_enabled (yes or no) | mailbox_archive | | other | `mailbox.create` | admin |
| `mailbox_update` | `save.php` | **mailbox**, any field of mailbox_create | restore prior | | other | `mailbox.update` | admin |
| `mailbox_archive` | `archive.php` | **mailbox** | restore | ✔ | other | `mailbox.archive` | admin |
| `mailbox_poll_now` | `poll.php` | **mailbox** | — | | | `mailbox.poll` | admin |
| `inbound_retry` | `inbound/retry.php` | **inbound** (a failed message processed again) | — | | | `mail.inbound_retry` | admin |
| `inbound_ignore` | `inbound/ignore.php` | **inbound**, reason | — | | | `mail.inbound_ignore` | admin |

## Automation

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `rules-list` | `/settings/automation/` | the rules as cards: kind, event, run count, last run, on or off |
| `rule-add` | `/settings/automation/new` | to add a trigger or a time-based or an assignment rule (params: `kind`) |
| `rule-edit` | `/settings/automation/{id}/edit` | to change a rule's conditions or actions |
| `rule-runs` | `/settings/automation/{id}/runs` | what a rule did: the last 30 days of runs |

Actions (base `/settings/automation/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `rule_create` | `save.php` | **name**, **kind** (trigger or time_based or assignment), event (ticket.create or ticket.update or ticket.reply or ticket.note for a trigger), **conditions** (JSON over the bounded vocabulary), **actions** (JSON over the bounded vocabulary), sort_order, active (yes or no) | rule_delete | | other | `rule.create` | admin |
| `rule_update` | `save.php` | **rule**, any field of rule_create | restore prior | | other | `rule.update` | admin |
| `rule_toggle` | `toggle.php` | **rule**, active (yes or no) | restore prior | | other | `rule.toggle` | admin |
| `rule_delete` | `delete.php` | **rule** | — | ✔ | deletion | `rule.delete` | admin |
| `rule_run_now` | `run.php` | **rule** (a time-based rule run once now) | — | ✔ | other | `rule.run_now` | admin |

## Knowledge base

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `kb-home` | `/kb/` | the knowledge base: sections and a search; public to everyone, internal articles to members with kb.read (params: `q`) |
| `kb-section` | `/kb/s/{slug}` | one section's articles |
| `kb-article` | `/kb/{slug}` | one article; a public article needs no session |
| `kb-admin` | `/kb/admin/` | every article by status with its counts, and the sections, for editors (params: `status`, `section`) |
| `article-add` | `/kb/admin/new` | to write an article (params: `section`, `from_ticket`) |
| `article-edit` | `/kb/admin/{id}/edit` | to change an article |

Actions (base `/kb/admin/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `section_save` | `section.php` | section (to change an existing one), **name**, slug, parent, description, visibility (public or internal), sort_order | restore prior | | | `section.save` | lead |
| `section_archive` | `section-archive.php` | **section** | restore | ✔ | | `section.archive` | lead |
| `article_create` | `save.php` | **title**, **body**, section, slug, visibility (public or internal), tags[], from_ticket (the ticket it was written from) | article_delete | | | `article.create` | lead |
| `article_update` | `save.php` | **article**, any field of article_create | restore prior | | | `article.update` | lead |
| `article_publish` | `publish.php` | **article**, published (yes publishes and no returns it to draft) | restore prior | | | `article.publish` | lead |
| `article_archive` | `archive.php` | **article** | restore | ✔ | | `article.archive` | lead |
| `article_delete` | `delete.php` | **article** | — | ✔ | deletion | `article.delete` | admin |
| `article_suggest` | `link.php` | **ticket**, **article** (noted on the ticket as a suggestion) | — | | | `article.suggest` | work |
| `article_send` | `send.php` | **ticket**, **article** (a public reply with the article's link and summary), body (words before the link) | — | | external_send | `article.send` | work |

## Requesters' replies, ratings and surveys

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `csat-settings` | `/settings/csat` | the satisfaction survey: on or off, scale, question, delay, window, bad-rating reasons, which queues |
| `ratings-list` | `/ratings/` | the ratings received, bad ones first, with reason and comment (params: `score`, `queue`, `period`) |

Actions (base `/ratings/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `rating_submit` | `save.php` | **ticket**, **score** (1 to 5), bad_reason, comment | — | | | `rating.submit` | own |
| `survey_send` | `send.php` | **ticket** (the survey mailed now instead of after the delay) | — | | external_send | `survey.send` | work |
| `csat_settings_save` | `/settings/csat.php` | enabled (yes or no), scale (good_bad or five_point), question, send_delay_minutes (0 to 10080), response_window_days (1 to 90), bad_reasons[], queues[] (survey these queues or all) | restore prior | | other | `csat_settings.save` | admin |

## Reports

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `queue-dashboard` | `/reports/` | the queues now: backlog by status and priority, aging, at risk, unassigned, workload (params: `queue`) |
| `reports` | `/reports/{report}` | one report as a table and a CSV: volume, first response, resolution, SLA attainment, reopen rate, CSAT, workload, aging, by queue or category or organization (params: `queue`, `from`, `to`, `group_by`, `format`) |

## Public doors (no session; not actions)

| Screen id | URL | What happens there |
| --- | --- | --- |
| `request-form` | `/request` | anyone files a request: name, email, category (requester-visible ones), subject, description, attachments; CSRF, honeypot, an address and IP rate limit; the ticket is created with channel `form` and the confirmation mail carries the secure link |
| `secure-ticket` | `/t/{token}` | an external requester's ticket: the public conversation, reply, attach, confirm resolution, reopen within the window, rate; `source = portal`; a dead link shows one page |
| `kb-public` | `/kb/{slug}` | a public article, and the public search at `/kb/` |

Their POSTs (`/request`, `/t/{token}/reply`, `/t/{token}/confirm`, `/t/{token}/reopen`, `/t/{token}/rate`, `/t/{token}/attachment`) are
handlers with a token or a form as the authority, logged with `actor_requester_id`, never tools: an agent files a ticket with
`ticket_create` and replies with `ticket_reply`.

## What is not an action

Reading a screen (`screen.view`), a report export (a GET with `format=csv`, logged `report.export`), the viewer heartbeat
(`POST /tickets/viewing.php`, no log), notifications sent by the worker (`notification.send`), the SLA clock's warnings and
breaches (`ticket.sla_warning`, `ticket.sla_breach`), the automation the worker runs (`rule.fire`), auto-close and the
snooze's wake-up (`ticket.transition` with `via auto`), the mail poll (`mail.inbound`), the directory sync (`directory.sync`)
and sign-on (`member.sign_on`) are events, not actions: nobody asks for them.
