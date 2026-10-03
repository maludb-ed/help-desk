# Build spec: views and the board — slice 4

Replicates slice 3's files, gates, flow and proof style. What exists at the end: every list of tickets in Help Desk —
the inbox, the standard queues, a lead's shared views, a person's own — is a **saved view**: one remembered filter over a
bounded vocabulary, a sort, columns and a **layout**, list or board. The **board** renders a view as columns (status by
default), cards move by drag or by a select, and every drop is the same status change slice 3's ticket page makes
(`transition_ticket()`), with the same refusals in the same words. The inbox (slice 3) switches to reading its filters
through this slice's one translator, so a view, an ad-hoc filter and the `board_snapshot` tool can never disagree.
Schema: `saved_views` (db/011: eight standard views seeded, `team_board` among them), `tickets.board_position` (db/010),
`ticket_statuses.on_board` / `sort_order` (db/009), the views `mcp_saved_views`, `mcp_tickets`, `mcp_ticket_statuses` (db/015),
`hd_transition()` (db/009). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `board` | `/board?view=&swimlane=&assignee=&priority=&q=` | A saved view as columns; drag or "Move to…" changes status |
| `views-list` | `/views/` | The views I may open: standard, mine, my queues', with layout and a count |
| `view-add` | `/views/new?layout=&from=` | Save the current filters as a view or a board |
| `view-edit` | `/views/{id}/edit` | Change a view's filters, sort, columns, layout, board settings |

## The filter vocabulary (the ONE translator, `view_filters_sql()`)
`filters` is a JSON object whose keys are exactly these; any other key is refused on save (422 *"'x' is not a filter."*)
and ignored on read of an old row:

| Key | Value | SQL on `mcp_tickets` |
|---|---|---|
| `status_category` | `["new","open","pending","on_hold","solved","closed"]` subset | `status_category = ANY($n)` |
| `status` | one status key | `status = $n` |
| `queue_id` | `[ids]` | `queue_id = ANY($n)` |
| `assignee` | `"me"` / `"none"` / a member id | `assignee_member_id = <caller>` / `IS NULL` / `= $n` |
| `requester` | `"me"` | `requester_id = (SELECT hd_my_requester_id())` |
| `organization_id` | an id | `organization_id = $n` |
| `priority` | `["low","normal","high","urgent"]` subset | `priority = ANY($n)` |
| `type_id`, `category_id` | `[ids]` | `= ANY($n)` |
| `tags` | `["slug"]` | `tags && $n` |
| `needs_reply` | `true` | `needs_reply` |
| `sla` | `"at_risk"` / `"breached"` | `sla_state = $n` |
| `stale_days` | 1–365 | `last_activity_at < now() - make_interval(days => $n)` |
| `channel` | one channel | `channel = $n` |
| `text` | words | `(subject ILIKE $n OR number ILIKE $n)` — `%words%`, start of a word for a number |

- `sort_by` allow-list: `last_activity_at`, `created_at`, `priority` (urgent first by `CASE`), `sla_next_due_at` (nulls last),
  `number`, `status`, `requester_name`, `assignee_name`; `sort_dir` asc/desc. **Never** a value from the request as an identifier.
- `columns` allow-list: `number, subject, requester, organization, queue, status, priority, type, category, assignee, tags, sla, needs_reply, created, updated, due`.
- `board` settings: `column_field` ∈ `status` (default) `priority` `assignee` `queue` `type`; `swimlane` ∈ null `priority` `queue`
  `assignee` `type` `organization`; `wip` = `{"<column value>": n}` soft limits; `solved_days` 0–30 (default 3).
- The translator answers `[string $where, array $params]` over `mcp_tickets` (the view already decides who sees what —
  the filter only narrows). The inbox (slice 3) calls it for `?q=&status=&queue=&assignee=&priority=` by building the same
  object; the `board_snapshot` and `find_tickets` tools (Phase 4) port the same table to Python, key for key.

