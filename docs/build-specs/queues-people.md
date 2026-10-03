# Build spec: queues and people — slice 1 (the CRUD exemplar for workers)

Built first and copied by every later CRUD slice: cards for named things, full-page create/edit, the gate in SQL and mirrored in PHP,
JSON mode on every handler. What exists at the end: an admin shapes the desk — queues linked to departments with their members, leads,
assignment method and default assignee (a person or an OS agent); SLA policies and business-hours schedules; the business's settings —
and support staff keep the customer companies and the people who ask. Nothing here touches a ticket yet (slice 3).
Schema: `app_settings`, `organizations`, `organization_domains` (db/005); `requesters`, `requester_emails`, `hd_requester_for_member()`,
`hd_requester_for_email()` (db/006); `business_hours`, `business_hours_intervals`, `business_hours_holidays`, `sla_policies`,
`hd_business_add_minutes()` (db/007); `queues`, `queue_members`, `queue_presence`, `hd_held_queue_ids()`, `hd_queue_ids_with_right()`,
`hd_is_queue_lead()` (db/008); `agent_dispatches` (db/013); the views `mcp_organizations`, `mcp_requesters`, `mcp_queues`,
`mcp_queue_members`, `mcp_business_hours`, `mcp_sla_policies`, `mcp_agent_dispatches`, `mcp_members` (db/015). Never modify them.

## Screens

| Screen id | Canonical URL | Purpose |
|---|---|---|
| `queues-list` | `/settings/queues/` | The queues as cards |
| `queue-add` | `/settings/queues/new` | Add a queue |
| `queue-view` | `/settings/queues/{id}` | One queue: members, leads, defaults, mailbox, policy, hours |
| `queue-edit` | `/settings/queues/{id}/edit` | Change a queue |
| `organizations-list` | `/organizations/` | The customer companies as cards (`q`, `tier`) |
| `organization-add` | `/organizations/new` | Add a customer company |
| `organization-view` | `/organizations/{id}` | One organization: domains, people, tickets, SLA, settings |
| `organization-edit` | `/organizations/{id}/edit` | Change an organization |
| `requesters-list` | `/requesters/` | The people who ask, as cards (`q`, `kind`, `organization`) |
| `requester-add` | `/requesters/new` | Add an external requester by hand (`organization`) |
| `requester-view` | `/requesters/{id}` | One requester: details, organization, addresses, tickets, notes |
| `requester-edit` | `/requesters/{id}/edit` | Change a requester |
| `sla-list` | `/settings/sla/` | The policies in match order and the schedules |
| `sla-policy-add` | `/settings/sla/new` | Add a policy |
| `sla-policy-edit` | `/settings/sla/{id}/edit` | Change a policy |
| `business-hours-edit` | `/settings/sla/hours` | A schedule's weekly hours and holidays (`schedule`) |
| `general-settings` | `/settings/general` | The business's Help Desk settings |
| `agents` | `/settings/agents` | Which agents work here (read-only) |

## Queues
- `queues-list`: one `RecordCard` per live queue (`queue-card-{id}`): name, department chip ("IT · members join"), member count (named +
  department), lead names, open count, assignment method as words ("Round robin", "Least loaded", "Goes to Frontline"), the mailbox address
  when one exists (slice 7). The default queue carries a **Default** badge. **Add queue** for `settings.manage`. Empty: never (General is seeded).
- `queue-view`: the card's facts; **Members** (named rows with a Lead toggle and Remove; department members listed muted as "through IT",
  not removable here — "Leave the department in the kernel"); **Add member** (a select of active members, people and agents, with a kind chip,
  and an "as lead" checkbox); **Presence** rows for support members (availability, capacity — read-only here; a person sets their own on
  `/settings/`, `queue_presence_set`); the policy, schedule and default assignee as links; **Edit** and **Archive** (`settings.manage`).
