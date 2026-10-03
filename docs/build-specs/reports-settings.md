# Build spec: reports and settings — slice 10 (Phase 3 complete)

Replicates slice 3's files, gates, flow and proof style. What exists at the end: the lead's **queue dashboard** (the queues
now) and eight **reports**, each a table and a CSV, over a period and a grouping — the numbers the `metrics_summary`,
`csat_summary`, `ticket_counts`, `backlog_aging` and `agent_workload` tools answer in Phase 4 from the **same query
functions**. This slice closes Phase 3: at its end every screen and action of the manifest is built.
Schema: the views `mcp_tickets`, `mcp_sla_targets`, `mcp_satisfaction_ratings`, `mcp_queues`, `mcp_queue_members`,
`mcp_members`, `mcp_time_entries` (db/015). Never modify them; a report reads views only, so a holder's reach is the view's.
**Settings are elsewhere**: `general-settings` / `settings_save` and `agents` are slice 1's screens; `settings` (prefs) and
`tokens` are Phase 2's; each settings area is its own slice's (queues 1, vocabulary 2, SLA 1, automation 8, mailboxes 7, CSAT 6).
The manifest has no settings hub screen: the sidebar's **Settings** group is the hub (`app/features/shell/nav.php`), and
the menu item `my-settings` is the screen `settings`.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `queue-dashboard` | `/reports/` | The queues now: backlog by status and priority, aging, at risk, unassigned, workload (`?queue=`) |
| `reports` | `/reports/{report}` | One report as a table and a CSV (`?queue=`, `?from=`, `?to=`, `?group_by=`, `?format=csv`) |