## Views list and form
- `views-list`: three card groups — **Standard** (the eight seeded, in order), **Mine**, **Shared** (global and my queues'
  non-standard ones); each card: name, layout icon (list `feather-list`, board `feather-trello`), a count of matching
  tickets (one `count(*)` per card through the translator — 50 cards at most), **Open**, and for one I may change **Edit**.
  Empty **Mine**: *"Save any filtered list as a view — from the inbox, Save as view."*
- `view-add` / `view-edit`: name, scope (**Personal**; **Queue** → a queue I hold, for `views.shared`; **Global** for
  `views.shared`), the filter builder (one row per key: a select of the key, then the control the key needs — chips for
  lists, a select for `assignee` with *me / nobody / a person*, a number for `stale_days`), sort, columns (checkboxes in
  the allow-list order), layout, and for a board: column field, swimlane, WIP per column, solved days. `?from=` carries the
  inbox's current filters as the starting point. A standard view's form shows the filters read-only with *"A standard view
  cannot be changed — save a copy."* and a **Save a copy** button (`view_create` with the same filters).

## The board
- `?view=` names a view with `layout = board` (default `team_board`); a list view opened on `/board` renders as a board with
  status columns (its filters, the default board settings). The page header: the view's name, the swimlane select, filters
  on top of the view's (assignee select with *me*, priority chips, a text box — applied as extra keys through the same
  translator), **Save as view** (→ `view-add?from=`), **List** (→ `/inbox?view=`).
- **Columns** = the values of `column_field`: for `status`, the statuses with `on_board` in `sort_order`, closed never;
  solved shows cards solved in the last `solved_days`. For `priority` the four; `assignee` the queue members who hold a
  card plus *Unassigned*; `queue` the queues held; `type` the types. Header: name, count, `count / limit` in `text-danger`
  when a WIP limit is exceeded (soft — nothing blocks). Ids: `board-columns`, `board-column-{value}`,
  `board-column-{value}-count`, `board-card-{ticket_id}`.
- **Swimlanes** render as stacked boards with a lane header (`board-lane-{value}`); the page never scrolls sideways beyond
  the column strip.
- **Card** (`app/views/board/partials/card.php`, reused by `board_snapshot` as its JSON shape): number + subject (link to
  `ticket-view`), requester with organization in `<small>`, priority badge (urgent `danger`, high `warning`, normal `info`,
  low `secondary`), assignee name or *Unassigned* (an agent shows a `feather-cpu` mark), **SLA strip** on the left edge
  (`danger` breached, `warning` at risk, none on track, muted paused), the next due as a chip *"due in 2 h"*, a
  **needs-reply** dot, up to three tags, age. Every card carries `data-ticket-id`, `data-column`.
- **Drag between columns** (SortableJS 1.15, vendored under `html/assets/vendors/sortablejs/` as Projects did; `group:
  board`): `POST /board/move.php` (`board_move_card`: ticket, column, position). For `column_field = status` the handler
  calls slice 3's `transition_ticket()`; when `hd_transition()` says the move **requires** a field, the response is the card
  re-rendered **expanded** with the inline control (a resolution-code select for solved, a hold-reason select for on hold,
  a date-time for pending) and **Confirm** / **Cancel**; Confirm re-posts with the field; Cancel re-renders the card in its
  old column. A move the table forbids answers 422 with `hd_transition()`'s sentence (*"HD-1042 cannot move from Closed."*)
  and the card snaps back (the JS swaps the response into the old column). For `priority` the drop is `ticket_set_fields`;
  for `assignee` / `queue` it is `ticket_assign`; for `type` `ticket_set_fields` — all through slice 3's query functions,
  never a second write path. The response carries `HX-Trigger: ticketChanged` and the two columns' counts out-of-band.
- **Drag within a column** → `POST /board/reorder.php` (`board_reorder`): `board_position` = the rank among the column's
  visible cards; a card without a position sorts last by `last_activity_at`.