- `queue-add` / `queue-edit` (one `form.php`): name, key (derived from the name when blank; immutable after create), description, department
  (a select of live departments from the mirror, "None"), include department members (checkbox, shown only with a department), assignment
  method (radio: Manual · Round robin · Least loaded · Default assignee), default assignee (a select of people **and agents**; required when the
  method is Default assignee), schedule, policy, email address (slice 7 reads it), sort order. The **General** queue's key is fixed.
- **Archive**: refused for the default queue (*"Choose another default queue first."*) and otherwise moves its open tickets (status
  category not solved/closed) to the default queue in the same transaction, logging one `ticket.assign` per moved ticket (`after.via = 'queue_archive'`).

## Organizations and requesters
- `organizations-list`: cards (`organization-card-{id}`): name, tier chip, domains as small text, open ticket count, "members see each other" dot.
  Search by name or domain; filter by tier. Empty: *"No customer companies yet. Add one, or they appear when a customer writes in."*
- `organization-view`: the facts; **Domains** (add / remove inline, each a chip); **People** (requester cards of the organization — slice 3 links them
  to their tickets); **Tickets** (the organization's, slice 3 fills; until then the count); SLA override as a link; Edit; Archive (`tickets.manage`,
  confirm; refused while open tickets exist: *"Solve or move its N open tickets first."*).
- `requesters-list`: cards (`requester-card-{id}`): name, kind chip (**Member** · **External** · **Agent**), organization, email and phone
  (**only** when the view gives them: `tickets.view_queue`), ticket count, last seen. Filters: kind, organization, search by name or email. A
  member's card links to nothing in the kernel (no directory writes). Empty: *"Nobody has asked for anything yet."*
- `requester-view`: details, organization (a select for `queue`), **Addresses** (each email, verified mark; add / remove; the primary first), notes
  (support's, never shown to the requester), tickets (slice 3), **Merge into…** (`tickets.manage`: a requester search; confirm page "Jo's N tickets
  and 2 addresses move to Jo Customer"). A member's or an agent's row: name and email are the mirror's and read-only (*"Managed by the OS."*);
  only organization and notes are editable.
- `requester-add` / `requester-edit`: display name, email (required on add; the handler calls `hd_requester_for_email()` so an address already
  known answers 422 *"That address belongs to <name> — open them instead."* with a link), phone, organization, locale, timezone, notes.

## Service levels and business hours
- `sla-list`: policies as cards in `sort_order` (`policy-card-{id}`): name, "matches: any organization · IT queue · urgent and high", the four
  priorities' targets as a compact grid, business or calendar hours, warning %, active; drag not needed — **Move up / Move down** arrows re-save
  `sort_order`. Below, **Schedules**: each with its weekday summary ("Mon–Fri 09:00–17:00", holidays count) and **Edit hours**. The seeded
  **Standard** policy cannot be archived (*"Standard applies when nothing else matches."*).
- `sla-policy-add` / `-edit`: name, description, sort order, **Matches** (multi-selects: organizations, queues, types, priorities, tags — empty means
  any), **Targets** as a grid: rows urgent/high/normal/low × columns first response / next response / resolution, minutes (blank = no target),
  schedule (select, "Calendar hours"), warning %. The handler builds `conditions` and `targets` jsonb; an agent sends them as JSON.
- `business-hours-edit`: the schedule's name, time zone (IANA select), a weekday × interval grid (each weekday: up to two opens/closes pairs,
  blank = closed), is_default; **Holidays**: a date list with add / remove. A **Preview** line: "60 minutes from Friday 16:30 lands Monday 09:30"
  computed by `hd_business_add_minutes()` as you type (`?preview=`). **New schedule** from the same form without `schedule`.
- `general-settings` (`/settings/general`, GET the form, POST = `settings_save`): prefix (uppercase, ≤ 8; changing it renumbers nothing),
  auto-close days, reopen window days, secure-link days, default queue (select), request form on/off, organization visibility default, time zone.
- `agents` (`/settings/agents`, read-only): one card per OS agent the mirror knows with a capability: name, the queues it is in, the last
  dispatch (kind, via, when, status) from `mcp_agent_dispatches`, a link to the kernel's agent page (`OS_LAUNCHER_URL` is the person face; the
  text says "Hiring, grants and duties live in the OS"). Empty: *"No agent works here yet — the installer proposes three."*

