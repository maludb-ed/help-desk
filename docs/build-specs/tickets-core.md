# Build spec: tickets core — THE EXEMPLAR (slice 3)

Built by the planning-class model. Every later slice replicates its files, ids, gates, flow and proof style. This is the
application's spine: a ticket filed by a person, by someone on their behalf, by an external by email, or by an OS agent;
worked in its queue by people and agents through one conversation and one set of allowed moves; every step logged, every
clock kept by the database.
Schema: `tickets`, `ticket_tags`, `ticket_followers`, `ticket_links`, `ticket_viewers`, `messages`, `attachments`,
`ticket_links_secure`, `sla_targets` and the `hd_*` functions and triggers (db/010); `ticket_statuses`, `status_transitions`,
`hd_transition()` (db/009); `requesters`, `hd_requester_for_member/email()` (db/006); `hd_can_see_ticket()`,
`hd_can_work_ticket()`, `hd_can_see_internals()`, `hd_own_ticket_ids()` (db/014); the views `mcp_tickets`, `mcp_messages`,
`mcp_attachments`, `mcp_ticket_followers`, `mcp_ticket_links`, `mcp_ticket_viewers`, `mcp_sla_targets`, `mcp_requesters`,
`mcp_saved_views`, `mcp_activity_log` (db/015); `agent_dispatches`, `notification_outbox` (db/013). Never modify them.
**The database is the referee**: numbering, the policy match, the SLA clock, the status dates, reopen counting and the
secure link's expiry live in db/010's triggers; the handlers decide the MOVE (`hd_transition()`) and translate a refusal
(`SQLSTATE P0001`, or a missing required field) into its sentence as a 422.

