# Build spec: working faster — slice 5

Replicates slice 3's files, gates, flow and proof style. What exists at the end: support staff answer in one click from
**canned responses** with placeholders and side-effects (personal, a queue's, global), the ticket composer offers them,
a lead changes **several tickets at once** from a list selection, and the ticket forms show **the custom fields of the
chosen type** as the type changes. Nothing new is written to a ticket here that slice 3 does not already write: a canned
reply is slice 3's `add_message()`, a bulk change is slice 3's `transition_ticket()` / `assign_ticket()` /
`set_ticket_fields()` / `tag_ticket()` per ticket.
Schema: `canned_responses` (db/011), `custom_field_defs` (db/009), `tickets.custom_values` (db/010), the views
`mcp_canned_responses`, `mcp_custom_field_defs`, `mcp_tickets` (db/015). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `canned-list` | `/canned/?q=&folder=` | The canned responses I may use, by folder: mine, my queues', global |
| `canned-add` | `/canned/new` | Write a canned response with placeholders and side-effects |
| `canned-edit` | `/canned/{id}/edit` | Change one |
| `bulk-update` | `/bulk?tickets=1,2,3` | Change several tickets at once from a list selection |

## Canned responses
- **List**: cards grouped **Mine · {Queue name} · Everyone**, each card: title, folder chip, the first line, usage count,
  side-effect chips (*→ Pending*, *↑ High*, *assign me*, *+billing*), **Use on a ticket** is not here (it is the composer's);
  **Edit** for the owner or a lead. Search by words in title or body; a folder select. Empty: *"No canned responses yet —
  write one from a reply you keep typing."*
- **Form**: title, folder (free text with a datalist of existing folders), scope (**Personal**; **Queue** → a queue held,
  `views.shared`-class right: `tickets.manage` or a lead flag; **Everyone** → `tickets.manage`), body (textarea; a
  **placeholders** helper lists them and inserts on click), side-effects: status (a select of statuses — a move the ticket
  cannot make at apply time is skipped and said), priority, assign (*nobody / me / a person*), add tags. A live **preview**
  pane renders the body against a sample ticket (Pattern A, `POST /canned/apply.php` with `preview=1`, nothing saved).
- **Placeholders** (`canned_render()`), exactly: `{{ticket.number}}` `{{ticket.subject}}` `{{ticket.queue}}` `{{ticket.status}}`
  `{{requester.name}}` `{{requester.first_name}}` `{{organization.name}}` `{{agent.name}}` `{{agent.first_name}}`
  `{{agent.signature}}` (the member's signature from `notification_prefs`? — no: from the queue's mailbox signature, else
  the person's display name) `{{queue.name}}` `{{queue.email}}` `{{today}}`. An unknown placeholder is left as typed and the
  form warns on save (*"{{foo}} is not a placeholder."*, 422). **Never** a secure link, another ticket's data, a note, an
  email of a third party: the render reads the ticket's presenter row (slice 3's `present_ticket()`) only.
- **Apply** (`canned_apply`, from the composer or by voice): `send=no` renders and returns the text — the composer inserts
  it at the caret; `send=yes` posts a **public reply** through `add_message()` with the rendered body, then the side-effects
  in order (tags, priority, assign, status — each through slice 3's functions; a status the transitions table forbids is
  skipped and named in `did`), then `usage_count + 1`. For an agent `send=yes` pauses (`external_send`); `send=no` is a read
  dressed as an action and never pauses — the handler checks `send` before anything and answers the text with `ok:true`
  and no `location`.
- **The composer gains** (`app/views/tickets/partials/composer.php`, slice 3's file; this slice changes exactly this): a
  **Canned ▾** button left of Send that opens an inline list (Pattern A: `GET /canned/?picker=1&q=` into `#canned-picker`,
  `hx-trigger="keyup changed delay:300ms"` on its search box); choosing one `POST /canned/apply.php` with `send=0`,
  `preview=0`, swaps the text into the textarea (`hx-target="#composer-body"`, `hx-swap="outerHTML"` of a re-rendered
  textarea carrying the text) and shows the side-effects as chips with an **apply side-effects** checkbox that travels
  with the reply (`canned_id` + `apply_side_effects` on slice 3's `ticket_reply` POST: the reply handler calls
  `canned_side_effects()` after sending). No modal; the picker is a card under the composer.

## Bulk update
- `bulk-update`: opened from the inbox's selection (slice 3's list gains checkboxes and a **Change selected…** button
  that GETs `/bulk?tickets=`; this slice adds the checkbox column and the button to `app/views/tickets/partials/list.php`).
  The page lists the selected tickets (number, subject, status, assignee), then one form: status (with the resolution
  code / hold reason / pending-until controls shown when needed), assignee (*keep / nobody / me / a person / an agent*),
  queue, priority, add tags, remove tags, a note added to each. **Apply to N tickets** (confirm). Only tickets the caller
  may see are listed; the handler re-checks each.
- The outcome page: a table of the N tickets with ✓ or the refusal sentence per ticket (*"HD-1005 cannot move from
  Closed."*), counts at the top. Nothing is undone when some refuse — the refused ones simply did not change.

## Per-type custom fields on the forms
- `ticket-add` / `ticket-edit` (slice 3's forms): the custom fields block is a Pattern A region `#custom-fields` reloaded
  on `change` of the type select (`GET /tickets/fields.php?type=&ticket=` → `app/views/tickets/partials/custom-fields.php`),
  rendering the definitions whose `type_ids` is empty or contains the type, with values from the ticket. The validator is
  slice 2's `custom_values_validate($defs, $input, $context)` (one function for ticket, organization, requester forms):
  type-checked, select options enforced, `required_on_solve` enforced by `transition_ticket()` when moving to solved
  (422 *"'Root cause' is required to solve this ticket."*). This slice writes the partial and the GET; the validator exists.

## Files (exactly these)
- `html/canned/index.php` · `form.php` · `save.php` · `delete.php` · `apply.php` · `html/bulk/index.php` · `html/bulk/update.php` · `html/tickets/fields.php` (the custom-fields partial GET)
- `app/features/canned/queries.php` · `present.php` · `render.php` (`canned_render()`, `canned_placeholders()`, `canned_side_effects()`) — `app/features/bulk/queries.php` · `handler.php` (`bulk_apply()`)
- `app/views/canned/list.php` · `form.php` · `partials/canned-card.php` · `partials/picker.php` · `partials/preview.php` — `app/views/bulk/form.php` · `result.php` — `app/views/tickets/partials/custom-fields.php` (new) · `partials/composer.php` and `partials/list.php` (changed as said)

## Query functions (signatures fixed)
- `find_canned(PDO, int $memberId, ?string $q = null, ?string $folder = null, int $limit = 100): array` (`mcp_canned_responses`, grouped by the presenter) · `find_canned_one(PDO, int $id): ?array` · `canned_folders(PDO, int $memberId): array`
- `save_canned(PDO, ?int $id, array $f, int $by): int` · `delete_canned(PDO, int $id, int $by): void` · `bump_canned_usage(PDO, int $id): void`
- `canned_render(array $canned, array $ticket, array $actor, array $queue): string` · `canned_placeholders(): array` · `canned_unknown_placeholders(string $body): array` · `canned_side_effects(PDO, array $canned, int $ticketId, int $by): array` (`[applied:[…], skipped:[{effect, why}]]`, each through slice 3's functions)
- `bulk_targets(PDO, array $ids, int $memberId): array` (`mcp_tickets` rows the caller may see, in the order given, 200 at most) · `bulk_apply(PDO, array $ids, array $changes, int $by): array` (`[changed:[ids], refused:[{ticket_id, number, why}]]`; one transaction per ticket so one refusal never rolls the others back; `transition_ticket()`, `assign_ticket()`, `set_ticket_fields()`, `tag_ticket()`, `untag_ticket()`, `add_message()` for the note)
- `custom_fields_for(PDO, string $appliesTo, ?int $typeId): array` (`mcp_custom_field_defs`)

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity` with `ticket_id`/`queue_id` where there is a ticket; `emit_action_status`; `HX-Trigger`)
- `canned/save.php` (`canned_create` / `canned_update`): personal → `require_right('tickets.work')`; queue → the queue held and `tickets.manage` or `hd_is_queue_lead(queue)`; global → `require_right('tickets.manage')`; unknown placeholder → 422; side-effects validated against the vocabulary (a status key, a priority, `me` or a member id, tag slugs); log `canned.create` / `canned.update` (`after`: scope, queue_id, folder, side_effect keys — never the body); `location` `/canned/{id}/edit`; `HX-Trigger: cannedChanged`.
- `canned/delete.php` (`canned_delete`): own, or `tickets.manage`; log `canned.delete`.
- `canned/apply.php` (`canned_apply`): `require_can_see_ticket($ticket)` for `send=no` / `preview=1` (a read: answers `{ok:true, text, side_effects}`; no log but `screen.view`-class none); `send=yes` → `require_can_work_ticket`; `add_message($pdo, $ticket, 'public_reply', $by, $text)` (slice 3: it mails through slice 7's `notify_requester()`), `canned_side_effects()`, `bump_canned_usage()`; log `canned.apply` (`after.canned_id`, `after.sent`, `after.side_effects` applied and skipped) beside the `ticket.reply` row `add_message()` writes; `location` `/tickets/{ticket}#message-{id}`; `HX-Trigger: ticketChanged`.
- `bulk/update.php` (`tickets_bulk_update`): `require_right('tickets.manage')`; `confirmed` required (a destructive-class tool: `✔`); `bulk_apply()`; log **one** `ticket.bulk_update` row (`after.ticket_ids`, `after.changes` keys, `after.changed`, `after.refused` with reasons) plus the per-ticket rows the functions write; the result page / `{ok:true, changed:[…], refused:[…]}`; `HX-Trigger: ticketChanged`.
- `tickets/fields.php` (GET, a partial): `require_login()`; `custom_fields_for('ticket', $type)`; no log.

## Action manifest entries
- Screens: `canned-list`, `canned-add`, `canned-edit`, `bulk-update`.
- Actions: `canned_create`, `canned_update`, `canned_delete`, `canned_apply`, `tickets_bulk_update`. Agent approvals:
  `canned_apply` (`external_send` — with `send=yes`; `send=no` never reaches a handler that writes, but the kernel's hook
  pauses by action, so an agent that wants the text only should call `search_canned_responses` and read `body_text`:
  the skills say so), `canned_delete` (`deletion`), `tickets_bulk_update` (`other`).

## Activity log events
- `screen.view` (the four screens), `canned.create`, `canned.update`, `canned.delete`, `canned.apply` (`after.canned_id`,
  `after.sent`, `after.side_effects`), `ticket.bulk_update` (`after.ticket_ids`, `after.changes`, `after.changed`,
  `after.refused`), and — through slice 3's functions — `ticket.reply`, `ticket.transition` (`after.via = 'canned'` or
  `'bulk'`), `ticket.assign`, `ticket.set_fields`, `ticket.tag`, `ticket.untag`, `ticket.note`. A canned body is never in
  a payload (an excerpt of 200 characters rides on `ticket.reply` as slice 3 says).

## Out of scope for this slice
- The composer, reply and note handlers themselves (slice 3 — this slice adds a button and two POST fields); mailing the
  reply (slice 7); the custom-field **definitions** screens (slice 2's vocabulary slice builds `fields-list` / `field-add`
  / `field-edit`); a canned response that sends an **article** (`article_send`, slice 9); templates with attachments (Later).

## Proof (`tests/phase3/slice5/run.sh`: the scratch database, slice 3's world, members through dev hand-offs, curl with signed action and run tokens and HTMX-shaped posts, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-03: 100 checks green** (scope 22, render 12, apply 19, bulk 19, fields 11, json 10, browser 6, the registry); Phase 2's, slices 1–4's suites still green; the
installer's `plan` clean.
- [x] **Scope and rights.** Marco's personal response is on his list and not on Lena's (a direct URL → 404 *"Canned response not found."*); his `scope=global` → 403 *"Sharing with everyone
  needs the Lead role."*, `scope=queue` for IT (he is no lead) → 403; Lena (IT's lead) saves a queue response for IT and is refused one for Facilities (422, she does not hold it); an IT-queue
  response shows to Marco, a Facilities one to neither Marco nor Lena (`view_all` is not holding); a global one with side-effects as JSON (the agent's way); `canned_update` keeps the body it was
  not sent and the log never carries a body; Priya → 403 *"You may not work tickets here."*; Colin (collaborator) → 403 (the view admits holders of `tickets.work` only); the page's groups
  Mine · IT help · Everyone with Edit on one's own; the chips read → Pending, +hardware, ↑ High, assign me; search by words and by folder; support cannot change or delete a shared one;
  the lead can; a delete archives, logs and lands on the list; empty says its sentence.
- [x] **Rendering.** Every placeholder renders from the fixture ticket; nothing left unrendered; `send=no` answers `ok` with no location; with a mailbox signature on the queue
  `{{agent.signature}}` is it, else the display name; `{{foo}}` → 422 *"{{foo}} is not a placeholder."* by field; the form's preview renders against the sample ticket with the chips and
  warns about an unknown placeholder; the preview saves nothing and logs nothing; a ticket one cannot see → 404; another's private response → 404; the form lists the placeholders.
- [x] **Apply.** `send=no` returns the text and the chips, no message; `send=yes` writes one public reply by Marco on the web channel with the rendered text, applies → Pending and +hardware,
  bumps `usage_count`, logs `canned.apply` (canned id, sent, the side-effects applied) beside `ticket.reply`, `ticket.transition` (via canned) and `ticket.tag`, lands on the message, Jo is
  told by email; → Closed from New is **skipped and named in `did`** while ↓ Low applies and the reply goes; `apply_side_effects=no` sends the reply only; `ticket_reply` with `canned_id` +
  `apply_side_effects` applies the effects and logs `canned.apply`; the composer has the Canned button, the picker region and the effects region; the picker partial filters by words; the
  HTMX answer is the textarea with the text at the caret plus the chips and the checkbox out-of-band; no modal anywhere.
- [x] **Bulk.** Lena sees the four listed in the order given; Marco (support) and Priya → 403; the page lists them and asks to confirm; the inbox has the checkboxes and Change selected… for a
  lead, not for support; without `confirmed` → 422 *"Confirm the change to 3 tickets."*; nothing to change → 422; three tickets, one closed: two change (status, priority, assignee, the note on
  the two only), the closed one is refused with *"… cannot move from Closed."* and untouched (per-ticket transactions); one `ticket.bulk_update` row with the ids, the change keys, changed and
  refused, plus the per-ticket rows (via bulk); the result page with ✓ or the sentence and `HX-Trigger: ticketChanged`; assigning to the agent in bulk dispatches each.
- [x] **Per-type fields.** Root cause (a select, required to solve) for incidents only and Asset tag for every type: the partial for Incident shows both ("needed to solve"), for Question
  only Asset tag; with `?ticket=` the values come from the ticket; the form's type select reloads `#custom-fields`; solving an incident without it → 422 *"'SMOKE Root cause' is required to
  solve this ticket."*, with it → solved; a Question solves freely; a canned → Solved on it is skipped and named (the code first, as the table says).
- [x] **JSON mode.** `canned_create` by token with side-effects as JSON; `canned_apply send=no` → `{ok, text, side_effects}` and no location; `send=yes` → `{ok, did, record_id, message_id,
  side_effects, location}`; an agent's `send=no` under a run token is a read (nothing written — the kernel's hook pauses `send=yes` by action); the registry marks `canned_apply`
  external_send, `canned_delete` deletion, `tickets_bulk_update` other; `tickets_bulk_update` → `{ok, changed, refused, location}` and 422 in the sentence without `confirmed`.
- [x] **375 × 740 and 1280 × 800.** The list and the form within the width, every button ≥ 44 px; the preview renders as you type; a placeholder button inserts at the caret; the Canned
  button opens the picker under the composer (no modal), full width on a phone, filters as you type, inserting at the caret with the chips and the checkbox, then closes; the inbox selection
  opens `/bulk` with the two tickets and Apply shows the result page; the type select reloads the fields (Incident adds Root cause, Question drops it); the list renders with JavaScript off.

**Decisions taken while building (2026-10-03):** the agent pause for `canned_apply send=yes`, `canned_delete` and `tickets_bulk_update` is the kernel hook's, by action key as the registry
marks them — the app never fakes a 202 (`send=no` is a read: the text with `ok` and no location); a collaborator sees no canned responses at all (`mcp_canned_responses` admits `tickets.work`,
never modified) — the spec's "read only" collaborator could not be honoured; `{{agent.signature}}` is the queue's active mailbox signature, else the display name (the open question stands);
`html/tickets/fields.php` keeps slice 3's action on POST and answers the custom-fields partial on GET (one file, as the spec's name says); a field required to solve is checked after the
table's own requirement (the resolution code first); the picker inserts at the caret through a hidden `caret` field the composer keeps (an outerHTML swap of the textarea carrying the merged
text) and the chips travel with the reply as `canned_id` + `apply_side_effects`; a queue response needs the queue held and its lead flag or `tickets.manage`; a bulk assignment to an agent
dispatches each ticket as the single assignment does; `remove_tags` works on a closed ticket (no move); the list partial is `ticket-list.php` (slice 3's name), the checkboxes a GET form around
it that opens `/bulk`.

## Open questions
- `{{agent.signature}}`: a per-person signature needs a column nowhere in the schema (`notification_prefs` has none).
  This spec uses the queue's mailbox signature, else the display name; a personal signature is a Phase-1 schema addition
  if the owner wants one (`notification_prefs.signature`, additive).