- **No-JS and phone**: each card has a **Move to…** select listing only the moves `hd_transition()` allows from its status
  (labels from the transitions table) that POSTs `board_move_card`; at 375 px the column strip scrolls horizontally
  **inside** `#board-columns` with `scroll-snap-type: x mandatory`, columns full-width, the header filters in the page
  header's offcanvas.
- **Freshness**: `#board-columns` re-fetches on `hx-trigger="every 30s [document.visibilityState=='visible'], ticketChanged from:body"`
  (Pattern B partial); a drag in progress defers the swap (`htmx:beforeSwap` cancelled while `.sortable-drag` exists).

## Files (exactly these)
- `html/board.php` · `html/board/move.php` · `html/board/reorder.php` · `html/views/index.php` · `html/views/form.php` · `html/views/save.php` · `html/views/delete.php`
- `app/features/views/queries.php` · `present.php` · `filters.php` (`view_filters_sql()`, the allow-lists, `view_filters_from_request()`) · `board.php` (`board_columns()`, `board_cards()`, the shape `board_snapshot` reuses)
- `app/views/views/list.php` · `form.php` · `partials/view-card.php` · `partials/filter-row.php` — `app/views/board/board.php` · `partials/column.php` · `partials/card.php` · `partials/move-select.php`
- `html/assets/vendors/sortablejs/Sortable.min.js` (1.15.x, MIT, licence file beside it)

