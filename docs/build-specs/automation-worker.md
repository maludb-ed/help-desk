# Build spec: automation and the worker — slice 8

Replicates slice 3's files, gates, flow and proof style. What exists at the end: **one timer** (`helpdesk-worker.timer`,
every minute) runs `bin/worker.php` — one pass of nine steps, each its own try, advisory-locked, logged once per pass with
counts and never a body; the **rules engine** (`app/features/automation/engine.php`) that triggers, time-based rules and
assignment rules share; **assignment methods** on create and on a queue change (manual, round-robin, least-loaded, the
queue's default assignee — a person or an agent); the **dispatch** to an agent assignee (design §5); the SLA clock's
warnings and breaches; the snooze's wake-up; auto-close; the CSAT survey falling due; and the admin's rules screens. The
two seeded rules (db/011) run from the first pass.
Schema: `automation_rules`, `rule_runs` (db/011); `sla_targets`, `tickets`, `ticket_viewers`, `ticket_links` (db/010);
`queues`, `queue_members`, `queue_presence` (db/008); `satisfaction_ratings`, `csat_settings` (db/012); `notification_outbox`,
`agent_dispatches` (db/013); `app_settings` (db/005); the views `mcp_automation_rules`, `mcp_rule_runs`, `mcp_tickets`,
`mcp_sla_targets`. Never modify them. The worker reads base tables as the writer with **no acting member** (`source = cron`).

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `rules-list` | `/settings/automation/` | The rules as cards: kind, event, run count, last run, on or off |
| `rule-add` | `/settings/automation/new` | Add a trigger, a time-based or an assignment rule (`?kind=`) |
| `rule-edit` | `/settings/automation/{id}/edit` | Change a rule's conditions or actions |
| `rule-runs` | `/settings/automation/{id}/runs` | What a rule did: the last 30 days of runs |

- **rules-list** (`settings.manage`; a `tickets.view_queue` holder may read): cards grouped *Triggers · Time-based · Assignment*, each `rule-card-{id}`: name, the event or "every minute" or "on create and queue change", **Runs: 412 · last 3 min ago**, a switch (`rule_toggle`, inline, Pattern C), **Edit**, **Run now** (time-based only, confirm), **Runs**. Empty group: *"No triggers yet."* The two seeded rules show with a *Built in* chip (editable, deletable like any — the owner may turn auto-close off).
- **rule-add / rule-edit**: one full-page form. Kind (a segmented control; fixed once saved), name, event (triggers), sort order, active. **Conditions** are rows — *field · op · value* — built from the bounded vocabulary below (selects; a value box typed per field; `setting:` values offered for days/hours fields); **Actions** are rows — *do · with* — from the action vocabulary. Both are stored as the JSON the manifest names; a **JSON** tab shows and accepts the raw document for the owner who prefers it. A rule that names a status, queue, tag, member or field that does not exist is refused with the name: *"No status 'Parked'."* A live **"Would match N open tickets"** line (GET `?preview=1`, nothing saved).
- **rule-runs**: a table (ledger): when, ticket (a link), outcome (`applied` success · `skipped` muted · `error` danger), detail (the actions taken, the condition that failed, the error's words). 50 a page, 30 days.

## The vocabulary (fixed in `engine.php`; the form, the handler and `rule_evaluate()` read one table)
- **Condition fields**: `status_category`, `status` (key), `priority`, `queue_id`, `category_id`, `type_id`, `organization_id`, `channel`, `tags` (any of), `assignee` (`none`, `agent`, `human`, an id), `needs_reply`, `sla_state`, `hours_since` / `days_since` with `args` ∈ `created_at`, `solved_at`, `last_activity_at`, `last_requester_reply_at`, `last_agent_reply_at`, `pending_until_passed`, `requester_kind`, `subject_contains`, `body_contains` (the first message).
- **Ops**: `eq`, `neq`, `in`, `not_in`, `gte`, `lte`, `contains`, `is_empty`. A value may be `"setting:auto_close_days"` / `"setting:reopen_window_days"` (read live from `app_settings`). `conditions` = `{"all": [...], "any": [...]}`; both lists optional; an empty document matches every candidate.
- **Actions** (applied in order; each writes its own activity row through slice 3's write functions, actor = the rule, `after.via = 'rule'`, `after.rule_id`): `{"set": {priority|type_id|category_id|due_at}}` · `{"assign": {"queue_id": n}}` · `{"assign": {"assignee_member_id": n | "round_robin" | "least_loaded" | "default" | "none"}}` · `{"add_tags": [..]}` · `{"remove_tags": [..]}` · `{"transition": "<status key>", "resolution_code"?, "hold_reason"?, "pending_until"?}` (refused by `hd_transition()` like any move; the run is `skipped` with the sentence) · `{"notify": {"who": "assignee"|"requester"|"queue_leads"|"member:<id>", "kind": "<notifications kind>", "text"?}}` · `{"note": "text with {{ticket.number}}"}` · `{"dispatch": "assignee"}` · `{"link_children": "solve"}` (a `problem` ticket solved → each `parent_child` child moved to solved with `resolution_code = resolved`, a note naming the parent; **problem-solves-incidents**).
- **Candidates** of a time-based rule: open-category tickets (`new`, `open`, `pending`, `on_hold`) plus `solved` ones (auto-close needs them); never `closed`. A rule touches a ticket **at most once per pass** and never re-applies when nothing would change (the `set` of an equal value is `skipped`, not `applied`).

## The worker (`bin/worker.php`, `php bin/worker.php [--only=a,b] [--limit=200]`, `source = cron`)
Advisory lock `hashtext('helpdesk_worker')`; `$step()` as txtSchedules' notifications worker (each step a try; `error_log` + `'error'` in the report, the pass goes on); the pass's report printed as one JSON line and logged **once** as `worker.pass` with the counts (never a body). Steps, in order:
(a) **send** — `outbox_send_batch(PDO, int $limit)` (slice 7). (b) **mail** — `mail_poll_due_mailboxes(PDO)` (slice 7). (c) **sla** — `sla_breach_pass(PDO)`: targets with `met_at IS NULL AND breached_at IS NULL AND paused_at IS NULL AND due_at < now()` → `breached_at = due_at`, log `ticket.sla_breach` (`after.metric`, `after.due_at`, `after.target_id`), notify the assignee and the queue's leads (`sla_breached`); then `sla_warning_pass(PDO)`: open, running targets past the policy's `warning_percent` of their span **not yet warned** → log `ticket.sla_warning` (`after.metric`, `after.due_at`, `after.target_id`), notify (`sla_warning`). *The schema has no `warned_at`: "not yet warned" is answered from the activity log — no `ticket.sla_warning` row whose `after.target_id` is this target.* Proposed, if the owner prefers a column to a log lookup: additive **db/016 `ALTER TABLE sla_targets ADD COLUMN warned_at timestamptz`** (Phase 3, with sign-off). (d) **snooze** — `pending_until <= now()` in the pending category → `transition_ticket()` to `open`, `after.via = 'auto'`, notify the assignee (`pending_elapsed`). (e) **auto_close** — solved for `app_settings.auto_close_days` days (0 = never) → `closed`, `via auto` (the trigger in db/010 dates the secure link). (f) **rules** — every active `time_based` rule in `sort_order` against its candidates; `rule_runs` rows; `run_count`, `last_run_at`. (g) **survey** — `csat_settings.enabled`, a solved ticket past `send_delay_minutes`, in a surveyed queue (or all), with a requester who has an address, no `satisfaction_ratings` row yet → a row with a one-time token (hash kept; raw mailed), `expires_at = now() + response_window_days`, the survey mail queued (slice 7's `notify_requester()`), log `survey.send` (`after.requester_id`, `after.via = 'auto'`). (h) **dispatch** — `agent_dispatches` with `status = 'failed'` from the last hour → one more attempt per pass through slice 3's `dispatch_ticket()`; *there is no attempts column: a ticket+agent pair with three `failed` rows in the hour is left alone and logged once `ticket.dispatch` `after.status = 'given_up'`.* (i) **housekeeping** — `DELETE FROM ticket_viewers WHERE last_seen_at < now() - interval '10 minutes'`; `DELETE FROM sso_nonces WHERE expires_at < now() - interval '1 hour'`; dead secure links are kept (history).
**The clock**: in `APP_ENV=dev` only, `HD_WORKER_NOW` (ISO 8601) replaces `now()` in every step's SQL through `SET LOCAL hd.now` read by a tiny `hd_now()` SQL function? **No** — the schema is not modified: the worker passes `$now` explicitly to every query function (`:now`), defaulting to `now()`; the proof sets `HD_WORKER_NOW`. Production ignores the variable.

## Assignment (`app/features/automation/assign.php`) and dispatch
- `assign_on_arrival(PDO, int $ticketId, string $why): ?int` — called by slice 3's create and by `ticket_assign` with a new queue when no assignee was named: the queue's `assignment_method`: `manual` → none; `default_assignee` → the queue's default (a person or an agent); `round_robin` → the next active member of the queue (named + department) with presence not `offline` and capacity not reached, after the last one assigned (the last `ticket.assign` row with `via = 'round_robin'` in that queue); `least_loaded` → the member with the fewest open tickets under capacity, humans and agents alike. Writes `ticket.assign` with `after.via`.
- An assignee that is an agent → `dispatch_ticket(PDO, int $ticketId, int $agentId, string $kind, ?int $actingMember)` (slice 3): with an acting person, one turn through the kernel's chat endpoint (`agent_dispatches.via = 'chat'`, the run id and status recorded); with none (the worker, a rule, an email), `via = 'duty'` — a row that says the agent's duty will pick it up; K8 replaces this later. Never a model call here.
- **Triggers** (`kind = trigger`) run from `app/features/tickets/write.php`: `automation_run_triggers(PDO, string $event, int $ticketId)` is called **after the commit** of the primary write of `ticket.create`, `ticket.update`, `ticket.reply`, `ticket.note`, in its **own transaction per rule**, so a failing rule never fails the write and never rolls another rule back; a trigger's actions never fire triggers again (depth 1).

## Files (exactly these)
- `bin/worker.php` · `app/features/automation/engine.php` (the vocabulary, `rule_evaluate`, `rule_apply`, `rule_validate`) · `assign.php` · `triggers.php` · `worker_steps.php` (the nine steps) · `queries.php` · `present.php`
- `html/settings/automation/index.php` · `form.php` · `save.php` · `toggle.php` · `delete.php` · `run.php` · `runs.php` (`/settings/automation/{id}/runs` → `runs.php?id=`: **one rewrite added to `deploy/apache-helpdesk.conf` and `tests/dev_router.php`** — the canonical rules cover `/new`, `/{id}`, `/{id}/edit` only)
- `app/views/automation/list.php` · `form.php` · `runs.php` · `partials/rule-card.php` · `partials/condition-row.php` · `partials/action-row.php`

## Query functions (signatures fixed)
- `rule_vocabulary(): array` · `rule_validate(PDO, array $rule): array` (errors by field, names resolved) · `rule_evaluate(array $rule, array $ticket, array $ctx = []): bool` · `rule_apply(PDO, array $rule, array $ticket, ?int $actor = null, string $via = 'rule'): array` (returns `['outcome' => applied|skipped|error, 'actions' => [...], 'detail' => ...]`, writes `rule_runs` and `rule.fire`)
- `rule_candidates(PDO, array $rule, string $now, int $limit = 500): array` · `rule_preview_count(PDO, array $rule): int`
- `find_rules(PDO, ?string $kind = null): array` (`mcp_automation_rules`) · `find_rule(PDO, int $id): ?array` · `save_rule(PDO, ?int $id, array $f, int $by): int` · `toggle_rule(PDO, int $id, bool $active, int $by): void` · `delete_rule(PDO, int $id, int $by): void` · `find_rule_runs(PDO, int $ruleId, int $days = 30, int $page = 1): array`
- `assign_on_arrival(PDO, int $ticketId, string $why): ?int` · `pick_round_robin(PDO, int $queueId): ?int` · `pick_least_loaded(PDO, int $queueId): ?int`
- `automation_run_triggers(PDO, string $event, int $ticketId): array`
- `sla_breach_pass(PDO, string $now): int` · `sla_warning_pass(PDO, string $now): int` · `snooze_pass(PDO, string $now): int` · `auto_close_pass(PDO, string $now): int` · `rules_pass(PDO, string $now): array` · `survey_pass(PDO, string $now): int` · `dispatch_retry_pass(PDO, string $now): array` · `housekeeping_pass(PDO, string $now): array`

## Handlers (every one: `require_post(); verify_csrf(); require_right('settings.manage');` one transaction; `log_activity`; `emit_action_status`; `HX-Trigger: ruleChanged`)
- `save.php` (`rule_create` / `rule_update`): `rule_validate()`; a bad document → 422 with the field; log `rule.create` / `rule.update` (`before`/`after`: name, kind, event, conditions, actions, sort_order, active); `location` `/settings/automation/{id}/edit`.
- `toggle.php` (`rule_toggle`): log `rule.toggle` (`after.active`). `delete.php` (`rule_delete`, confirm): refused for a rule that ran in the last hour? **No** — deleted; its `rule_runs` go with it (cascade), its `rule.fire` rows stay. `run.php` (`rule_run_now`, confirm, time-based only): one `rules_pass` for that rule; answers the counts (`did`: *"Ran 'Close solved tickets after the window' on 12 tickets."*); log `rule.run_now` (`after.applied`, `after.skipped`).
- Agents: `rule_create`, `rule_update`, `rule_toggle`, `rule_run_now` pause (`other`); `rule_delete` pauses (`deletion`).

## Action manifest entries
- Screens: `rules-list`, `rule-add`, `rule-edit`, `rule-runs`. Actions: `rule_create`, `rule_update`, `rule_toggle`, `rule_delete`, `rule_run_now`. Tools (Phase 4): `find_rules`, `rule_runs` — read `mcp_automation_rules` / `mcp_rule_runs`.

## Activity log events
- `rule.create`, `rule.update`, `rule.toggle`, `rule.delete`, `rule.run_now`; `rule.fire` (`after.rule_id`, `after.outcome`, `after.actions[]`; `ticket_id`, `queue_id`); `ticket.sla_warning` / `ticket.sla_breach` (`after.metric`, `after.due_at`, `after.target_id`); `ticket.transition` with `after.via = 'auto'` (snooze, auto-close) or `'rule'`; `ticket.assign` with `after.via` ∈ manual, round_robin, least_loaded, default, rule, triage; `ticket.dispatch` (`after.agent_member_id`, `after.via`, `after.run_id`, `after.status`); `survey.send` (`after.requester_id`, `after.via`); `worker.pass` (the counts). Every ticket row carries `ticket_id` and `queue_id`; no row carries a body.

## Out of scope for this slice
- The sending itself (slice 7 owns `outbox_send_batch` and the mail poll; this slice calls them); the survey page the requester rates on (slice 6); AI actions inside rules (Later); K8's wake call.

## Proof (`tests/phase3/slice8/run.sh`: the scratch database, slice 3's world, the worker run as a command with `HD_WORKER_NOW` faked, members through dev hand-offs and action tokens, the stubbed kernel counting chat calls, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-03: 110 checks green** (worker 7, sla 11, rules 20, triggers 11, assignment 13, survey 6, screens 17, json 10, browser 15, the registry); Phase 2's and
slices 1–7's suites still green (Phase 2's vhost check now expects the runs page); the installer's `plan` clean.
- [x] **The worker.** One pass prints the report naming every step with its counts' shapes; `worker.pass` logged once with source cron and the report; a second pass with the same clock
  changes nothing (no new rows); `--only=sla` runs one step; a pass while one runs → `{"skipped":"locked"}`; housekeeping removes stale viewers and spent nonces.
- [x] **SLA.** A first-response target past 85 % of its span → one `ticket.sla_warning` with the target id, `warned_at` stamped, the assignee and the lead told; the next pass warns no more for
  it; past due → `breached_at = due_at`, one `ticket.sla_breach`, told once each for that target; the next pass breaches no more; the ticket shows breached; a paused target is never breached
  nor warned.
- [x] **Snooze and auto-close.** `pending_until` passed → open via auto with the wake-up cleared and `pending_elapsed` queued for the assignee; `auto_close_days = 0` → never; solved 5 days
  ago with 4 → closed via auto and its secure link dated.
- [x] **Rules.** The two seeded rules fire through the rules step (the solved one closed, the snoozed one reopened; `rule.fire`, a `rule_runs` row, `run_count`, the move via rule, the
  report counting per rule); a problem solved → Run now applies `link_children`: its three children solved with resolved and the note naming the parent; "Would match N" equals applied +
  skipped; run again → skipped (no open children); a missing status planted by SQL → skipped with *"No status 'parked'."*; a throwing action → error, counted, and the next rule still ran
  and tagged the ticket; a second pass skips the tag rule (nothing to change); `days_since solved_at gte setting:auto_close_days` → the note with `{{ticket.number}}` filled.
- [x] **Triggers.** On create of a high ticket from the Gold organization: in IT, urgent, tagged — `ticket.create` committed before `rule.fire`, the queue move logged via trigger with the
  rule id; a low one untouched; a trigger that throws leaves the ticket and records an error; a `ticket.update` trigger fires once on a change and its own set_fields fires no second round
  (depth 1); `ticket.reply` and `ticket.note` triggers fire; a request from the public form fires `ticket.create` triggers too.
- [x] **Assignment.** The IT queue holds the owner, Marco, Lena and the agent; round-robin walks 1, 27, 28, 40 and wraps (`ticket.assign` via round_robin); with Lena offline and the agent at
  capacity 1 it walks 27, 1, 27; least-loaded picks the emptiest; the queue's default assignee an agent: from the form (nobody acting) a dispatch via duty with no network call, by Marco via
  chat with the stub's one call carrying X-Acting-Member 27; a queue change with no assignee named lets the new queue's method assign; the dispatch retry: the worker retries a failed
  dispatch as the person who assigned, gives up after three failures (logged once) and leaves it alone after.
- [x] **Survey.** Solved 30 minutes ago with a 60-minute delay: not yet; past the delay → one rating row with a hash, the raw token in the survey mail only (hashing to the row),
  `survey.send` via auto without the token; a second pass sends none; a requester with no address → none.
- [x] **Rights and the screens.** Support reads the list without the buttons, a requester → 403; the form and every handler → 403 *"You may not change Help Desk's settings."* for support;
  the seeded rules show Built in with a switch and Run now; the form's rows become the documents (a list from "a, b", the empty row dropped), `rule.create` logged, landing on the edit
  page with the rows and "Would match N"; a missing status on save → 422 *"No status 'parked'."*, an unknown field → 422 by name; toggled off; the runs ledger (support may read); deleted.
- [x] **JSON mode.** `rule_create` (`{ok, record_id, kind, location}`), a tag that does not exist → 422 *"No tag 'never-matches'."*, `rule_update` keeps what it was not sent, the kind is fixed
  once saved, validation 422 by field, `rule_run_now` (`{ok, did, applied, skipped}`), `rule_toggle` (`{ok, active}`), support → 403, `rule_delete` (`{ok}`), the list answers JSON.
- [x] **375 × 740 and 1280 × 800.** The rules list, the form (the condition rows stack on a phone) and the runs page render without horizontal scroll; no console errors.

**Decisions taken while building (2026-10-03):** the worker's steps read the base tables (the mcp views are empty to a caller with no member) through `rule_ticket_rows()`, one query
a pass; `warned_at` (db/016) answers "not yet warned" — no log lookup; a rule's note (and the note on a child a problem solves) is authored by the rule's creator, else the first
super-admin — the schema demands an author; a form's condition rows are "all" rows (a list value turns `eq` into `in`; the JSON tab wins when given); a refused action is that action
skipped, not the run failed (savepoints per action; an unexpected throwable rolls the ticket back and is the run's error); the assignment on arrival is `assign_on_arrival()` inside the
creating transaction, and a queue change with no assignee named in `ticket_assign` lets the new queue's method pick; the dispatch retry acts as the person who assigned (a retry via duty would
do nothing); `rule_run_now` never fires triggers; the rules list is readable by `tickets.view_queue` (the buttons for `settings.manage`); the header's three "new rule" buttons are icon-only on
a phone; `survey_pass()` is the worker's own clock-aware step (slice 6's `send_due_surveys()` stays for a plain call). The open questions stand: `warned_at` is the column (chosen); auto-close
is silent.

## Open questions
- `warned_at` as a column (db/016) or the activity-log lookup — the lookup costs one query per candidate target per pass; fine under a thousand open targets, a column beyond.
- Should auto-close tell the requester ("we closed this")? Proposed: no — the solve already asked them to confirm; closing is silent.
