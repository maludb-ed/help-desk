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
- [x] `php bin/build_action_registry.php` reports **65 screens built, 96 actions built**; `--check` OK; no unresolved endpoint (the builder learned the three slug rewrites, 2026-10-04).
- [x] The three rewrites added in Phase 3 (slice 8 `runs`, slice 9 the two `/kb/` ones, slice 10 `/reports/{report}`) are in `deploy/apache-helpdesk.conf` **both vhosts** (the internal vhost gained them, and slice 3's `merge`, 2026-10-04) and `tests/dev_router.php`, and rendered by the installer without a placeholder left (the json proof counts them).
- [x] `php /var/www/bin/app_install.php plan /srv/apps/helpdesk --domain subello.com` — 60 steps, 5 notes, no stop (2026-10-04).
- [x] Every earlier slice's proof re-run green, one at a time, on its own scratch database (2026-10-04; the application is not installed here yet — no live health to read).
- [x] `app/features/shell/nav.php` has no placeholder item (no `render_nav_stub()` call is left under `html/` — Phase 2's gates proof and the json proof both say so); every menu URL answers 200 or 403 by right (the gates matrix).
- [x] The record written: `docs/helpdesk-design.md` §15 (Phase 3 complete, the decisions taken per slice), `CLAUDE.md` State, `deploy/ROOT_STEPS.sh` with every owner step so far (step 4, Phase 3).

## Proof (`tests/phase3/slice10/run.sh`: the scratch database, slice 3's world plus a DATED fixture — thirteen tickets created, answered, solved, closed, reopened, rated and timed across 60 days in IT help and Facilities, an organization named `-SMOKE Dash Co`, an empty queue — curl as members and under a person token; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-04: 101 checks green** (numbers 23, reach 20, period 18, json 12, browser 27, the registry); Phase 2's and slices 1–9's suites still green (Phase 2's
gates matrix admits a queue's holders to `/reports/`; its placeholder check became "no placeholder is left"); the installer's `plan` with the domain: 60 steps, no stop.
- [x] **The numbers reconcile.** Every report's totals equal direct SQL over the fixture, computed both ways: volume (created, solved, closed, reopened, open at end, the channels; IT's
  own row; a zero row for the empty queue; one row per live queue), first response (tickets, responded, with a target, within target; IT's median minutes), resolution (solved; IT's median
  hours), SLA attainment (due, met, breached; rows per queue × metric), reopen rate (solved, reopened; IT's median days to reopen = 2.0, the fixture's), CSAT (sent, answered, good, bad;
  the bad reasons counted; the mean — on the good/bad scale), workload (Marco's open now, solved, minutes logged; the agent as an Agent), aging (the open total, the 4–7 d bucket, five
  buckets for every queue). The dashboard's counts (open, unassigned, stale, new, needs reply) equal direct SQL; at risk and breached equal the view as Lena; Marco shows 3 open over a
  capacity of 2, online; the agent has kind agent, no capacity and no availability; Lena is away; Colin is listed from Facilities.
- [x] **Reach.** Lena (`reports.view`) is offered every live queue; Marco (IT holder, no `reports.view`) opens the dashboard with IT alone in the selector, `?queue=` another says *"Nothing
  to report for that queue."* with no numbers, his volume report lists IT only and totals IT's own count; Priya → 403 *"You may not see reports."* on the dashboard, a report and its CSV;
  Colin (collaborator) sees Facilities only; the agent under a person token: IT's numbers when it holds the queue, 403 when it does not.
- [x] **The links and the words.** Each of the seven dashboard links (open, needs reply, unassigned, at risk, breached, new, stale) lists in the inbox exactly the count on the card; with a
  queue chosen the links carry it; the workload row over capacity is in danger; the agent with its kind icon and no capacity; the dashboard refreshes every 60 s; the empty queue says
  *"Nothing open in SMOKE Empty."* with nobody in the workload; the pages leak no address and no token.
- [x] **The CSV and the period.** The CSV downloads with its name, CRLF, a header equal to the columns, every row (not a page), cell for cell the table's strings; `-SMOKE Dash Co` is
  prefixed with an apostrophe in the CSV and shown unprefixed on the page; a cell that is a number (+1, −5) is never prefixed; `report.export` logged with report, period, grouping and
  the row count; RFC 4180 quoting. from > to → 422 *"The period's start is after its end."*; 367 days → 422 *"A period is at most 366 days."*; 366 fine; a malformed date → 422; the default
  is the last 30 days ending today in the business zone; `group_by=week` buckets on Mondays; `group_by=day` one row per day; an unknown grouping → 422 by name; an unknown report → 404
  *"No such report."*; workload ignores a grouping; aging is about now whatever the period says.
- [x] **JSON and the screens.** The dashboard answers counts, by_priority (urgent first), aging (five buckets), workload, links, queues, the chosen queue by name; a report answers report,
  title, columns, rows, totals, row_count, csv, and the page shows the same cells with the totals row and Download CSV; a look is `screen.view` with `after.report` (the dashboard with
  the queue); paging. Phase 3 closes: 65 screens and 96 actions built, no placeholder left, the three rewrites in both vhosts of the deploy template.
- [x] **375 × 740 and 1280 × 800.** Six count cards and the three tables; on a phone the cards stack two a row, the tables stack and the workload table scrolls inside its card; on a desktop
  the three tables sit side by side; clicking open, unassigned and needs reply lists exactly that count in the inbox (cards on a phone, rows on a desktop); the report page's wide table
  scrolls inside; changing the grouping re-renders the table over HTMX and pushes the URL; no sideways scroll; no console errors.

**Decisions taken while building (2026-10-04):** a queue's holder (`tickets.view_queue`) reads the dashboard and the reports for their queues without `reports.view` — the menu item
stays for `reports.view` (the open question, answered yes); the query functions return typed numbers and `report_cells()` makes the strings the page, the JSON and the CSV share;
`first_reopened_at` (db/016) gives "median days to reopen" from creation to the first reopening (the solve before it is not held); `workload` has no "assigned in period" column (the
log is not read per row); a CSV cell that is a number is never prefixed; a report's three base-table reads are the business zone, the CSAT scale and `queue_presence` for the
dashboard's capacity; the dashboard's refresh container carries `hx-disinherit="*"` so its links and forms do not inherit its `hx-select` (the lesson of the board, again); the
registry builder knows the three slug rewrites; the internal vhost of the deploy template gained the same special rewrites as the public one.

## Open questions
- **A chart library**: Projects vendored one for its reports (the owner allowed JavaScript packages where a screen earns them). Proposed for v1: tables and count cards only; a sparkline per report later if asked.
- `first_reopened_at` on tickets (additive) so `reopen_rate` can give "median days to reopen" without reading the log per row; and an "assigned in period" count for `workload` the same way — or the two columns stay *n/a*.
- Should a queue's lead (`queue_members.is_lead`, without `reports.view`) see the dashboard for their queue? Proposed: yes — the right `tickets.view_queue` already lets them read every ticket of it; the dashboard is the same facts counted.
