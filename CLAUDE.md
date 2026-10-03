# Help Desk — CLAUDE.md

This is **Help Desk**, the fourth application from us beside the Business OS kernel (kernel repo `/var/www`, its
design `docs/business-os-integration.md` there) and the first that is **part of every OS install**: anyone — an
employee, a customer with no OS account, an OS agent — files an issue or a request here, and people and agents work it.
Read, in order: `docs/helpdesk-design.md` (the OS-fitted design: what changes from the Phase 0 plan, externals, agents,
roles, the memory model, the build order, the kernel items owed, the decisions asked of the owner), then
`docs/01-phase0-plan.md` (the domain plan: the 44 questions, the action inventory, the board, the research), then — once
Phase 1 exists — `docs/helpdesk-mcp-tool-surface.md`, `docs/helpdesk-action-manifest.md` and the slice specs in
`docs/build-specs/` (the exemplar: tickets core).

## Two plugins govern this repo
- `htmx-php-builder` — **how it is built**: `new-app` (the fixed phase order), `php-patterns` before any PHP,
  `design-system` before any markup, `php-session-auth` for session hardening and CSRF, `chat-actions` for the
  command bar, `mcp-servers` for the two read servers, `new-screen` for every screen.
- `maludb-os-integration` 0.5.0 (skill `os-integration`, `~/maludb-os-integration`) — **how it fits**: the mirror
  with the kernel's ids, the `/sso` receivers, the run token honoured everywhere, the command bar through the kernel's
  chat endpoint, roles and rights published by `app_roles`, texts through the kernel (K6), `maludb-os.json`. Where the
  two conflict, the integration contract wins: **no password, no login form, no account, no model key, no Twilio key,
  no shared tables, no actions server of our own**. Help Desk never writes the directory.

## Rules that do not move
- **Vocabulary: "agent" means an OS AI agent**, always. The person who works tickets is *support staff*; the holder
  of a ticket is its *assignee*. The Phase 0 plan's "agent" / "light agent" / "AI agent (external)" are support staff /
  collaborator / a hired OS agent.
- **The owner's decisions (2026-10-02, design §14) are rules**: D1 externals by secure link and email, no account; D2 the five roles, base key `user`; D3 one desk, queues per department; D4 MaluMail mailboxes; D5 an agent's reply pauses; D6 expert, triage, frontline hired on install; D7 history = the activity log; D8 no webhooks, no write tokens; D9 `HD-` numbering; D10 catalog Everyday/other; D11 kernel items K8–K11 approved; D12 ports 8182/8825/8826 pinned.
- **Checkpoint gate**: no feature PHP until the owner approves the schema + tool surface + manifest + slice specs
  together (Phase 1). Schema changes after that are numbered additive migrations, each with sign-off.
- **Externals have no account** (design §4): a ticket's secure link `/t/<token>` (hashed, rotatable, expiring) and the
  email reply are their authority; the public form `/request` and the webhook `/channels/malumail/mail` are the other
  public doors; every public path is named in the vhost's allow-list, CSRF- or signature-checked, rate-limited, logged
  with `source = 'portal'` or `'webhook'`. A page for an external never shows a note, another requester, or SLA internals.
- Every state-changing handler: `require_post()` + `verify_csrf()` (an action token stands in) + the right
  (`require_right()`, SQL `hd_has_right()`) + `hd_can_see_ticket()` for the record + `log_activity()` +
  `emit_action_status()`; a create's `location` ends in the record id.
- The kernel's rules copied here: activity `source` is `web` for the UI, `agent` under a run token, `cron` for the
  worker; `action` is `entity.verb`; `mcp_*` views are the only thing the read roles see, with caller checks as
  uncorrelated sets tested once per statement; `app.member_id` is set before any query; an unknown member id is refused,
  never created. **A ticket's history is the activity log** — no ticket-events table.
- An agent's public reply (`ticket_reply`) pauses in the kernel's approvals (`external_send`) until the owner relaxes
  the policy; triage actions (fields, tags, assign, link, note, new → open) never pause.
- Writes by anything automated go through the kernel's Actions MCP as a hired agent; a person's own MCP token is
  read-only. No webhooks, no `actions` token scope, no second actions server.
- Email is MaluMail: a mailbox per queue, inbound by the signed new-mail webhook plus a once-a-minute poll, outbound
  threaded; the application holds `MALUMAIL_API_KEY` (optional env). Texts only through the kernel (K6), every refusal
  falling back to email. No model is ever called from PHP: "summarise", "draft" and "suggest" are the expert's, through
  the chat endpoint.