- **queue-dashboard** (`reports.view`; a queue's holder with `tickets.view_queue` sees **their queues only** — the views already narrow it; a requester-only member → 403 *"You may not see reports."*): a queue selector (All · each held); then cards (`RecordCard`) in two rows — **Open** (count, by status category as chips), **Needs reply**, **Unassigned**, **At risk / Breached** (two numbers, danger when breached > 0), **New in triage**, **Stale (7 d)**; then three small tables: **By priority** (urgent → low: open, at risk), **Aging** (today · 1–3 d · 4–7 d · 8–30 d · older, per priority), **Workload** (`workload-row-{member}`: person or agent with its kind icon, open, needs reply, at risk, capacity, availability — over capacity in danger). Every number links to the inbox with the matching filter (`/inbox?queue=&status=&sla=…`, the click-around rule). Refreshes every 60 s (`hx-trigger="every 60s"`) while visible. Empty queue: *"Nothing open in Facilities."*
- **reports** (`/reports/{report}` → `report.php?report=` — **one rewrite added to `deploy/apache-helpdesk.conf` and `tests/dev_router.php`**, the canonical rules cover numeric ids only): a header with the period (default: the last 30 days; at most 366), `group_by` (queue · category · organization · assignee · day · week), the queue filter, **Download CSV**; a table, 50 rows a page (`?page=`); a footer line with the totals. The eight reports and their columns:

| `report` | Rows | Columns | Right |
|---|---|---|---|
| `volume` | per group | created, solved, closed, reopened, open at end, by channel (web · email · form · agent · api) | `reports.view` or the queue's holder |
| `first_response` | per group | tickets with a first response, median minutes, p90, within target, % within | same |
| `resolution` | per group | solved, median hours, p90, within target, % within | same |
| `sla_attainment` | per group × metric | targets due, met before due, breached, % attained, median minutes over on breaches | same |
| `reopen_rate` | per group | solved, reopened once or more, % reopened, median days to reopen | same |
| `csat` | per group | surveys sent, answered, % answered, good, bad, % good (or the mean on a five-point scale), the bad reasons (a count each) | same |
| `workload` | per assignee (person or agent) | open now, assigned in period, solved in period, median resolution hours, minutes logged, needs reply now | `reports.view`; a queue lead for their queue |
| `aging` | per group × bucket | open tickets by age bucket and priority | same |

- The **CSV** is the whole table (every row, not a page), UTF-8, CRLF, RFC 4180 quoting, a leading apostrophe on any cell beginning with `=`, `+`, `-`, `@`; file name `helpdesk-{report}-{from}-{to}.csv`; logged `report.export` (`after`: report, queue_id, from, to, group_by, rows). A look is `screen.view` with `after.report`.
- **A report is display text**: columns and rows are strings, so the page, the JSON and the CSV are the same cells; dates are the business's time zone (`app_settings.timezone`); a group with nothing in the period still shows a zero row when it exists (a queue, a status), not when it is a free value (an organization).

## The queries (`app/features/reports/queries.php` — shared with Phase 4's tools; each over `mcp_*` views only)
- Period: `[from 00:00, to 24:00)` in the business zone; `p` = `(from, to)`; a grouping is an allow-listed column expression (`queue_id`/`queue_name`, `category_id`/`category_name`, `organization_id`/`organization_name`, `assignee_member_id`/`assignee_name`, `date_trunc('day'|'week', created_at)`), never a request string.
- `report_volume(PDO, p, group, ?queue): array` — `count(*) filter (where created_at in p)`, `solved_at in p`, `closed_at in p`, `reopen_count > 0 and solved_at in p`, open at end = created before `to` and not solved before `to` (or reopened); channel counts with `filter`.
- `report_first_response(PDO, p, group, ?queue)` — over `mcp_sla_targets` metric `first_response` joined to `mcp_tickets` created in p: `percentile_cont(0.5) within group (order by extract(epoch from met_at - started_at - paused_total)/60)`, `percentile_cont(0.9)`, `count(*) filter (where met_at <= due_at)`; tickets with no target counted in the denominator of "with a first response" from `first_response_at`.
- `report_resolution(PDO, p, group, ?queue)` — the same over metric `resolution` for tickets solved in p, hours.
- `report_sla_attainment(PDO, p, group, ?queue)` — per metric: targets with a `due_at` whose ticket was created in p: due, `met_at <= due_at`, `breached_at is not null`, attainment = met-before-due / all with a due; median minutes over = `percentile_cont(0.5)` of `(coalesce(met_at, now()) - due_at)` on breaches.
- `report_reopen_rate(PDO, p, group, ?queue)` — solved in p; reopened = `reopen_count > 0`; days to reopen from the activity log is **not** read here (a view join would be per row): the median is from `mcp_tickets` only when a later migration adds `first_reopened_at`; until then the column reads *n/a* and the open question says so.
- `report_csat(PDO, p, group, ?queue)` — `mcp_satisfaction_ratings` sent in p: answered = `responded_at is not null`; good = `score >= 4` on five-point, `score = 5` on good_bad (the scale from `csat_settings` through the view? — `csat_settings` has no view: the scale is read by PHP as the writer, the one base-table read of this slice, and the tool answers it the same way); bad reasons grouped.
- `report_workload(PDO, p, ?queue)` — per `assignee_member_id`: open now (`status_category in open set`), assigned in p (`ticket.assign` rows are the log's — **not read**: "assigned in period" = tickets created or reassigned in p is approximated by `updated_at` only when the assignee changed, which the view cannot tell; the column is **solved in period** and **open now** in v1, "assigned in period" dropped — see open questions), solved in p, median resolution hours, minutes logged (`mcp_time_entries` in p), needs reply now, capacity and availability from `mcp_queue_members`/`queue_presence` (the view `mcp_queues` has none per member — read as the writer, `queue_presence`, for the dashboard only).
- `report_aging(PDO, group, ?queue)` — open tickets now, `case` buckets on `now() - created_at`, by priority.
- `dashboard_counts(PDO, ?queue): array` (open by category, needs reply, unassigned, at risk, breached, new, stale 7 d) · `dashboard_by_priority(PDO, ?queue)` · `dashboard_workload(PDO, ?queue)` · `report_period(string $from, string $to): array` (validated, ≤ 366 days, defaults) · `report_columns(string $report): array` · `stream_csv(string $name, array $columns, array $rows): never` (in `csv.php`).
- Phase 4 maps: `metrics_summary` → `report_volume` + `report_first_response` + `report_resolution` + `report_sla_attainment` + `report_reopen_rate` + `report_csat` for `period` and the period before; `csat_summary` → `report_csat` + the ratings list; `ticket_counts` → `report_volume`'s grouped count with `group_by`; `backlog_aging` → `report_aging`; `agent_workload` → `report_workload` + `dashboard_workload`.

## Files (exactly these)
- `html/reports/index.php` · `report.php` — `app/features/reports/queries.php` · `present.php` · `csv.php` — `app/views/reports/dashboard.php` · `report.php` · `partials/count-card.php` · `partials/table.php`
- One rewrite in `deploy/apache-helpdesk.conf` (both vhosts) and `tests/dev_router.php`: `RewriteRule ^/reports/([a-z_]+)$ /reports/report.php?report=$1 [L,QSA]`.

## Handlers
- None that write. `report.php` GET with `format=csv`: the report's right, `stream_csv()`, log `report.export`. Both screens log `screen.view` (`after.report`, `after.queue_id`). A report name not in the allow-list → 404 *"No such report."*; a `group_by` not allowed → 422; `from` > `to` or > 366 days → 422 with the sentence.

## Action manifest entries
- Screens: `queue-dashboard`, `reports`. Actions: none (a CSV is a GET; "What is not an action"). Tools (Phase 4): `metrics_summary`, `csat_summary`, `ticket_counts`, `backlog_aging`, `agent_workload`, `stale_tickets` share these functions.

## Activity log events
- `screen.view` (`queue-dashboard`, `reports` with `after.report`), `report.export` (`after`: report, queue_id, from, to, group_by, rows). No row carries a requester's address or a body.

## Out of scope for this slice
- A chart library (see open questions); scheduled or mailed reports; a per-requester or per-organization scorecard page; exports other than CSV.

## Phase 3 closing checklist (this slice ends with it)
- [ ] `php bin/build_action_registry.php` reports **65 screens built, 96 actions built**; `--check` OK; no unresolved endpoint.
- [ ] The three rewrites added in Phase 3 (slice 8 `runs`, slice 9 the two `/kb/` ones, slice 10 `/reports/{report}`) are in `deploy/apache-helpdesk.conf` **both vhosts** and `tests/dev_router.php`, and rendered by the installer without a placeholder left.
- [ ] `php /var/www/bin/app_install.php plan /srv/apps/helpdesk --domain subello.com` — no stop; only owner/apply todos.
- [ ] Every earlier slice's proof re-run green, one at a time, on its own scratch database; the live application (if installed) answers health `database: ok` after each.
- [ ] `app/features/shell/nav.php` has no placeholder item; every menu URL answers 200 or 403 by right.
- [ ] The record written: `docs/helpdesk-design.md` §15 (Phase 3 complete, the decisions taken per slice), `CLAUDE.md` State, `deploy/ROOT_STEPS.sh` with every owner step so far.

## Proof (scratch database with a dated fixture — tickets created, solved, reopened, rated across 60 days — curl as members; headless Chromium at 375 × 740 and 1280 × 800)
- **Numbers reconcile**: each report's totals equal direct SQL over the fixture (the proof computes both); the CSV equals the table cell for cell and has every row when the table shows 50; formula-looking cells are prefixed; `report.export` logged with the row count.
- **Period and grouping**: `from` > `to` → 422; 367 days → 422; `group_by=week` buckets on the business zone's Monday; an unknown report → 404 *"No such report."*
- **Reach**: Lena (`reports.view`) sees every queue; Marco (IT holder, no `reports.view`) opens the dashboard and the reports **for IT only** (the queue selector offers IT alone; `?queue=` another → the views answer nothing, the page says *"Nothing to report for that queue."*); Priya → 403 *"You may not see reports."*; an agent granted `support` reads its queue's numbers through the same functions (the Phase 4 tools' proof reuses this fixture).
- **Dashboard links**: every count links to `/inbox` with a filter that lists exactly that count (clicked in Chromium, the inbox's row count equals the card).
- **Workload**: an agent assignee appears with its kind icon and no capacity when it has no presence row; a person over capacity is in danger.
- **375 px**: cards stack; each table scrolls inside its card; the page never sideways; 1280 px: the three tables side by side.

## Open questions
- **A chart library**: Projects vendored one for its reports (the owner allowed JavaScript packages where a screen earns them). Proposed for v1: tables and count cards only; a sparkline per report later if asked.
- `first_reopened_at` on tickets (additive) so `reopen_rate` can give "median days to reopen" without reading the log per row; and an "assigned in period" count for `workload` the same way — or the two columns stay *n/a*.
- Should a queue's lead (`queue_members.is_lead`, without `reports.view`) see the dashboard for their queue? Proposed: yes — the right `tickets.view_queue` already lets them read every ticket of it; the dashboard is the same facts counted.
