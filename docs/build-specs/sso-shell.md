# Build spec: Phase 2 — sign-on, the mirror, the shell (before any slice)

What exists at the end: a person clicks Help Desk on the launcher — or types `helpdesk.<domain>` on a phone — and lands,
signed in, on a home screen that looks finished though it holds no tickets yet; the mirror refreshes every minute; every
request is logged; the command bar answers through the kernel. The kit is in the repo already (Phase 0: Projects' unscoped
identity, roles and sign-on files; txtSchedules' MCP common files, HTTP helper and sync, un-scoped) and is proven without a
kernel (`tests/phase0/run.sh`, 15 checks); this phase wires the shell around it, installs it beside the kernel and proves it.
Schema: `members`, `departments`, `department_members`, `directory_sync_state`, `sso_nonces`, `member_sessions` (db/001),
`activity_log`, `activity_ingest_state` (db/002), `mcp_access_tokens` + `mcp_resolve_token()` (db/003), `hd_rights`, `hd_roles`,
`hd_role_rights`, `hd_has_right()`, `hd_is_admin()`, `mcp_app_roles` (db/004), `notification_prefs`, `notifications` (db/013),
`mcp_members`, `mcp_notifications`, `mcp_my_tokens`, `mcp_activity_log` (db/015). Never modify them.

## Files
**Already in the repo (Phase 0, proven):** `app/bootstrap.php` (env from `config/.env` or real environment variables, session
cookie `HDSID`, `json_mode_begin()`, the acting member on the PDO connection, the action-token / approval-replay path, the
per-request mirror re-check), `app/db.php`, `app/http.php`, `app/auth.php` (`has_right()`, `require_right()`, `is_helpdesk_admin()`,
`require_admin()`, `can_see_ticket()`, `can_work_ticket()`, `can_see_internals()`, `held_queues()`, the verifiers), `app/directory.php`
(the mirror appliers incl. `mirror_apply_roles()` and `access[]`), `app/activity.php` (`log_activity()` with `ticket_id`, `queue_id`,
`actor_requester_id`), `app/partial_update.php`, `app/mail.php`, `app/api/bootstrap.php`, `app/features/shell/nav.php` (the one menu
table), `html/sso.php` (`/sso`), `html/sso/logout.php` (`/sso/logout`), `html/api/v1/health.php`, `app/views/sso/refused.php`,
`bin/directory_sync.php` (`--full`, `--from-file`), `bin/mint_mcp_token.php`, `bin/build_action_registry.php`, `bin/dev_handoff.php` +
`bin/dev_directory.json`, `mcp/activity_ingest.py`, `tests/dev_router.php`, `tests/setup_dev.sh`, `deploy/*` templates.
**Phase 2 adds:** `app/views/layout.php` (the nxl shell, phone first), `app/views/shared/assistant-bar.php`, `app/views/shared/tab-bar.php`,
`app/views/home/dashboard.php`, `html/index.php` (screen `dashboard`), `html/login.php` (302 to `OS_LAUNCHER_URL?app=helpdesk`),
`html/logout.php` (own sign-out: POST + CSRF, end this session, back to the launcher), `html/my-tickets.php` (the empty shape; slice 3 fills it),
`html/notifications.php` + `html/settings/notifications/read.php` (the empty shape; slice 7 fills it), `html/settings/index.php` (screen `settings`,
tab "How I am told") + `html/settings/prefs.php`, `html/settings/tokens/index.php` + `tokens/mint.php` + `tokens/revoke.php`,
`html/activity.php`, `html/assistant/ask.php` (the bar's POST → the kernel's chat endpoint), `app/features/home/queries.php`,
`app/features/settings/{queries,present}.php`, `app/features/activity/queries.php`, `html/assets/` (the design system's
`examples/assets/` verbatim + `htmx.min.js`), `html/manifest.webmanifest` + two icons (installable on a phone; no service worker, no push),
`tests/phase2/` (the proofs). Every other menu item of `nav.php` answers a placeholder page saying which slice builds it (200 with an empty
state, or 403 by the right); the registry builder reads a placeholder as unbuilt.

## Help Desk is not txtSchedules
- **Unscoped.** The claims carry `capability`, `roles[]`, `rights[]`; `/sso` writes `members.roles` through `mirror_apply_roles()` (only
  keys `hd_roles` knows); there is no site, no switcher, no `scope_id`. The session re-check is the mirror row (`status = 'active'`,
  `capability IS NOT NULL`) and `member_sessions` — nothing else.
- **Roles from db/004.** A member whose roles are still `{}` (a kernel from before the grant named them) reads from the capability: `admin` →
  admin, else `user` (`hd_has_right()`). The shell's badge shows the highest role held (admin › lead › support › collaborator › user).
- **Three kinds of person reach the shell differently**: a member through the launcher (this phase); an external through the secure link
  (slice 6, no session, the bare `(public)` layout); an agent never sees HTML — the kit's action-token path answers JSON.
- **The public doors are rewritten by the vhost already** (`/request`, `/t/{token}`, `/kb/…`, `/channels/malumail/mail`); in Phase 2 their
  handlers do not exist and the rewrites land on 404. Nothing on the allow-list answers until its slice (6, 7, 9).

## The receiver (`/sso`) — in order (as the kit does it)
1. `verify_sso_token($token, APP_KEY)` and `verify_sso_claims($claims)` — constant-time, both must pass, member ids must match.
2. Expiry, audience (`helpdesk`), then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows = replay → refuse.
3. In one transaction: `mirror_apply_member()` (the row with `capability`), `mirror_apply_roles()` from `claims.roles`.
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, fresh CSRF token; `member_sessions` row.
5. `log_activity('member.sign_on', 'member', $id, ['after' => ['capability', 'roles']])`; redirect to `/`.
6. Any failure: one page `sso/refused.php` (*"This sign-on link has expired. Open Help Desk from app.<domain> again."*),
   `member.sign_on.refused` with `after.reason` (token, claims, status, capability, replay, mirror) — never shown.

## Every request (bootstrap, as the kit does it)
- Cookie `HDSID`, `SameSite=Lax`, `Secure` when HTTPS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row and the session
  list; otherwise the session is destroyed (`ended_by = 'directory'`) and the visitor sent to the launcher with `?app=helpdesk`.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed;
  an id with no mirror row is refused and logged `member.refused`; a known, active agent with no capability is admitted at first contact
  only when the kernel's run-facts call vouches for it. `SET app.member_id` before any query; `log_screen_view()` on every rendered GET.
- `/sso/logout` (POST from the kernel): `verify_sso_logout_notice` → every session of the member ended (`ended_by = 'kernel'`); 204 always.

## The shell
- Design-system skeleton, exactly. **Phone first**: 375 px is the design. The bottom tab bar carries **Home, My tickets, New request,
  Knowledge base, More**; the header shows the person's name and role badge, the bell (slice 7 fills it) and the command bar;
  `#page-content` is the HTMX target; CSRF meta + `htmx:configRequest` listener; `htmx:afterSwap` re-init; the layout re-stamps
  `data-screen`/`data-entity`/`data-record-id` from `X-Screen`/`X-Entity`/`X-Record-Id` (app/http.php).
- Sidebar from 992 px (and as **More** on a phone): the three groups of `nav_groups()` — **Help** (Home, My tickets, New request, Knowledge
  base), **Work** (Inbox, Board, Requesters, Organizations, Canned responses, Reports), **Settings** (Queues, Statuses, Types/categories/tags,
  Custom fields, SLA and hours, Automation, Mailboxes, Satisfaction, Agents, My settings). An item shows only when the person holds its
  right (`|` = any one). A requester-only person therefore sees Help and My settings alone.
- **The command bar** (`#assistant-bar`, chat-actions): `html/assistant/ask.php` POSTs `{utterance, screen, context, conversation_id}` to
  `{OS_INTERNAL_URL}/api/v1/agents/chat.php?agent=expert` with `OS_APPLICATION_TOKEN` and `X-Acting-Member`; renders `reply`, lists
  `actions` (a paused one as "waits for approval"), follows nothing blindly; `wait` 60, then `GET ?run=` while `finished` is false; logs
  `assistant.ask` (the utterance's length and the run id, never the text). A kernel refusal (403/404/409) is shown in the kernel's words.
- Home (`/`, screen `dashboard`): three regions, each an empty state naming the slice that fills it — **Needs me** (`tickets.view_queue`;
  slice 3's `my_queue`), **My tickets** (slice 3), **Knowledge base** (a search box; slice 9) — and the **New request** button
  (`tickets.create`, → `/tickets/new`, 404 until slice 3 with the placeholder page).
- `/settings/` (screen `settings`): tab **How I am told** — email on/off, text on/off (a note that texts need a phone verified in the OS,
  linking `<OS_LAUNCHER_URL>/settings/channels`), the event kinds as checkboxes, the text kinds, digest — `prefs_save` (upsert `notification_prefs`).
- `/settings/tokens/` (screen `tokens`): my tokens as rows (label, scope, last used, expiry); **Mint** shows the raw value once; **Revoke** confirms.
- `/notifications` (screen `notifications`): the empty shape ("Nothing yet"), `notification_read` wired (marks one or all).
- `/activity` (screen `activity`): the member's own trail from `mcp_activity_log`, 50 a page; `?ticket=` is slice 3's.
- `/my-tickets` (screen `my-tickets`): the empty shape ("You have asked for nothing yet — New request"), filled in slice 3.

## Query functions (signatures fixed)
- `home_needs_me(PDO, int $memberId): array` · `home_my_tickets(PDO, int $memberId, int $limit = 5): array` — empty until slice 3; written now
  so the dashboard's shape is fixed.
- `find_prefs(PDO, int $memberId): array` (defaults when no row) · `save_prefs(PDO, int $memberId, array $prefs): void`
- `find_my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, string $scope): string` (raw, shown once) ·
  `revoke_token(PDO, int $memberId, int $tokenId): bool`
- `find_my_activity(PDO, int $memberId, int $page): array` · `find_my_notifications(PDO, int $memberId, bool $unreadOnly): array` ·
  `mark_notifications_read(PDO, int $memberId, ?int $id): int`

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity`; `emit_action_status`)
- `settings/prefs.php` (`prefs_save`): `require_login()`; own row; log `prefs.save` (`before`/`after` of the changed keys).
- `settings/tokens/mint.php` (`token_mint`): `require_login(); require_human();` log `token.mint` (`after.label`, `after.scope`, the id — never the value);
  `location` ends in the token id. `tokens/revoke.php` (`token_revoke`): own token only (another's → 404 "Token not found."); log `token.revoke`.
- `settings/notifications/read.php` (`notification_read`): own rows; log `notification.read` (`after.count`).
- `logout.php`: own session ended (`ended_by = 'member'`), log `member.sign_out`, 302 to the launcher.
- `assistant/ask.php`: `require_login(); require_human();` not an action — the kernel runs the agent; log `assistant.ask`.

## Action manifest entries
- Screens: `dashboard`, `my-tickets`, `settings`, `tokens`, `notifications`, `activity`.
- Actions: `prefs_save`, `token_mint`, `token_revoke`, `notification_read`. No agent approvals.

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.sign_out.refused`, `member.refused`, `directory.sync`,
`directory.sync.failed`, `screen.view` (dashboard, my-tickets, settings, tokens, notifications, activity), `prefs.save`, `token.mint`,
`token.revoke`, `notification.read`, `assistant.ask`.

## Out of scope for this phase
Every ticket, queue and settings screen (slices 1–10); the public doors' handlers (6, 7, 9); the bell's contents (7); the two MCP servers
(Phase 4 — `maludb-os.json` lists no MCP endpoint until then).

## Proof before Phase 3 (`tests/phase2/run.sh` on a scratch database, against `php -S` with `tests/dev_router.php` and against a private Apache serving `deploy/apache-helpdesk.conf` rendered as the installer renders it)
- [x] *(Phase 0, `tests/phase0/run.sh`, 15 checks)* a hand-off minted the kernel's way opens a session once; replay, another audience, an unknown member and a
  tampered signature → 403 with one page and the reason logged; the sign-out notice → 204 and `ended_by = 'kernel'`; a bad notice → 204; health 200.
- [ ] The kernel-compat mint: a token from the kernel's own `mint_sso_token()`/`sign_sso_claims()` (its `app/auth.php` in a CLI) opens a session;
  `claims.roles = ['support','user']` lands in `members.roles` and `has_right('tickets.work')` is true; `['user']` → false; `{}` with capability
  `admin` → every right.
- [ ] The dashboard at 375 × 740 and 1280 × 800 (headless Chromium): `scrollWidth` = viewport, the tab bar on the phone and the sidebar on the desktop,
  the command bar thumb-reachable, HTMX navigation pushing URL and title, no console errors; the page whole with JavaScript off; a requester-only
  member sees Help and My settings only; a support member sees Work; an admin sees Settings — every other URL → 403 in the right's sentence.
- [ ] `bin/directory_sync.php --full` applies the fixture; an `access[]` row with `capability: null` ends the member's sessions and their next request
  → 302 to the launcher; a role change reaches `members.roles` in one pass; the cursor advances; a second run applies 0.
- [ ] The ingest ships rows tagged `helpdesk` with `ticket_id`/`queue_id` keys and advances the checkpoint only past what a fake MaluDB accepted;
  `/api/v1/health` answers `ingest_lag` and the sync state.
- [ ] The command bar against a stubbed kernel (`tests/fake_kernel.php` answering `/api/v1/agents/chat.php`): a reply rendered, a paused action shown,
  a 409 shown in the kernel's words; `assistant.ask` logged without the text.
- [ ] `token_mint` shows the value once and `my_tokens` never; the minted token opens the records server (Phase 4) — until then, `mcp_resolve_token()` by SQL.
- [ ] `php bin/build_action_registry.php --check` passes; the placeholders read as unbuilt; `php /var/www/bin/app_install.php plan /srv/apps/helpdesk` — no stop.
- [ ] ▲ (the installed application on the live kernel — the owner's `apply`): a real launch from `app.<domain>`, the return trip with `?app=helpdesk`.

## Open questions
- The theme's logo: Help Desk needs its own mark (the owner's artwork); `feather-life-buoy` stands in.
