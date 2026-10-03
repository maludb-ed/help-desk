# Build spec: the vocabulary — slice 2

What exists at the end: the admin shapes what a ticket can be — statuses within the six fixed categories and the matrix of allowed moves
between them, ticket types, a tree of categories with routing defaults, tags, and custom fields for tickets, organizations and requesters —
and slice 3's create and update forms have a validator to call. Reference data, every row seeded by db/009 editable and the built-ins
protected by the database's own guard.
Schema: `ticket_statuses` + `hd_status_category_guard()`, `status_transitions`, `ticket_types`, `categories`, `tags`, `custom_field_defs`,
`hd_transition()` (db/009); the views `mcp_ticket_statuses`, `mcp_status_transitions`, `mcp_ticket_types`, `mcp_categories` (with `path`),
`mcp_tags`, `mcp_custom_field_defs` (db/015). Never modify them. **`hd_validate_custom_values()` is not in the schema**: this slice adds the
validator in PHP (below), and slice 3 calls it.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `statuses-list` | `/settings/statuses/` | The statuses in board order with category and clock; a link to the matrix |
| `status-add` | `/settings/statuses/new` | Add a custom status |
| `status-edit` | `/settings/statuses/{id}/edit` | Rename, recolour, reorder; on the board; clock runs |
| `transitions` | `/settings/statuses/transitions` | The matrix of allowed moves and what each requires |
| `vocabulary` | `/settings/vocabulary/` | Types, the categories tree, tags (`tab`) |
| `category-add` | `/settings/vocabulary/categories/new` | Add a category (`parent`) |
| `category-edit` | `/settings/vocabulary/categories/{id}/edit` | Change a category |
| `fields-list` | `/settings/fields/` | The custom field definitions |
| `field-add` | `/settings/fields/new` | Add a field (`applies_to`) |
| `field-edit` | `/settings/fields/{id}/edit` | Change a field |