## Screens (every requester screen at 375 px first; the inbox and the detail usable at 375 px, designed at 1280 px)

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `dashboard` | `/` | Home: **What needs me** (support: my_queue's shape — needs reply, then by SLA due, then unassigned in my queues), **My open tickets** (anyone), **New request**, the knowledge-base search box (slice 9 wires it; until then it links to `/kb/`) |
| `my-tickets` | `/my-tickets` | The tickets I asked for, filed, am cc'd on or follow — open, waiting on me (needs my confirmation), solved, closed (`?state=`) |
| `inbox` | `/inbox` | The tickets of my queues as a list: tabs for the seeded standard views (`mcp_saved_views` where `is_system`, read-only here), ad-hoc filters, sort, 50 a page |
| `ticket-add` | `/tickets/new` | File a ticket: for me, on behalf of a member, for an external by email, from an email (`?from_inbound=` arrives in slice 7) |
| `ticket-view` | `/tickets/{id}` | One ticket: the conversation and composer, the fields panel, the requester card, followers and CCs, links, time, SLA, the timeline |
| `ticket-edit` | `/tickets/{id}/edit` | Subject, requester, organization, type, category, due, custom fields (what the fields panel does not cover inline) |
| `ticket-merge` | `/tickets/{id}/merge` | Merge this ticket into another: the target picker with similar tickets suggested, what moves, confirm |

## The inbox
- Tabs across the top are the eight seeded views in `sort_order` (My tickets · Needs my reply · Unassigned · Triage · At risk · Stale · My requests · Team board — the last links to `/board`, slice 4); `?view=` selects one and its `filters` JSON is applied **on top of** the caller's visibility; the ad-hoc filter row (`q`, `status` category chips, `queue`, `assignee` = me/none/a person/an agent, `priority`) refines it. `assignee: "me"` and `requester: "me"` resolve to the caller.
- Each row (`ticket-row` partial, table on desktop; `ticket-card` on a phone): number (link), subject, requester with organization in `<small>`, queue, assignee (an agent shows a chip **agent**), status badge in its colour, priority badge (urgent `danger`, high `warning`, normal `info`, low `secondary`), **needs-reply** chip (`feather-corner-up-left`, `warning`) when `needs_reply`, the **SLA strip** on the row's edge and the next due as "due in 2h 10m" / "breached 40m ago", tags (three, then "+2"), last activity. Sort: `last_activity_at desc` by default; `?sort=` from an allow-list (`sla_due`, `priority`, `created_at`, `updated_at`).
- Region `#ticket-list` (Pattern B, `hx-push-url` explicit) listens to `ticketChanged`; a row's assignee and status are plain text here — changes happen on the detail page (bulk changes are slice 5).
- Empty: "Nothing in this view." A member with no queue sees the inbox menu item only if they hold `tickets.view_queue`; a direct URL without it → 403 "You may not see this queue's tickets."

## Filing a ticket
- **Who is it for**: (a) *Me* — the requester row is `hd_requester_for_member(me)`; (b) *Someone else here* — a member picker (`find_members`-shaped select, `?on_behalf_of=`), the requester row is theirs, `submitter_member_id` = me; (c) *A customer or outside person* — name + email, `hd_requester_for_email()` finds or makes the external (claimed by an organization's domain), `submitter_member_id` = me, channel `web`; (d) under a run token — the requester is the agent (`hd_requester_for_member(agent)`), channel `agent`, unless `on_behalf_of` names a member (then (b) with the agent as submitter). Options (b)–(c) need `tickets.view_queue` or `tickets.work`; a plain Requester files for themself only (422 "You can file a ticket for yourself here; support files on behalf of others.").
- **Fields**: subject, body (the first message, a public reply by the requester — or an internal note when `as_note`), category (requester-visible ones for a Requester; all for queue holders), priority (default normal; a Requester may say urgent — the triage agent decides), queue (queue holders only; empty = the category's `default_queue_id`, else `app_settings.default_queue_id`), type, tags, custom fields (slice 2's validator: `validate_custom_values('ticket', $typeId, $values)` — this slice calls it and shows its sentences), due, cc[] (emails or requesters → `ticket_followers` kind `cc`), `external_id`.
- **Routing**: the trigger sets number, organization (from the requester), status (default `new`), policy; the handler sets `queue_id` as above and `type_id`/`priority` from the category's defaults when the form left them empty.
- **Idempotency for agents**: `external_id` present and already a ticket's → **200** with that ticket's id and `did: "HD-1042 already exists for that external id"`, nothing created (the unique index is the backstop: a race answers the same way from the `23505`).
- On success: the secure link is minted (`hd_secure_link_mint()`) and the raw token placed in the outbox row's body for the requester's confirmation notice (an external: the link; a member: a link to `/tickets/{id}`) — the sending is slice 7; `saved_go("/tickets/$id")`; the assignee notice when the category or queue assigned someone (`assign_on_create()` applies the queue's `default_assignee` when `assignment_method = default_assignee`; round-robin and least-loaded are slice 8).

## The ticket page
- **Header**: number, subject, status badge (click = the transitions menu), priority, queue › assignee, requester (link to the card), created / last activity, the SLA strip, needs-reply chip, **viewers** ("Marco is viewing · Dana is replying", from `mcp_ticket_viewers`; the page POSTs `/tickets/viewing.php` every 30 s with `composing` when the composer has text; no log; the view hides rows older than 2 minutes).
- **Triage shortcuts** (only while the category is `new`, for `work`): **Accept** (→ open + assign me), **Decline** (→ solved `no_action`, a note required), **Duplicate…** (pick the twin: link `duplicate_of` then merge into it), **Snooze…** (→ pending with a `pending_until` chip picker: tomorrow 9:00, in 3 days, next week, a date).
- **Conversation** (`conversation` partial): messages oldest first; a public reply in a plain card, an internal note in a `warning`-tinted one with "Internal" chip; author = a person, an **agent** (chip) or the requester (external by name); channel icon; attachments as chips (name · size) linking to `/tickets/attachments/get.php?id=` (served through `require_can_see_ticket()`, `X-Content-Type-Options: nosniff`, inline only for images and PDFs, download otherwise). Notes render only when `can_see_internals()`.
- **Composer** (`composer` partial): tabs **Reply** / **Note** (Note for `note`+; Reply for `work` or the ticket's own requester); body (plain text; the HTML version is the sanitised paragraphs of it — no editor in v1); `@` mentions in a note (a typeahead over `find_members`; the ids go in `mentions[]`); attachments (`attachments/save.php` first, then the ids; ≤ `ATTACHMENT_MAX_BYTES`, default 10 MB, 5 files; stored at `storage/attachments/<ticket>/<id>-<sha256 prefix>`; the mime from `finfo`, never the client); **"Then set status…"** select listing only `hd_transition()`'s allowed moves; choosing solved expands a resolution-code select, on hold a hold-reason select, pending a pending-until picker — inline, no modal. Send → `reply.php` (with `then_status`) or `note.php`.
- **Fields panel** (`fields-panel`): status (the transitions menu: each allowed move as a button with its required fields expanding inline; a move not allowed is absent; the sentence "HD-1042 cannot move from Closed." when the category is closed), priority, type, category (the path), queue (move it: `assign.php` with `queue`), **assignee** (the queue's members from `mcp_queue_members` — people and **agents** — plus "me" and "nobody"), due, tags (add/remove chips), custom fields (per type, slice 2's renderer), the policy and each SLA target (`sla-strip` expanded: metric, due, state, paused), time logged with a "Log time" row, the secure link's state (live/rotated/expires, **never the token**) with **Rotate**.
- **Requester card** (`requester-card`): name, kind chip (member / external / agent), organization and tier, email and phone (queue holders only — the view blanks them), their last five tickets, notes (queue holders), "Edit" → `/requesters/{id}/edit` (slice 1). **Followers and CCs**: chips with remove; add a follower (a member) or a CC (an email or requester).
- **Links** (`links`): parent / children / duplicates / related / blocks / follow-ups with number and subject; add a link (number typeahead over `find_tickets`); **Merge into…** → the merge page.
- **Timeline** (`timeline`): `mcp_activity_log` rows for the ticket, newest first, in words: "Marco assigned it to Frontline (agent)", "Status Open → Pending (snoozed until Fri 9:00)", "Rule 'Reopen when the snooze passes' fired", "Priya replied", "Dispatched to Frontline — answered"; internals (notes' excerpts, rule rows, dispatches, time) only for `can_see_internals()`.
- **Requester's own view** (a member on their own ticket without queue rights): the conversation (public only), the composer's Reply tab, **Confirm it is resolved** (when solved: `confirm.php`), **Reopen** (within `reopen_window_days`: `reopen.php`), no fields panel but a read-only summary (status, priority, queue, assignee's first name or "an agent", next due as "we aim to respond by …"). The same words the secure page uses (slice 6).
- `data-screen="ticket-view" data-entity="ticket" data-record-id="{id}"`; a ticket the member may not see → 404 "Ticket not found." (the same sentence as a missing one).

## Merging
- `GET /tickets/{id}/merge` (the same file `merge.php`, GET renders, POST merges): the target picker (number typeahead; `similar_tickets`-shaped suggestions: same requester or organization, matching subject words, open first); what will happen listed; confirm. POST: both tickets visible and workable, `tickets.manage`; one transaction: the source's messages, attachments, tags, followers and time move to the target; the source gets a `ticket_links` row `merged_into`, `merged_into_id`, status solved with code `duplicate`; a note on the target "Merged HD-1043 into this ticket"; log `ticket.merge` on both; the source's secure link stays valid and its page (slice 6) says "This request was merged into HD-1042" and shows the target's public conversation.

## Assigning to an agent (design §5)
- The assignee select lists the queue's members, agents marked. Assigning to an agent inserts an `agent_dispatches` row (`kind = assigned`, `via = chat`, `acting_member_id` = the person) and calls `dispatch_to_agent()`: `POST {OS_INTERNAL_URL}/api/v1/agents/chat.php?agent=<id>` with `OS_APPLICATION_TOKEN`, `X-Acting-Member: <person>`, utterance "Ticket HD-1042 was assigned to you: <subject>. Work it with my_queue and get_ticket.", `wait: 5`; 200/202 → status `answered`/`sent` with `run_id` and a 200-char `reply_excerpt`; 4xx/5xx/unreachable → status `failed` with the kernel's sentence. **The assignment never waits for or fails on the dispatch**: it is committed first, the call made after, and `ticket.dispatch` logged with the outcome. Under a run token (no acting person) no call is made: `via = duty` — the agent's own duty picks it up (`my_queue`). `ticket_dispatch` repeats the call by hand.

## Files (exactly these)
- `html/index.php` (dashboard fill) · `html/inbox.php` · `html/my-tickets.php`
- `html/tickets/form.php` (`ticket-add`, `ticket-edit`) · `view.php` · `merge.php` (GET page + POST handler)
- `html/tickets/save.php` · `reply.php` · `note.php` · `transition.php` · `assign.php` · `fields.php` · `tags.php` · `untag.php` · `follow.php` · `unfollow.php` · `cc.php` · `cc-remove.php` · `link.php` · `unlink.php` · `close.php` · `reopen.php` · `confirm.php` · `spam.php` · `delete.php` · `time.php` · `time-delete.php` · `secure-link.php` · `dispatch.php` · `viewing.php`
- `html/tickets/attachments/save.php` · `delete.php` · `get.php`
- `app/features/tickets/queries.php` · `present.php` · `write.php` · `transition.php` · `dispatch.php` · `handler.php` (the handlers' shared prelude: load the ticket, the gate, `ticket_refused()`)
- `app/features/messages/queries.php` · `present.php`
- `app/views/home/dashboard.php` · `app/views/tickets/inbox.php` · `view.php` · `form.php` · `merge.php` · `partials/ticket-row.php` · `ticket-card.php` · `conversation.php` · `composer.php` · `fields-panel.php` · `requester-card.php` · `sla-strip.php` · `timeline.php` · `links.php`

## Query functions (signatures fixed)
- `find_tickets_filtered(PDO, array $filters, int $memberId, int $limit = 50, int $offset = 0): array` (rows from `mcp_tickets`; `$filters` keys are the saved-view vocabulary: `status_category[]`, `status`, `queue_id`, `assignee` (me/none/id), `requester` (me/id), `organization_id`, `priority[]`, `type_id`, `category_id`, `tag`, `needs_reply`, `sla`, `stale_days`, `q`, `sort`) · `count_tickets_filtered(PDO, array $filters, int $memberId): int`
- `find_ticket(PDO, int $id): ?array` (mcp_tickets + followers, links, targets, time, viewers) · `find_ticket_by_number(PDO, string $number): ?array` · `find_ticket_by_external_id(PDO, string $externalId): ?int`
- `ticket_conversation(PDO, int $id, bool $withNotes): array` (mcp_messages + attachments) · `ticket_history(PDO, int $id, int $limit = 100): array` (mcp_activity_log, worded by `present.php`)
- `ticket_allowed_moves(PDO, int $ticketId): array` (`hd_transition()` for every live status; each with `requires`, `may_carry`, `needs_right`, `may` for the caller)
- `similar_tickets(PDO, int $ticketId, int $limit = 8): array` · `requester_summary(PDO, int $requesterId): ?array` (mcp_requesters + last five tickets)
- `create_ticket(PDO, array $fields, int $actorMemberId): int` (requester resolution, routing, the first message, tags, ccs, the secure link, the outbox rows; one transaction) · `add_message(PDO, int $ticketId, string $kind, string $body, array $opts): int` (`author_member_id` or `author_requester_id`, channel, mentions, attachment ids re-parented, `then_status` through `transition_ticket()`)
- `transition_ticket(PDO, int $ticketId, int $toStatusId, array $carry, int $by, string $via = 'screen'): array` (checks `hd_transition()`, the right it names, the required fields present; UPDATE; returns before/after for the log; raises with the sentence) · `assign_ticket(PDO, int $ticketId, ?int $assigneeMemberId, ?int $queueId, int $by, string $via = 'manual'): array` · `set_ticket_fields(PDO, int $ticketId, array $fields, int $by): array` (changed fields only) · `set_ticket_tags(PDO, int $ticketId, array $add, array $remove): array`
- `add_follower(PDO, int $ticketId, string $kind, ?int $memberId, ?int $requesterId, int $by): void` · `remove_follower(...)` · `link_tickets(PDO, int $from, int $to, string $kind, int $by): int` · `unlink_tickets(...)` · `merge_tickets(PDO, int $sourceId, int $targetId, int $by, ?string $note): array`
- `log_time(PDO, int $ticketId, int $memberId, int $minutes, bool $billable, ?string $note, string $workedOn): int` · `delete_time_entry(PDO, int $entryId, int $by): void`
- `store_attachment(PDO, int $ticketId, ?int $messageId, array $file, int $byMember): int` · `attachment_path(PDO, int $attachmentId): ?array` · `delete_attachment(PDO, int $id): void`
- `rotate_secure_link(PDO, int $ticketId): string` (returns the raw token ONCE to the outbox writer) · `touch_viewer(PDO, int $ticketId, int $memberId, bool $composing): void`
- `dispatch_to_agent(PDO, int $ticketId, int $agentId, string $kind, ?int $actingMemberId): array` (`app/features/tickets/dispatch.php`)
- `notify(PDO, array $recipient, string $kind, int $ticketId, string $subject, string $body, ?int $messageId = null, ?string $dedupe = null): int` (inserts `notification_outbox` rows — one per channel the recipient's prefs allow; an external gets email only; never a note's text; the sender is slice 7)

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity` with `ticket_id` and `queue_id`; `emit_action_status`; `HX-Trigger: ticketChanged`)
- `save.php` (`ticket_create` / `ticket_update`): create — `require_login()`, `has_right('tickets.create')`; on behalf / external need `tickets.view_queue`; `external_id` idempotency first; log `ticket.create` (`after`: number, requester_id, queue_id, priority, channel, on_behalf_of); location `/tickets/{id}`. Update — `require_can_work_ticket()`; the changed fields; log `ticket.update`.
- `reply.php` (`ticket_reply`): `can_work_ticket()` OR the ticket's own requester (`hd_my_requester_id()` = `requester_id`, or a cc member); a requester's reply is a requester-side reply (the trigger reopens pending/solved); `then_status`/`resolution_code` honoured only for `work`; log `ticket.reply` (`after`: message_id, kind, excerpt, attachments, recipients); queues `replied` notices (support's reply → the requester and CCs; a requester's reply → the assignee, else the queue's leads). **Pauses for an agent** (`external_send`).
- `note.php` (`ticket_note`): `tickets.note` in the queue or `work`; log `ticket.note`; a mention queues `mentioned` to each named member who can see the ticket (others silently dropped).
- `transition.php` (`ticket_transition`): `require_can_work_ticket()`; `transition_ticket()`; refusals 422: "HD-1042 cannot move from Closed." · "HD-1042 cannot move from Open to Closed here — close it from the ticket's menu." (a move the table lacks) · "Solving needs a resolution code." · "Putting a ticket on hold needs a reason." · "Closing early needs the Lead role." (`needs_right`); log `ticket.transition` (`before.status`, `after.status`, category, resolution_code, hold_reason, pending_until, via).
- `assign.php` (`ticket_assign`): `require_can_work_ticket()`; a queue move needs `work` in the target queue too or `tickets.manage`; an assignee must be a member of the (new) queue — else 422 "Dana is not in the IT help queue."; log `ticket.assign` (before/after assignee + names + kind, before/after queue, via); the `assigned` notice; an agent assignee → `dispatch_to_agent()` after commit.
- `fields.php` (`ticket_set_fields`): `require_can_work_ticket()`; custom values validated; log `ticket.set_fields` (changed only). `tags.php` / `untag.php`: `ticket.tag` / `ticket.untag` (`after.tags[]`); a new slug creates the tag.
- `follow.php` / `unfollow.php`: a member follows a ticket they can see (`require_can_see_ticket()`); naming another member needs `tickets.note`; `ticket.follow` / `ticket.unfollow`. `cc.php` / `cc-remove.php`: `work`, or the requester adding a cc on their own ticket; `ticket.cc_add` / `ticket.cc_remove`; a new email → `hd_requester_for_email()`.
- `link.php` / `unlink.php`: `work` on both tickets (the other must be visible: 404 otherwise); `ticket.link` / `ticket.unlink` (`after.kind`, `after.to_ticket_id`); `parent_child` needs the parent to be type problem? — no: any ticket may parent (the type is advice).
- `merge.php` (POST, `ticket_merge`): `tickets.manage`, both workable, not the same, the target not closed; `merge_tickets()`; log `ticket.merge` on both (`after.into_ticket_id`, `after.messages_moved`); location `/tickets/{target}`. **Pauses for an agent** (`other`).
- `close.php` (`ticket_close`): `tickets.manage`; from solved → closed, or from open/pending/on_hold with a resolution code (`transition_ticket()` with `needs_right` tickets.manage); log `ticket.close`. **Pauses for an agent** (`other`).
- `reopen.php` (`ticket_reopen`): `work`, or the requester within `reopen_window_days` of `solved_at` (else 422 "This request was solved more than 14 days ago — file a new one and mention HD-1042."); solved → open; closed → 422 (a follow-up ticket is slice 7's mail rule and the secure page's button, slice 6); log `ticket.reopen`.
- `confirm.php` (`ticket_confirm`): the requester (or a cc) on a ticket in pending or open after support's reply → solved with code `resolved`, `via requester`; log `ticket.confirm`.
- `spam.php` (`ticket_spam`): `tickets.manage`; → solved with code `spam`, tag `spam`, the requester's notes untouched; log `ticket.spam`; **deletion** category. `delete.php` (`ticket_delete`): `require_admin()`; the attachment files removed from storage, the row deleted (cascades); log `ticket.delete` (`after.number`, `after.subject`) before the delete; **deletion**.
- `time.php` / `time-delete.php`: `work`; own entry or `tickets.manage` to delete; `ticket.log_time` (`after.minutes`, `billable`), `ticket.time_delete`.
- `attachments/save.php` (`attachment_add`): `work` or the ticket's requester; size, count, a mime allow-list (images, PDF, text, office documents, zip; never executables); `ticket.attach` (`after.filename`, `byte_size`, `mime_type`). `attachments/delete.php`: `tickets.manage`; `ticket.attachment_delete`; **deletion**. `attachments/get.php`: GET, `require_can_see_ticket()`; a note's attachment needs `can_see_internals()`.
- `secure-link.php` (`secure_link_rotate`): `work`; `rotate_secure_link()`; the new link goes to the outbox for the requester; log `ticket.secure_link_rotate`; the token never in the response, the log or the page.
- `dispatch.php` (`ticket_dispatch`): `work`; the assignee must be an agent (422 "HD-1042 is not assigned to an agent."); `dispatch_to_agent(kind = assigned)`; log `ticket.dispatch`.
- `viewing.php`: POST, `require_can_see_ticket()`, `touch_viewer()`, 204, **no log**, not an action.

## Action manifest entries
- Screens: `dashboard`, `my-tickets`, `inbox`, `ticket-add`, `ticket-view`, `ticket-edit`, `ticket-merge`.
- Actions (28): `ticket_create`, `ticket_update`, `ticket_reply`, `ticket_note`, `ticket_transition`, `ticket_assign`, `ticket_set_fields`, `ticket_add_tags`, `ticket_remove_tag`, `ticket_follow`, `ticket_unfollow`, `ticket_cc_add`, `ticket_cc_remove`, `ticket_link`, `ticket_unlink`, `ticket_merge`, `ticket_close`, `ticket_reopen`, `ticket_confirm`, `ticket_spam`, `ticket_delete`, `ticket_log_time`, `time_entry_delete`, `attachment_add`, `attachment_delete`, `secure_link_rotate`, `ticket_dispatch` — and `viewing.php` is not one. Agent approvals: `ticket_reply` (`external_send`); `ticket_merge`, `ticket_close` (`other`); `ticket_spam`, `ticket_delete`, `attachment_delete` (`deletion`). `PARTIAL_UPDATE_TARGETS` gains `/tickets/save.php => ['tickets', 'ticket', 'mcp_tickets', 'ticket_id']`.

## Activity log events (every row: `ticket_id`, `queue_id`)
`screen.view` (each screen; on `ticket-view` `after.ticket_id` — Q22), `ticket.create`, `ticket.update`, `ticket.reply`, `ticket.note`, `ticket.transition`, `ticket.assign`, `ticket.set_fields`, `ticket.tag`, `ticket.untag`, `ticket.follow`, `ticket.unfollow`, `ticket.cc_add`, `ticket.cc_remove`, `ticket.link`, `ticket.unlink`, `ticket.merge`, `ticket.close`, `ticket.reopen`, `ticket.confirm`, `ticket.spam`, `ticket.delete`, `ticket.log_time`, `ticket.time_delete`, `ticket.attach`, `ticket.attachment_delete`, `ticket.secure_link_rotate`, `ticket.dispatch` — payload keys per the tool surface. **No row carries a message body (a 200-character excerpt at most), a secure token, or another requester's address.**

## Notifications this slice queues (`notification_outbox`; the sender is slice 7)
| Step | Who is told | Kind |
|---|---|---|
| a ticket is filed | the requester (confirmation; an external's carries the secure link), the assignee if any | `created`, `assigned` |
| assigned | the new assignee (a person; an agent is dispatched instead) | `assigned` |
| support replies | the requester and the CCs (the reply's text; an external's carries the link) | `replied` |
| the requester replies | the assignee, else the queue's leads | `replied` |
| a note mentions someone | each mentioned member who can see the ticket | `mentioned` |
| cc'd | the new cc | `cc_added` |
| solved / closed by support | the requester (how to confirm or reopen; the link) | `replied` |
A notice carries the ticket's number, subject, the reply's text when it is one, and a link — never a note, never another requester's details.

## Status vocabulary
- Status badges: the row's `color` (db/009: new primary, open info, pending warning, on hold secondary, solved success, closed dark; a custom status its own). Priority: urgent `danger`, high `warning`, normal `info`, low `secondary`. SLA state: `breached` danger strip + "breached 40m ago", `at_risk` warning, `paused` secondary with `feather-pause`, `on_track` no strip, `met` success tick, `n/a` nothing. Needs-reply chip `feather-corner-up-left` warning. Requester kind chip: member none, external `feather-globe`, agent `feather-cpu`. Assignee agent chip `feather-cpu`.
- Ids: `ticket-row-{id}`, `ticket-card-{id}`, `ticket-conversation`, `message-{id}`, `ticket-composer`, `ticket-fields`, `ticket-requester`, `ticket-links`, `ticket-timeline`, `ticket-sla`, `ticket-viewers`, `inbox-filters`, `ticket-list`.

## Out of scope for this slice
Saved views and the board (slice 4 — the inbox reads only the seeded system views here); canned responses and bulk changes (5); the public request form and the secure ticket page (6); sending the outbox, inbound mail and `?from_inbound=` (7); automation, round-robin and least-loaded assignment, the SLA worker's warnings and auto-close (8); the knowledge base and article suggestions (9); reports (10). The requesters' and organizations' own screens are slice 1; the vocabulary's screens and the custom-field validator/renderer are slice 2 (this slice calls `validate_custom_values()` and `render_custom_fields()` from `app/features/vocabulary/custom_fields.php`).

## Proof (`tests/phase3/slice3/run.sh`: the scratch database, members through dev hand-offs, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-03: 223 checks green** (visibility 17, filing 26, moves 31, replies 26, agents 18, merge_attach 40, json 15, viewers 14, browser 35, the registry check); Phase 2's 250,
slice 1's 211 and slice 2's 129 still green; the installer's `plan` 60 steps, no stop. The world the proofs reason about is `tests/phase3/slice3/lib.php` `world()`: two queues linked to the
fixture's departments, Customer Co with a domain, Jo (an external), the invited external member, five tickets.
- [x] **See only what is mine or my queue's.** Priya sees her two tickets in `my-tickets`, 403 on `/inbox`, 404 "Ticket not found." on Jo's, no SLA and no secure link on her own; Marco (support,
  IT through the department) sees IT's tickets, the note, Jo's email on the card, nothing of Facilities; Colin (collaborator, Facilities) sees Facilities' notes and the IT ticket he follows (without
  internals); Lena (lead) everything; the invited external member sees Customer Co's tickets and not Jo's email; every rendered page and JSON answer grepped for the externals' addresses and any
  secure token — none outside the queue holders' requester card, never a token (the activity log too).
- [x] **Filing for every requester kind.** Priya for herself (channel web, her row, urgent as she said); Marco on behalf of Priya (submitter Marco, the body as a note, tags, a cc that became an external
  claimed by the domain) and for `new@customer.invalid` (an external made, the ticket carrying the organization); by a requester id and by a known email; a category routes the queue, priority and type;
  the agent's run token files one (requester = the agent, channel agent, source agent with the run's request id) and the same `external_id` twice → one ticket, 200 with the same `record_id`; `on_behalf_of`
  by the agent; a plain requester posting `on_behalf_of` → 422 in words, a hidden category → 422; a `created` outbox row with the link, the raw token only there; a queue whose method is default
  assignee assigns the agent and dispatches it.
- [x] **Every move the table allows, and none it does not.** Accept (open + me, via triage, a ticket.assign row), Decline (needs a note; solved no_action), Snooze (pending, the clock paused); open → solved
  without a code → *"Solving needs a resolution code."*; on hold without a reason → 422; closed → anything → *"… cannot move from Closed."*; open → closed as support → *"Closing early needs the Lead role."*;
  as Lena → closed with a code, the secure link's expiry set; solved → open (reopen_count 1, first_reopened_at); the requester's Confirm → solved resolved via requester; her Reopen inside the window works
  and outside it says the 14-day sentence; a move removed from the matrix is refused in words and allowed again once restored.
- [x] **Replies and the clock.** Marco's reply meets first response, stamps the dates, tells Jo by email with the text; the HTML is the sanitised paragraphs; Jo's reply (as her requester row) sets needs-reply
  and reopens pending; "Needs my reply" follows the assignee; `then_status = pending` pauses the clock; `then_status = solved` without a code refuses the whole transaction (no message); a requester's
  reply tells the assignee (and the bell); a note mentioning Lena and Priya tells Lena only (internals); the requester CCs an address on her own ticket, support's reply then reaches both.
- [x] **Assigning to an agent.** A dispatch row via chat, acting Marco, answered with a run id and an excerpt by the stubbed kernel; the stub failing → `failed` with its sentence and the assignment stands;
  `ticket_dispatch` repeats it; under a run token via duty, nobody acting, no call; `mcp_agent_dispatches` readable by the queue's holders, not by Priya; an assignee outside the queue → *"… is not in the … queue."*;
  a queue move needs work there or `tickets.manage`; the new person is told.
- [x] **Merge.** Four messages and the attachment move, B solved duplicate with `merged_into_id`, the `merged_into` link, both log `ticket.merge`, A's conversation in time order with the merge note,
  B's tag on A, B off the inbox and its page saying "merged into"; into a closed ticket → 422; as support → 403; again → 422. Links by number, a child's parent, unlink; time logged and removed.
- [x] **Attachments.** The limit + 1 byte → 422 (the dev environment pins `ATTACHMENT_MAX_BYTES=200000`, under php -S's own 2 MB); an `.exe` → 422; a PDF stored under `storage/attachments/<ticket>/`
  with the mime from `finfo`, served inline with `nosniff`; a text file a download; 404 to Priya when it hangs on a note; the requester attaches to her own reply; delete as Lena removes the file.
- [x] **JSON mode.** Every handler under a signed action token: `{ok, did, record_id, location}`; 422 `{error: {code: invalid, fields}}`; 404 in one sentence; `_partial=1` keeps the fields; the relay
  missing → 401; an unknown member → 401 and no row; the ticket and the inbox answer JSON.
- [x] **Viewers.** Two sessions see each other, "is replying" with text in the composer, gone after two minutes, no activity row from `viewing.php`, 404 on a ticket one cannot see.
- [x] **The timeline** in words with the actor and the agent marked; a requester's holds no note, no dispatch, no time, no SLA row. The dashboard's "What needs me" with a reason; "Waiting on me".
- [x] **375 × 740 and 1280 × 800**: cards on the phone, a table on the desktop, the detail page's panels stacked with the composer within the width, the fields panel beside the conversation on the
  desktop, `scrollWidth` = viewport, every button ≥ 44 px, no console errors, the flows through the real screens (file, snooze, reply, assign to the agent), JavaScript off; the registry reads the
  28 actions and 7 screens as built.

**Decisions taken while building (2026-10-03):** the slice's prelude is `app/features/tickets/handler.php` (loads every feature it reads: statuses, vocabulary, fields, queues, sla, requesters);
`home/queries.php` now only requires it (the dashboard's reads live with the tickets); the vhost and the dev router gained `/tickets/{id}/merge` → `merge.php?ticket=` and the registry builder reads
`/x/{id}/word` as `x/word.php`; a mention goes to those who can see the ticket's INTERNALS (a requester named in a note is dropped); `notify()` also writes the in-app `notifications` row (the
bell) and never mails an agent; the requester's reply notice goes to the assignee, else the queue's named leads; a reply's `then_status` runs in the same transaction (a refused move sends nothing);
`spam` marks only (D1 stands); the composer is Reply and Note as two `<details>` (no tabs script), the matrix moves as `<details>` per move needing a field; viewers are polled by a small script on the
page; `member_in_queue()` (base tables) is the writer's check for an assignee; the requester's address in the ticket's JSON goes to this queue's holders only (the view gives it to any holder of the
right); `as_member_sql()` switches `app.member_id` session-wide for one call. The three open questions below stand as the owner decided (spam mark-only, a follow-up never a reopen of closed, plain text).

## Open questions
- **Suppressing a spammer's address.** The schema has no suppressed-senders table; `ticket_spam` solves with code `spam` and tags the ticket. Should slice 7's mail intake refuse future mail from an address whose tickets were marked spam twice (a `requester_emails.suppressed_at` column, additive), or is marking enough?
- **Reopening a closed ticket.** The design makes closed terminal and a requester's reply a follow-up ticket (slice 7). Should a Lead be able to reopen a closed ticket by hand, or is a follow-up always the answer?
- **The composer's HTML.** Plain text with paragraphs in v1 (no rich editor); confirm, or name an editor to vendor like SortableJS.