## Files (exactly these)
- `html/settings/queues/index.php` · `form.php` · `view.php` · `save.php` · `archive.php` · `member.php` · `member-remove.php` · `presence.php`
- `html/organizations/index.php` · `form.php` · `view.php` · `save.php` · `archive.php`
- `html/requesters/index.php` · `form.php` · `view.php` · `save.php` · `email.php` · `email-remove.php` · `merge.php`
- `html/settings/sla/index.php` · `form.php` · `save.php` · `archive.php` · `hours.php` · `holiday.php` · `holiday-remove.php`
- `html/settings/general.php` · `html/settings/agents.php`
- `app/features/queues/queries.php` · `present.php` — `app/features/organizations/queries.php` · `present.php` — `app/features/requesters/queries.php` · `present.php` —
  `app/features/sla/queries.php` · `present.php` — `app/features/settings/queries.php` (extends Phase 2's) · `present.php`
- `app/views/queues/list.php` · `form.php` · `view.php` · `partials/queue-card.php` · `partials/member-row.php` — `app/views/organizations/list.php` · `form.php` · `view.php` · `partials/organization-card.php` —
  `app/views/requesters/list.php` · `form.php` · `view.php` · `partials/requester-card.php` · `merge.php` — `app/views/sla/list.php` · `form.php` · `hours.php` · `partials/policy-card.php` · `partials/targets-grid.php` —
  `app/views/settings/general.php` · `agents.php`

## Query functions (signatures fixed)
- `find_queues(PDO, array $filters = []): array` (mcp_queues; `mine`, `q`) · `find_queue(PDO, int $id): ?array` · `queue_members(PDO, int $queueId): array`
  (mcp_queue_members, named and department) · `save_queue(PDO, ?int $id, array $fields, int $by): int` · `archive_queue(PDO, int $id, int $by): array` (returns moved ticket ids) ·
  `add_queue_member(PDO, int $queueId, int $memberId, bool $lead, int $by): void` · `remove_queue_member(PDO, int $queueId, int $memberId): bool` · `set_presence(PDO, int $memberId, string $availability, ?int $capacity): void`
- `find_organizations(PDO, array $filters = []): array` · `find_organization(PDO, int $id): ?array` · `save_organization(PDO, ?int $id, array $fields, array $domains, int $by): int` · `archive_organization(PDO, int $id): bool`
- `find_requesters(PDO, array $filters = []): array` (mcp_requesters) · `find_requester(PDO, int $id): ?array` · `create_requester(PDO, string $name, string $email, array $fields): int` (`hd_requester_for_email()`
  then the fields; refuses an address that resolves to an existing row) · `update_requester(PDO, int $id, array $fields): void` (a member's name/email untouched) · `add_requester_email(PDO, int $id, string $email): void` ·
  `remove_requester_email(PDO, int $id, string $email): bool` (never the last) · `merge_requesters(PDO, int $from, int $into, int $by): array` (tickets, followers, ratings, emails re-pointed; `merged_into_id` set; counts)
- `find_sla_policies(PDO): array` · `find_sla_policy(PDO, int $id): ?array` · `save_sla_policy(PDO, ?int $id, array $fields, array $conditions, array $targets): int` · `archive_sla_policy(PDO, int $id): bool` ·
  `find_schedules(PDO): array` · `find_schedule(PDO, int $id): ?array` · `save_schedule(PDO, ?int $id, array $fields, array $intervals): int` · `add_holiday(PDO, int $scheduleId, string $date, ?string $name): void` · `remove_holiday(PDO, int $scheduleId, string $date): bool` ·
  `business_preview(PDO, int $scheduleId, string $from, int $minutes): ?string`
- `find_settings(PDO): array` · `save_settings(PDO, array $fields): array` (returns changed keys) · `find_agents_here(PDO): array`

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity` with `queue_id` where a queue is the subject; `emit_action_status`; a create's `location` ends in the id)
- `queues/save.php` (`queue_create` / `queue_update` by the presence of `queue`): `require_right('settings.manage')`; validation 422 with field errors (a key in use, a default
  assignee missing for that method, an archived department); log `queue.create` / `queue.update` (`before`/`after` of changed fields). `archive.php` (`queue_archive`): the default-queue refusal; log `queue.archive` (`after.moved_tickets`).
- `queues/member.php` (`queue_member_add`) / `member-remove.php`: `require_right('queues.members')` or the queue's lead (`hd_is_queue_lead()`); a member must be active with a capability; log `queue.member_add` / `queue.member_remove` (`after.member_id`, `after.is_lead`).
- `queues/presence.php` (`queue_presence_set`): `require_login()`; own row; log `queue.presence`.
- `organizations/save.php` / `archive.php`: `require_right('tickets.view_queue')` for save, `tickets.manage` for archive; domains validated (`^[a-z0-9.-]+\.[a-z]{2,}$`, unique across organizations: 422 *"example.com already belongs to <name>."*); log `organization.create|update|archive`.
- `requesters/save.php` · `email.php` · `email-remove.php`: `require_right('tickets.view_queue')`; log `requester.create|update|email_add|email_remove` (never the notes' text). `merge.php` (`requester_merge`): `require_right('tickets.manage')`; refuses merging a member into an external
  (*"Merge the external into the member, not the other way."*) and a row into itself; log `requester.merge` (`after.into_requester_id`, `after.tickets`, `after.emails`).
- `sla/save.php` · `archive.php` · `hours.php` · `holiday.php` · `holiday-remove.php`: `require_right('settings.manage')`; targets must be positive integers or blank; at least one weekday open for a schedule marked default; log
  `sla_policy.create|update|archive`, `business_hours.save`, `business_hours.holiday_add|holiday_remove` (`before`/`after` of the changed fields).
- `settings/general.php` (POST = `settings_save`): `require_right('settings.manage')`; `before`/`after` of changed keys only; "Nothing changed" logs nothing.

## Action manifest entries
- Screens: the eighteen above. Actions: `queue_create`, `queue_update`, `queue_archive`, `queue_member_add`, `queue_member_remove`, `queue_presence_set`, `organization_create`, `organization_update`,
  `organization_archive`, `requester_create`, `requester_update`, `requester_email_add`, `requester_email_remove`, `requester_merge`, `sla_policy_create`, `sla_policy_update`, `sla_policy_archive`,
  `business_hours_save`, `holiday_add`, `holiday_remove`, `settings_save`. Agent approvals (`other`): the queue, policy, business-hours and settings saves/archives, `requester_merge`.

## Activity log events
`queue.create`, `queue.update`, `queue.archive`, `queue.member_add`, `queue.member_remove`, `queue.presence`, `organization.create`, `organization.update`, `organization.archive`, `requester.create`,
`requester.update`, `requester.email_add`, `requester.email_remove`, `requester.merge`, `sla_policy.create`, `sla_policy.update`, `sla_policy.archive`, `business_hours.save`,
`business_hours.holiday_add`, `business_hours.holiday_remove`, `settings.save`, `screen.view` (each screen). Configuration rows carry `before`/`after` of the changed fields only (`config_change_log` reads them).

## Out of scope for this slice
Tickets and anything on a requester's or organization's page that lists them (3); mailboxes (7); the kernel-side grants of roles (the kernel's); hiring an agent (the kernel's).

## Proof (`tests/phase3/slice1/run.sh`, scratch database, `php -S` + headless Chromium at 375 × 740 and 1280 × 800)
**Built and proven 2026-10-03: 211 checks green** (rights 12, queues 34, organizations 21, requesters 31, sla 30, settings 17, json 15, browser 50, the registry check); the Phase 2
suite still green after it (250); the installer's `plan` 60 steps, no stop.
- [x] Rights: a requester-only member → 403 on every screen here with the right's sentence (*"You may not see this queue's tickets."* for requesters, *"…change Help Desk's settings."* for settings);
  support and a collaborator see organizations and requesters with emails and notes (the view's rule, `tickets.view_queue`); a lead reaches the queues list but not the add form; SLA is the admin's.
- [x] Queues: create with a department → the department's members appear "Through IT" and `hd_held_queue_ids()` lists the queue for them; `include_department` off removes them; a named member stays;
  the queue's lead (no `queues.members`) may add and remove; archive the default → refused; archive another with two open tickets → both in General, two `ticket.assign` rows (`via queue_archive`).
- [x] A default assignee that is an **agent** saves and shows with its kind chip ("Goes to SMOKE Frontline"); method Default assignee without one → 422 with the field error; the key is derived and immutable.
- [x] Organizations: a duplicate domain → 422 naming the owner; an organization's domain claims a new external at `hd_requester_for_email()` and the card's counts follow; the domain set is replaced whole;
  archive refused while open tickets exist (*"Solve or move its 1 open ticket first."*).
- [x] Requesters: adding a known address → 422 *"That address belongs to <name> — open them instead."* (with `record_id` for the link); a member's row refuses a name change and has no address form
  (*"Managed by the OS."*); never the last address, the primary moves; merge moves tickets, a cc and a rating, re-points the addresses and dates `merged_into_id`; a member into an external → refused.
- [x] SLA: the targets grid round-trips to `targets` jsonb (blanks dropped) and an agent's JSON lands the same way; the matches to `conditions`; the preview line equals `hd_business_add_minutes()`
  and skips a holiday; a schedule with no open day marked default → 422; un-defaulting the only default → 422; Standard cannot be archived; Move up / Move down swap `sort_order`.
- [x] Settings: `settings_save` logs before/after of the changed keys only; "Nothing changed" logs nothing; the prefix numbers new tickets; the agents screen lists the Frontline agent with its queues;
  My settings carries the presence card for `tickets.work` and the admin's link to the business's settings.
- [x] JSON mode: every handler answers `{ok, did, record_id, location}` to an action token and 422 `{error: {code: invalid, errors[], fields{}}}` on a bad form; a create's `record_id` parses from
  `location`; the kernel's `_partial=1` fills the base row; a run token's rows are source `agent` with the run's request id and the rights hold.
- [x] `php bin/build_action_registry.php --check` passes and the 21 actions read as built (25 with Phase 2's); every screen at 375 and 1280 with `scrollWidth` = viewport; every button 44 px; the flows
  through the real screens; a refused save lands in `#flash` and keeps the form; JavaScript off the forms post and land.

**Decisions taken while building (2026-10-03):** the shared handler kit is `app/handler.php` (required by the bootstrap: `hd_handler_begin()`, `req_val()`, `request_list()`, `hd_yes()`, `hd_int()`,
`hd_ref()`, `hd_guard()`, `hd_refuse_fields()`, `hd_done()`, `hd_diff()`, `require_any_right()`, `find_assignable_members()`, `find_live_departments()`) — every CRUD slice copies its shape; a handler keeps
what it is not sent from the current row (and the kernel's `_partial` fills the base row too); a refusal that points at a record carries `record_id` in its action status (the known address → its owner);
the merge page is `GET /requesters/merge.php?requester=&q=&into=` (search, then confirm) and its POST the action; the policy cards' Move up / Move down are `sla_policy_update` with `move=up|down`;
`business_hours_save` takes the grid (`intervals[weekday][i][opens|closes]`) or JSON `[{weekday, opens, closes}]`, the targets grid `targets[priority][metric]` or JSON; the preview is
`GET /settings/sla/hours?preview=1&schedule=&from=&minutes=`; a token's scope may be `api` as the manifest says; the queues list is `settings.manage|queues.members` (a queue's lead by flag alone opens the
queue through its page, not the list); `/settings/` gained the presence card and the admin's link to `/settings/general`; the "Organizations and requesters" screens are support's (`tickets.view_queue`),
archive and merge the lead's.

## Open questions
- `queue_presence_set` lives under `/settings/queues/presence.php` in the manifest though it is the person's own setting; the spec keeps the path and links it from `/settings/`.