## Query functions (signatures fixed)
- `view_filters_sql(array $filters, int $memberId): array` → `[$where, $params]`; `view_sort_sql(string $sortBy, string $sortDir): string`; `view_filters_from_request(array $get): array` (the inbox's and the board header's keys → the vocabulary; unknown ignored); `view_filters_validate(array $filters): array` (errors by key)
- `find_views(PDO, int $memberId, ?string $q = null, ?string $layout = null, int $limit = 50): array` (`mcp_saved_views`) · `find_view(PDO, int $id): ?array` · `find_view_by_key(PDO, string $systemKey): ?array` · `view_count(PDO, array $view, int $memberId): int`
- `save_view(PDO, ?int $id, array $f, int $by): int` · `delete_view(PDO, int $id, int $by): void` (never `is_system`)
- `board_columns(PDO, array $view, array $extraFilters, int $memberId): array` (`[{key, label, color, count, wip, cards:[…]}]`, lanes when a swimlane is set) · `board_cards(PDO, array $view, array $extraFilters, int $memberId, int $cap = 500): array` (`mcp_tickets` through the translator, `board_position NULLS LAST, last_activity_at DESC`) · `reorder_card(PDO, int $ticketId, int $position): void` · `allowed_moves(PDO, int $ticketId): array` (`hd_transition()` for each status: `[{status_id, label, requires}]`)

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity` with `ticket_id`/`queue_id` where there is a ticket; `emit_action_status`; `HX-Trigger`)
- `views/save.php` (`view_create` / `view_update`): personal → `require_right('views.personal')`; queue or global → `require_right('views.shared')` (and the queue held); a standard view → 422 *"A standard view cannot be changed — save a copy."*; `view_filters_validate()` → 422 by key; log `view.create` / `view.update` (`after`: scope, layout, the filter keys, queue_id); `location` `/views/{id}/edit`; `HX-Trigger: viewChanged`.
- `views/delete.php` (`view_delete`): own, or `views.shared` for a shared one; never a standard one (422); log `view.delete`.
- `board/move.php` (`board_move_card`): `require_can_work_ticket($ticket)`; resolve `column` to the field's value; status → `transition_ticket($pdo, $ticket, $statusId, $fields, ['via' => 'board'])` (slice 3's; it logs `ticket.transition` with `after.via = 'board'`, raises the required-field case as a typed exception the handler turns into the expanded card, and a forbidden move into 422 with the sentence); priority/type → slice 3's `set_ticket_fields()` (logs `ticket.set_fields`); assignee/queue → `assign_ticket()` (logs `ticket.assign`); then `reorder_card()`; log `board.move` beside (`before.column`, `after.column`, `after.position`, `after.view_id`); answer the card partial (+ counts OOB) for HTMX, `{ok, ticket_id, status}` in JSON mode; `HX-Trigger: ticketChanged`.
- `board/reorder.php` (`board_reorder`): `require_can_work_ticket`; `reorder_card()`; log `board.reorder` (`after.position`); 204 for HTMX.

## Action manifest entries
- Screens: `board`, `views-list`, `view-add`, `view-edit`.
- Actions: `view_create`, `view_update`, `view_delete`, `board_move_card`, `board_reorder`. No agent approval of its own: a
  drop into solved or closed carries slice 3's rules (an agent's `ticket_close` pauses; a transition to solved does not).

## Activity log events
- `screen.view` (board with `after.view_id`, views-list, view-add, view-edit), `view.create`, `view.update`, `view.delete`,
  `board.move` (`before.column`, `after.column`, `after.position`, `after.view_id` — beside the `ticket.transition` /
  `ticket.set_fields` / `ticket.assign` row the underlying change writes), `board.reorder`. Every ticket row carries
  `ticket_id` and `queue_id`.

## Out of scope for this slice
- The ticket page and the inbox themselves (slice 3 — this slice gives the inbox its translator); canned responses and bulk
  update from a list selection (slice 5); SSE live boards (the 30 s refresh is the design; SSE only if the owner asks);
  per-view notifications; a calendar layout (Later).

## Proof (`tests/phase3/slice4/run.sh`: the scratch database, slice 3's world, members through dev hand-offs, curl with signed action tokens and HTMX-shaped posts, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-03: 114 checks green** (translator 11, who 27, board 17, drops 29, json 11, browser 19, the registry); Phase 2's, slice 1's, slice 2's and slice 3's suites
still green; the installer's `plan` clean.
- [x] **The translator is the law.** Seventeen keys, each with a ticket that matches and one that does not, through the inbox's query keys (`status`, `status_key`, `queue`, `assignee=me|none|id`,
  `requester=me`, `organization`, `priority`, `type`, `category`, `tags`, `needs_reply`, `sla`, `stale_days`, `channel`, `q`); the inbox with `?status=open&assignee=me` and the saved view
  `{"status_category":["open"],"assignee":"me"}` answer the same ids; `'foo'` on save → 422 *"'foo' is not a filter."*; bad values → 422 by key; `sort_by` as SQL on save → 422 by key and the
  tickets are still there; `?sort=foo` on the inbox → the default order; the form's arrays (blanks dropped, tags split, columns allow-listed).
- [x] **Who sees which view.** Priya: the eight standard views and a global one, none of Marco's or the queues'; her `view_create` with scope global → 403 *"You may not change shared views."*, a
  personal one → 403 (no `views.personal`); Marco: his own, IT's queue view, not Facilities' (a direct URL → 404 *"View not found."*); Lena cannot open Marco's personal view; the inbox and the
  board ignore a view one may not see; a queue view for a queue the lead does not HOLD → 422 (`view_all` is not holding); a standard view's delete and change → 422 in the sentence, Save a copy
  → a personal copy with its filters; support changing or deleting a shared view → 403; the lead renames one and sets a swimlane, the filters it was not sent kept, `view.update` logged; the list
  carries `may_change`, the count and the layout; cards show Open on every view and Edit only on one I may change; empty Mine says its sentence.
- [x] **The board shows only what the view's audience may see.** Marco's team board: the five status columns in order, IT's tickets, none of Facilities' or the general queue's; Priya → 403;
  the closed ticket on no column; solved today shows, solved 5 days ago absent with `solved_days = 3` and present with 7; the WIP limit in the header as *count / limit*; swimlanes by priority as
  lanes with lane-scoped column ids; columns by assignee (Unassigned first); a list view opened on `/board` renders as a status board with its filters; a status taken off the board loses its
  column; the header filters ride on the view through the translator; `screen.view` carries `after.view_id`.
- [x] **A drop is a transition.** New → Open by the drag's post: the card re-rendered in Open with `HX-Trigger: ticketChanged` and the two columns' counts out-of-band, one `ticket.transition`
  (via board) and one `board.move` (before new, after open, position, queue_id); Open → Solved comes back expanded with a resolution-code select, Confirm and Cancel, nothing moved; Confirm with
  resolved → solved, the requester told; a Closed card → 422 *"HD-… cannot move from Closed."* retargeted to `#flash`; an unknown column → 422; Priya's forced POST on her own ticket → 403, on
  one she cannot see → 404; open → closed by support → the Lead sentence; within a column, x ranked first and y second survive a reload, `board.reorder` logged, a negative rank → 422; a priority
  board's drop is `ticket_set_fields`, an assignee board's `ticket_assign` (the person told, the agent dispatched, someone outside the queue → 422).