## Statuses and the matrix
- `statuses-list`: a table (ledger) in `sort_order`: colour swatch, label, key, category chip, **On board**, **Clock runs** / **pauses**, built-in
  lock icon, open ticket count; **Move up / Move down** (re-save `sort_order` = the board's column order); **Add status**; **Transitions** button.
- `status-add` / `status-edit` (one `form.php`): label, category (radio of **open · pending · on hold · solved** for a new one; a built-in's is shown
  read-only: *"A built-in status keeps its category."*), colour (the locked seven), sort order, on board, clock runs (default from the category:
  pending/on_hold/solved pause). The key is derived from the label on create and never changes. The database's guard answers 422 in its own
  sentence when a form is forced (*"A custom status may be open, pending, on hold or solved — never new or closed."*).
- **Archive** (`status_archive`): a custom status only; refused while tickets hold it (*"N tickets are in Waiting on vendor — move them first."*);
  a built-in → 422 *"A built-in status is renamed, never archived."*
- `transitions`: a 6 × 6 grid of categories, rows = from, columns = to, the diagonal struck out and the `closed` row disabled (terminal). Each cell:
  an **allowed** toggle, and when allowed: requires (checkboxes resolution_code · hold_reason), may carry (pending_until · assignee), needs right
  (tickets.work · tickets.manage), label. Saving a cell POSTs `transition_save`; `allowed = no` **deletes the row** (the table has no `allowed`
  column — absence is refusal). A banner under the grid lists what the seeds mean in words. The grid is the same table `hd_transition()` reads,
  so the ticket page's status select (slice 3) and the board's drop rules (slice 4) follow a change at once.

## Types, categories, tags (`vocabulary`, three tabs)
- **Types**: a table — label, key, description, built-in lock, ticket count; add / edit inline (`type_save`); **Archive** for a custom type with no
  open tickets (`type_archive`); the four built-ins are renamed, never archived.
- **Categories**: a tree (indented rows, each `category-row-{id}`): name, requester-visible eye, routing defaults as chips ("→ IT help", "incident",
  "high", "Gold SLA"), ticket count; **Add child** on each row; drag not used — `sort_order` and `parent` are fields of the form. A cycle
  (a category under its own descendant) → 422 *"A category cannot sit under itself."*; an archived parent → 422.
- `category-add` / `category-edit`: name, parent (a select of the tree), description, default queue, default type, default priority, policy,
  requester visible, sort order. **Archive** keeps tickets' `category_id` (the view still names it) and hides it from forms.
- **Tags**: chips with usage counts; rename inline (`tag_save`: label and slug, the slug unique); **Delete** (`tag_delete`, confirm:
  *"Removes it from N tickets."*); a tag is also born on a ticket (slice 3's `ticket_add_tags` creates one by slug).

## Custom fields
- `fields-list`: three groups (Tickets · Organizations · Requesters), each a table: label, key, type, options count, "for incidents and problems"
  (per-type), required on solve, requester visible, archived muted. **Add field** per group.
- `field-add` / `field-edit`: label, key (derived; immutable), type (text · number · date · select · multi select · checkbox), options (one per
  line, for select kinds), applies to (fixed after create), ticket types (multi-select; ticket fields only), required on solve, requester visible,
  sort order. A unique `(applies_to, key)` clash → 422 *"A field with that key already exists for tickets."*
- **The validator** (`app/features/fields/validate.php`): `custom_values_validate(PDO $pdo, string $appliesTo, array $values, ?int $typeId = null): array`
  — returns `['values' => <cleaned, keyed by field key>, 'errors' => [key => sentence]]`. Rules: unknown key → error; archived field → dropped;
  a ticket field restricted to types the ticket is not → dropped silently; `number` numeric; `date` `YYYY-MM-DD`; `select` one of options;
  `multi_select` a subset; `checkbox` → `true`/`false`; `text` ≤ 2,000 chars; `required_on_solve` is NOT checked here — slice 3's
  `ticket_transition` to solved calls `custom_values_missing_on_solve(PDO, int $ticketId): array`, also in this file. Slice 3's `ticket_create`,
  `ticket_update`, `ticket_set_fields`, `organization_*` and `requester_*` saves pass their `custom` JSON through it; the form renders each
  definition from `fields_for(PDO, string $appliesTo, ?int $typeId): array` (same file).

## Files (exactly these)
- `html/settings/statuses/index.php` · `form.php` · `save.php` · `archive.php` · `transitions.php` (GET the grid) · `transition.php` (POST one cell)
- `html/settings/vocabulary/index.php` · `type.php` · `type-archive.php` · `categories/form.php` · `category.php` · `category-archive.php` · `tag.php` · `tag-delete.php`
- `html/settings/fields/index.php` · `form.php` · `save.php` · `archive.php`
- `app/features/statuses/queries.php` · `present.php` — `app/features/vocabulary/queries.php` · `present.php` — `app/features/fields/queries.php` · `present.php` · `validate.php`
- `app/views/statuses/list.php` · `form.php` · `transitions.php` · `partials/status-row.php` · `partials/transition-cell.php` — `app/views/vocabulary/index.php` · `partials/type-row.php` · `partials/category-row.php` · `partials/tag-chip.php` · `category-form.php` —
  `app/views/fields/list.php` · `form.php` · `partials/field-row.php` · `partials/field-input.php` (the one renderer slice 3 reuses)

## Query functions (signatures fixed)
- `find_statuses(PDO, bool $includeArchived = false): array` (with open counts) · `find_status(PDO, int $id): ?array` · `save_status(PDO, ?int $id, array $fields): int` · `archive_status(PDO, int $id): void` (throws the refusal sentences) ·
  `reorder_status(PDO, int $id, int $direction): void`
- `find_transitions(PDO): array` (the 6 × 6 as `[from][to] => row|null`) · `save_transition(PDO, string $from, string $to, ?array $cell): void` (null deletes)
- `find_types(PDO, bool $includeArchived = false): array` · `save_type(PDO, ?int $id, array $fields): int` · `archive_type(PDO, int $id): void`
- `find_category_tree(PDO, bool $includeArchived = false): array` (ordered, with depth and path) · `find_category(PDO, int $id): ?array` · `save_category(PDO, ?int $id, array $fields): int` (cycle check) · `archive_category(PDO, int $id): void`
- `find_tags(PDO, ?string $q = null): array` · `save_tag(PDO, ?int $id, string $label, ?string $slug): int` · `delete_tag(PDO, int $id): int` (tickets untagged, count returned) · `tag_by_slug_or_create(PDO, string $slugOrLabel): int` (slice 3 calls it)
- `find_fields(PDO, ?string $appliesTo = null, bool $includeArchived = false): array` · `find_field(PDO, int $id): ?array` · `save_field(PDO, ?int $id, array $fields): int` · `archive_field(PDO, int $id): void`
- `validate.php`: `fields_for()`, `custom_values_validate()`, `custom_values_missing_on_solve()`

## Handlers (every one: `require_post(); verify_csrf(); require_right('settings.manage')` — tags: `tickets.manage`; one transaction; `log_activity`; `emit_action_status`; a create's `location` ends in the id)
- `statuses/save.php` (`status_save`): the guard's P0001 → 422 with its sentence (`db_message()`); log `status.save` (`before`/`after` of changed fields; `after.category` on create).
  `archive.php` (`status_archive`): the two refusals; log `status.archive`.
- `statuses/transition.php` (`transition_save`): `from_category`/`to_category` in the six, not equal, `from` never `closed`; `requires[]` ⊆ {resolution_code, hold_reason}, `may_carry[]` ⊆ {pending_until, assignee},
  `needs_right` ∈ {tickets.work, tickets.manage}; log `transition.save` (`after.from_category`, `after.to_category`, `after.allowed`, `after.requires`, `after.needs_right`).
- `vocabulary/type.php` · `type-archive.php`: log `type.save` / `type.archive`. `category.php` · `category-archive.php`: cycle and archived-parent 422s; log `category.save` / `category.archive` (`before`/`after` incl. `parent_id` and the defaults).
  `tag.php` (`tag_save`, `tickets.manage`): slug `^[a-z0-9][a-z0-9_-]{0,49}$`, unique; log `tag.save`. `tag-delete.php` (`tag_delete`, `settings.manage`): log `tag.delete` (`after.tickets_untagged`).
- `fields/save.php` · `archive.php`: options required for select kinds (422), `applies_to` immutable (a changed value ignored), type_ids only for tickets; log `field.save` / `field.archive`.

## Action manifest entries
- Screens: the ten above. Actions: `status_save`, `status_archive`, `transition_save`, `type_save`, `type_archive`, `category_save`, `category_archive`, `tag_save`, `tag_delete`, `field_save`, `field_archive`.
  Agent approvals: every one `other`, except `tag_save` (none) and `tag_delete` (`deletion`).

## Activity log events
`status.save`, `status.archive`, `transition.save`, `type.save`, `type.archive`, `category.save`, `category.archive`, `tag.save`, `tag.delete`, `field.save`, `field.archive`, `screen.view` (each screen) —
each configuration row with `before`/`after` of the changed fields only, for `config_change_log`.

## Out of scope for this slice
Setting a ticket's status, type, category, tags or custom values (3 — through the validator and `hd_transition()`); the board's columns (4); category routing at create (3 reads `default_*`).

## Proof (`tests/phase3/slice2/run.sh`, scratch database, `php -S` + headless Chromium at 375 × 740 and 1280 × 800)
- [ ] The guard: a new status with category `new` or `closed` → 422 in the database's sentence; changing a built-in's category → 422; a second default → refused by the unique index (422 in words).
- [ ] Archive: a custom status holding 2 open tickets → 422 naming the count; empty → archived and gone from forms, still named by the view; a built-in → refused.
- [ ] The matrix: turning `open → solved` off deletes the row and `hd_transition(open, solved)` answers nothing; turning it back on with `requires = {resolution_code}` restores it; `closed →` anything cannot be saved; a cell in the same category is not shown (always allowed).
- [ ] Categories: a cycle → 422; the tree renders paths ("Billing › Refunds") equal to `mcp_categories.path`; `requester_visible = false` hides it from `mcp_categories` for a requester-only member (SQL as records_ro).
- [ ] Tags: delete untags N tickets and returns N; a duplicate slug → 422; `tag_by_slug_or_create('Hard Ware')` → slug `hard-ware`, reused on the second call.
- [ ] Fields: `custom_values_validate('ticket', {...}, incidentTypeId)` drops a field restricted to problems, refuses a bad date and an unknown option, booleans a checkbox; `custom_values_missing_on_solve()` names the required-on-solve fields a ticket lacks (fixture by SQL).
- [ ] Rights: support → 403 on every screen (*"You may not change Help Desk's settings."*); a lead may `tag_save` and not `tag_delete`.
- [ ] JSON mode on every handler; `php bin/build_action_registry.php --check` passes and the 11 actions read as built; 375 and 1280 clean.

## Open questions
- The manifest's `tag_save` says Who = `lead` but no right named "tags" exists in db/004; this spec gates it on `tickets.manage` (a lead holds it). Confirm or add a right.
- The manifest's `transition_save` has an `allowed (yes or no)` param while `status_transitions` has no such column: this spec reads `no` as "delete the row". Confirm.
