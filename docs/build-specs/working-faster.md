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

## Proof (live as members; curl for JSON mode; headless Chromium at 375 × 740 and 1280 × 800)
- **Scope and rights**: Marco's personal response is on his list and not on Dana's (a direct URL → 404 *"Canned response
  not found."*); an IT-queue response shows to Marco and Sam (collaborator — read only, no Use button since no
  `tickets.work`) and not to Dana; a global one to everyone with `tickets.work`; Priya (Requester) gets 403 on
  `/canned/` with *"You may not work tickets here."*; Marco's `scope=global` save → 403.
- **Rendering**: every placeholder of the list renders from fixture HD-1001 (grep the output for each value); an unknown
  placeholder → 422 naming it; `{{agent.signature}}` falls back to the display name; the preview saves nothing (row count unchanged).
- **Apply**: `send=0` returns the text and writes no message; `send=1` writes one public reply (`messages.kind =
  'public_reply'`, `channel = 'web'`), applies `→ Pending` and `+hardware`, bumps `usage_count`, logs `canned.apply` and
  `ticket.reply`; a side-effect status the table forbids (→ Closed from New) is **skipped** and named in `did`; an agent's
  `canned_apply` with `send=1` is `pending_approval` (Phase 4 proves the hook; here the handler answers 202 under a run
  token with a stubbed hook as txtSchedules' slice proofs did).
- **Bulk**: three tickets, one of them closed: two change, the closed one is reported with *"…cannot move from Closed."*
  and the others keep their change (per-ticket transactions); the note lands on the two; Priya's POST → 403; without
  `confirmed` → 422 *"Confirm the change to 3 tickets."*.
- **Per-type fields**: a field restricted to `incident` appears when the type select changes to Incident and not for
  Question; `required_on_solve` refuses the solve with the field's label in the sentence.
- **Composer**: the Canned button opens the picker under the composer (no modal), filters as you type, inserts at the
  caret, shows side-effect chips; at 375 px the picker is full width and the buttons ≥ 44 px; no horizontal scroll at
  375 and 1280.
- JSON mode: `canned_apply` and `tickets_bulk_update` answer the contract; `php bin/build_action_registry.php --check` passes.

## Open questions
- `{{agent.signature}}`: a per-person signature needs a column nowhere in the schema (`notification_prefs` has none).
  This spec uses the queue's mailbox signature, else the display name; a personal signature is a Phase-1 schema addition
  if the owner wants one (`notification_prefs.signature`, additive).