- [x] **No JavaScript.** The card's "Move to…" lists exactly `hd_transition()`'s moves for support from New; the plain POST → 302 back to the board and the card is in Open on reload; a move
  needing a field → 302 to `?expand=&to=`, which renders that card expanded.
- [x] **JSON mode.** `board_move_card` → `{ok, ticket_id, status, column, location}`; the required-field case → 422 `{error: {code: invalid, fields: {resolution_code: …}}}`; `board_reorder` →
  `{ok, position}`; `view_create` by an agent with JSON filters, columns as a list and board JSON; `view_update` keeps what it was not sent; filters that are not JSON → 422; `view_delete`; the
  board answers JSON under a token in `board_snapshot`'s shape (a card carries column, board_position, age_words, sla, status).
- [x] **375 × 740 and 1280 × 800.** The column strip scrolls inside `#board-columns` (page `scrollWidth` = viewport), one column per snap, every button and select ≥ 44 px, the views list and
  the form within the width; five columns visible on the desktop with the swimlane select in the header; a REAL drag of a New card into Open re-renders it there and the region re-fetches;
  a drag into Solved expands the card, Confirm settles it in Solved; choosing Priority swimlanes renders lanes without a page load; the views list as cards; the board and its selects render
  with JavaScript off; no console errors (an aborted stale refresh, `hx-sync`, is by design).

**Decisions taken while building (2026-10-03):** the translator lives in `app/features/views/filters.php` and slice 3's `ticket_where()` now delegates to it (`find_tickets_filtered()` sorts
through `view_sort_sql()`, the inbox's short sort keys kept as aliases); the inbox reads its ad-hoc keys through `view_filters_from_request()` and accepts `?view=` as an id or a system key;
the filter builder is one fixed row per key (fourteen rows, each its control) rather than a key select — honest without JavaScript; the manifest's two board rows name `/board/move.php` and
`/board/reorder.php` absolutely (the section's base is `/views/`); a queue view needs a queue the caller HOLDS (`hd_held_queue_ids()`), not merely sees; a request from inside the region
(a drag, the card's forms) selects `.board-card` from its response — it inherits the region's `hx-select` otherwise and the card vanishes; the filter form carries `hx-sync="#board-columns:replace"`
so a person's change wins over a stale refresh; `board_reorder` shifts the cards at or after the rank within the same column (no extra parameter); a column's move table is
`board_move_table()` — `hd_transition()` once per pair of statuses a page, never per card; "Views" is a Work menu item (`tickets.view_queue`) and the inbox gained Views, Save as view and
Board links; the view form's queue select lists the queues held. The open question below stands as written: a queue view is visible to everyone who holds the queue.

## Open questions
- Should a **queue** view be visible to the queue's collaborators (read) or only to those with `tickets.work`? This spec
  says: everyone who holds the queue (the view's rows are filtered by `mcp_tickets` anyway).
