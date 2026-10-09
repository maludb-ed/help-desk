# The record picker (2026-10-09)

The owner's decision (the builder plugin's `design-system/references/record-picker.md`; the plan in the plugin's
`docs/record-picker-plan.md`): a form chooses a **record** — a person, an organization, anything a person adds rows to without
limit — through one searchable modal, never a `<select>` that lists every row. ProcessCore's purchase order is the exemplar and
the cidery the first retrofit; this is the mechanical retrofit of Help Desk. The picker is the second exemption to "no modals"
(beside `hx-confirm`): it only chooses and closes, shows no record and holds no form.

- **Added:** `app/picker.php` (`picker_field()`, `picker_label()`, `picker_like()`, `picker_result()`; required from the
  bootstrap), `app/pickers.php` (the sources), `html/pick/index.php` (the endpoint), `app/views/shared/picker-rows.php`,
  `assets/js/record-picker.js`, the RECORD PICKER section of `app-overrides.css`, the `#record-picker` modal and the script in
  `layout.php`. Help Desk has no `form_row_open()` and no unsaved-changes guard, so there is no `form_picker()` and the guard
  line is not needed: forms are hand-written rows and call `picker_field()` inside their own label markup, the label's `for`
  pointing at `{id}-open`.
- **Sources (each reads the mcp view its screen's select read, so it lists nothing the select would not have; the endpoint
  refuses an agent and answers the right the screens need — any one of `a|b`, `nav_has_right()`):**
  `person` (people, no agents; `tickets.view_queue|tickets.work`), `assignee` (a person or an agent, param `queue` = only that
  queue's people, named or through its department; `tickets.work|tickets.manage`), `member` (anyone nameable in a queue, param
  `not_named_in` = leave out those already named; `settings.manage|queues.members`), `organization` (active, by name or
  domain; `tickets.view_queue|views.personal|views.shared`). Queries added beside their feature's: `find_assignable_members_page()`
  (`app/handler.php`), `find_organizations_page()` (`requesters/queries.php`), both 25 a page with an ILIKE search.
- **Converted (the hidden input keeps each select's name and id; no save handler, `hx-include` or manifest row changed):**
  the new-request form's *on behalf of* (`person`); the requester form's *organization* and the requesters list's organization
  filter (`organization`, Clear = any company); the saved-view form's organization filter (`organization`; disabled and not posted
  on a standard view, as before); the ticket page's *assignee* (`assignee` **dependent**: it includes `#ticket-assign-form-field-queue`,
  so the list follows the queue field — the handler already requires the assignee to be in the new queue; Clear posts an empty
  value, which `assign.php` reads as Nobody; the Save button is appended to the group); a queue's *default assignee* (`member`);
  the queue page's *Add a member* (`member`, the queue a fixed param, required).
- **Still selects, by the rule (D7):** queues (13 uses; a short list per department that the screens also use as filters and
  as the ticket's dependent field), categories (a tree with depth indentation: a picker would have to show the path), types,
  statuses, priorities, resolution and hold reasons, policies, business-hours schedules, departments, KB sections, tiers,
  folders — configuration lists. **Left on purpose although they list people or organizations:** the inbox, board and saved-view
  *assignee* filters and the bulk form's *assignee* (their options include the sentinels Me / Nobody / Keep a record cannot be),
  the canned response's *assign* side effect (Me / Nobody sentinels), the SLA policy's *organizations* (a multiple select), and
  the automation rule's condition and action rows (arrays of selects built per row). **Not touched:** the composer's article
  picker (`kb/partials/article-picker.php`) — a different interaction: it searches as you type under the reply box and inserts or
  links an article; it never fills a field. **Lost on purpose:** "Me" in the ticket page's assignee (search your own name; the
  row says "you").
- **A 422 does not re-render a form here:** Help Desk's forms post into `#flash` and a refusal leaves the page as it is, so there
  is no re-render to keep a label across (the exemplar's check); the proof shows a refused save changes nothing instead. A first
  render with an id from the query string (`?on_behalf_of=`, `?organization=`) shows the label through the source's `label`.
- **Proven:** `tests/picker/run.sh` (66 checks: the endpoint's refusals by right, search, escaping, paging past 25, the dependent
  and undeclared params, every converted form rendering its picker, saves through the hidden inputs, Clear) and, with
  `BROWSER=1`, `tests/picker/picker-browser.mjs` (headless Chromium: keyboard, Enter in the search box, Show more, the dependent
  list following the queue field, Save through the picker, 375 px full-screen dialog, dark mode). The scratch database is built by
  the script itself and needs a scratch `config/.env` (see CLAUDE.md: `tests/setup_dev.sh` alters the cluster's roles).