- **Every port env (`APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT`) is in `maludb-os.json` `env.required`**
  and pinned in `config/.env` before the installer's `apply` (txtSchedules left two out and Apache went down with
  `{{…}}` in its vhost, 2026-10-02).
- Phone first for requesters: every requester screen designed at 375 px before desktop; no modals; full-page
  create/edit; cards for named things, tables for ledgers; every name a link, every page a way back.
- Commit after every finished step on `main`, in the sibling repos' message style (`Help Desk: …`); never push.
- Smokes and fixtures are named `SMOKE <run>`; proofs run on a scratch database and never touch the installed
  application; the installer's `plan` (`php /var/www/bin/app_install.php plan /srv/apps/helpdesk`) runs at the end of
  every phase.

## Environment facts
- Repo and install path `/srv/apps/helpdesk`; database `subello_helpdesk` on the office's PostgreSQL 17 (roles
  `helpdesk_rw`, `helpdesk_records_ro`, `helpdesk_activity_ro`) — not yet created.
- Vhost `helpdesk.subello.com`; ports to pin: `APP_INTERNAL_PORT=8182`, `MCP_RECORDS_PORT=8825`,
  `MCP_ACTIVITY_PORT=8826` (the kernel owns 8080 and 8811–8816; HR 8180/8821/8822; Projects 8181/8823/8824;
  Reservations 8100; txtSchedules 8101/8102/8103). The kernel's internal API: `OS_INTERNAL_URL=http://127.0.0.1:8080`.
- The kit to copy from txtSchedules (`/srv/apps/txtschedules`): `app/auth.php` verifiers, `app/http.php`,
  `app/activity.php`, `app/partial_update.php`, `app/directory.php`, `bin/directory_sync.php`, `html/sso.php`,
  `html/sso/logout.php`, `mcp/db.py`, `mcp/server_common.py` (the run-facts gate), `mcp/activity_ingest.py`,
  `bin/build_action_registry.php`, `bin/mint_mcp_token.php`, the notifications outbox (`app/features/notify/`),
  `tests/` harness patterns. The kernel's `html/channels/malumail-mail.php` is the model for the mail webhook.
- Kernel items this application waits on (design §11): K8 wake-an-agent, K9 escalation → ticket, K10 default
  application, K11 the installer's template check.
- **State (2026-10-03): Phase 0 BUILT; Phase 1 APPROVED (the owner, 2026-10-03 — every one of the ten proposals stands); Phase 2 BUILT and proven** —
  the schema db/001–016 (`db/proof/phase0_proof.sql`, 81 checks), the kit (`tests/phase0/run.sh`, 15), `docs/helpdesk-mcp-tool-surface.md` (36 + 6 tools),
  `docs/helpdesk-action-manifest.md` (65 screens, 96 actions, 40 pausing) → `mcp/action_registry.json` (6 screens + 4 actions built, 18 placeholders), eleven specs
  in `docs/build-specs/` (`tickets-core` = THE EXEMPLAR), the shell (`app/views/layout.php`, `shared/`, `app/features/shell/nav.php` with `render_nav_stub()`,
  `hx_link()`, `back_link()`, `role_badge()`), the Phase 2 screens and handlers, `html/assets/` (the design system + `app-overrides.css`), `maludb-os.json`, `os/`,
  six skills, `deploy/` + `ROOT_STEPS.sh`. **Proof:** `tests/phase2/run.sh` (250 checks; `HD_APP=apache` runs them against the rendered vhost) — green 2026-10-03;
  the installer's `plan` 60 steps, no stop. The record: `docs/helpdesk-design.md` §15. **Next: slice 1 (`docs/build-specs/queues-people.md`), then 2–10 in order;
  a slice's first step is deleting its placeholder's `render_nav_stub()` call.** Owed: the live launch (▲) after the owner's `apply`.
- Proofs: `tests/phase2/run.sh` is the regression (sso, gates, sync, ingest, kernel_compat, vhost, browser.mjs, the registry check; the fakes `tests/fake_kernel.php`, `tests/fake_maludb.php`; ports 8291–8297). `tests/setup_dev.sh` builds the scratch database and writes its environment to `$HD_DEV_ENV` (real environment variables, read ahead of
  `config/.env`); **never put a `config/.env` here before the installer's `apply`** except the three-line port pin of `deploy/ROOT_STEPS.sh` step 0.
- The shell's menu table is `app/features/shell/nav.php` (the bootstrap requires it; Phase 2 builds the shell around it). `app/activity.php`
  takes `ticket_id` and `queue_id` (and `actor_requester_id` for a public door) on every row; a public door sets `$GLOBALS['__public_door']`
  to `portal` or `webhook` so `source` is right. `PARTIAL_UPDATE_TARGETS` is empty until the manifest names the `*_update` handlers.
