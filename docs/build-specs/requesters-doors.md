# Build spec: the requesters' doors — slice 6 (D1)

Replicates slice 3's files, gates, flow and proof style, for the one class of person the kernel does not know: the
**external requester with no OS account**. What exists at the end: anyone files a request at `/request`; every ticket's
requester reaches it by the **secure link** mailed in every notice (`/t/{token}`) — reads the public conversation,
replies, attaches, confirms the resolution, reopens within the window, rates — with the mailbox's possession as the
only authority; members confirm and rate from their own ticket page; a lead reads the ratings; the admin tunes the
survey. No password, no account, no session but PHP's anonymous one for CSRF.
Schema: `request_form_submissions`, `csat_settings`, `satisfaction_ratings` (db/012), `ticket_links_secure` and
`hd_secure_link_mint()` / `hd_secure_link_ticket()` (db/010), `hd_requester_for_email()` (db/006), `app_settings`
(`request_form_enabled`, `reopen_window_days`, `secure_link_days`, db/005), the views `mcp_satisfaction_ratings`. Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `request-form` | `/request` | Public: file a request (no session) |
| `secure-ticket` | `/t/{token}` | Public: an external requester's ticket by its secure link |
| `ratings-list` | `/ratings/?score=&queue=&period=` | The ratings received, bad ones first |
| `csat-settings` | `/settings/csat` | The survey: on/off, scale, question, delay, window, reasons, queues |

The two public screens use a **bare layout** (`app/views/public-layout.php`: the nxl look without the shell — no sidebar,
no tab bar, no command bar; the business's name; 375 px first; `data-screen` stamped). They never show a menu, a name of
staff beyond a first name on a reply, or a link into the application.

## The request form (`/request`)
- `GET`: when `app_settings.request_form_enabled` is off, one page *"Requests are taken by email at {default queue's
  address}."* (or *"Requests are not taken here."* when no address) — 200, nothing else. Else the form: your name, your
  email, what it is about (a select of `requester_visible` categories, optional), subject, description (textarea), up to
  three attachments (`ATTACHMENT_MAX_BYTES` each, the slice 3 allow-list of types), a honeypot field (`website`, hidden
  by CSS, must stay empty), the CSRF token of the **anonymous session** (PHP's session started for the visitor like the
  kernel's login page does; no member in it; cookie `HDSID` as everyone's — a session with no `member_id` is nobody).
- `POST`: `require_post(); verify_csrf();` honeypot filled → 200 the same thank-you page, outcome `honeypot`, nothing
  created; **rate limits** from `request_form_submissions`: more than **5 in an hour from one address** or **20 in an hour
  from one IP** → the thank-you page with outcome `rate_limited` (never a different page: no enumeration, no oracle);
  validation → the form again with the errors; else in one transaction: `hd_requester_for_email($email, $name)` (a
  member's address lands on the member's requester row; an organization's domain claims it), the ticket (`channel = form`,
  queue by the category's default else `app_settings.default_queue_id`, type/priority by the category's defaults,
  `created_by NULL`), the first message as a public reply authored by the requester, the attachments, `hd_secure_link_mint()`,
  the outbox row for the confirmation mail carrying the link (slice 7's `notify_requester()`, kind `created`), the ledger row
  (`outcome created`, `ticket_id`); `$GLOBALS['__public_door'] = 'portal'`; `log_activity('ticket.create', 'ticket', $id,
  ['actor_requester_id' => $r, 'after' => [...channel form...]])`.
- The thank-you page says **only**: *"We have your request — HD-1042. An email with a link to follow it is on its way to
  {the address as typed}."* The number is shown (it is in the mail too); nothing else about the ticket is.

## The secure ticket page (`/t/{token}`)
- Every request: `hd_secure_link_ticket($token)` (format-checked, hash-looked-up, not rotated, not expired; stamps
  `last_used_at`) → the ticket id, or **one page** `dead-link` (*"This link no longer works. Reply to any email from us
  about your request, or write to {queue address}."*) with 404 — the same page for malformed, unknown, rotated and expired.
- What it shows (`present_secure_ticket()`, a whitelist: never a base row): the number, the subject, the **status in the
  requester's words** — new/open *"We are on it"*, pending *"Waiting for you"*, on_hold *"On hold"*, solved *"Solved —
  does this resolve it?"*, closed *"Closed"* — the organization's name when there is one, the **public conversation** only
  (`messages.kind = 'public_reply'`), each entry with the author's **first name** (staff and agents alike; an agent shows as
  its display name), the time, the body, the attachments (served through `/t/{token}/attachment?id=` — the token is the
  authority, the attachment must belong to a public message of this ticket). **Never** a note, a CC's or another
  requester's details, an assignee's full name, SLA, tags, custom fields, the queue's internals.
- **Reply** (`/t/{token}/reply`): a textarea + up to three attachments; `POST` CSRF-checked (anonymous session), one
  transaction: slice 3's `add_message($pdo, $ticket, 'public_reply', null, $body, ['author_requester_id' => $r, 'channel' =>
  'portal'])` (the message trigger does the rest: `last_requester_reply_at`, next response started, pending → open or a
  solved ticket within the window → open), the attachments, `notify_member()` to the assignee (kind `replied`);
  `log_activity('ticket.reply', …, ['actor_requester_id' => $r, 'source' portal])`. A reply on a **closed** ticket answers
  the page with *"This request is closed. Start a new request"* and a link to `/request` (the mail path makes a follow-up
  ticket, slice 7; the page sends them to the form).
- **Confirm** (`/t/{token}/confirm`): shown when the status category is `solved`? No — shown when it is **pending or open
  with a reply from us**: *"Did this resolve it?"* → **Yes, resolved** posts `transition_ticket($pdo, $ticket, solved,
  ['resolution_code' => 'resolved'], ['via' => 'requester', 'actor_requester_id' => $r])` (the transitions table allows
  open/pending → solved; the right check is bypassed for the requester's own confirm — `transition_ticket()` takes an
  `as_requester` option that skips `require_can_work_ticket` and allows only → solved with `resolved`); logs `ticket.confirm`
  beside `ticket.transition`. **No, still a problem** opens the reply box.
- **Reopen** (`/t/{token}/reopen`): shown on a solved ticket within `reopen_window_days` of `solved_at`: a reason textarea;
  POST → `transition_ticket(…, open, [], ['via' => 'requester', as_requester])` + the reason as a public reply; after the
  window: *"This was closed on {date}. Start a new request"*.
- **Rate** (`/t/{token}/rate`): shown on a solved or closed ticket when `csat_settings.enabled` and the queue is surveyed
  and the rating row exists (made by the survey) or — for a requester who comes before the survey — made on first view
  of a solved ticket (`ensure_rating_row()`): the scale (`good_bad`: two big buttons; `five_point`: five), the question, a
  bad-reason select (the configured list) and a comment when the score is bad (1–2 of 5, or bad); POST writes `score`,
  `bad_reason`, `comment`, `responded_at` **once** (a second POST shows *"Thank you — already received."*); logs
  `rating.submit` (`after.score`, `after.bad_reason`); `notify_member()` the assignee (kind `rated`). The survey mail's own
  one-time link `/t/{token}/rate?r={rating token}` is the same page; `rating token` is checked against `token_hash` and
  `expires_at` and is not required when the secure link itself is presented.
- Every POST of the page: `require_post(); verify_csrf();` the token re-checked; `$GLOBALS['__public_door'] = 'portal'`;
  `log_activity` with `actor_requester_id` and no `actor_member_id`; a 429-class abuse guard: more than 30 POSTs an hour on
  one link → 429 *"Too many requests — try again later."*.

## Members: confirm, rate, the ratings list, the survey settings
- A member requester confirms and reopens from `ticket-view` (slice 3's `ticket_confirm` / `ticket_reopen` — built there);
  **this slice adds the rating block** to `app/views/tickets/partials/requester-actions.php` (slice 3's partial): on a
  solved ticket of theirs, the scale, a bad reason and comment → `rating_submit` (`/ratings/save.php`).
- `ratings-list` (`reports.view`, and a queue's holder for their queue): a table (a ledger): date, ticket (link), queue,
  score (a badge: good `success`, bad `danger`, 3 `warning`), reason, comment (200 chars, the rest on hover), who was
  assigned; filters score (bad / good / all), queue, period; a CSAT percentage at the top for the filter (good ÷ answered);
  CSV with `format=csv` (`report.export`).
- `csat-settings` (`settings.manage`): enabled, scale, question, send delay (minutes, with a live sentence *"The survey
  goes 60 minutes after a ticket is solved, and may be answered for 14 days."*), response window, bad reasons (one per
  line), queues (checkboxes; none = all) → `csat_settings_save`.
- **`survey_send`** (`/ratings/send.php`, `work`): mails the survey now for a solved ticket of the queue — makes the rating
  row (`token_hash`, `expires_at = now() + window`), queues the mail through `notify_requester()` (kind `survey`, the body
  carrying `/t/{secure}/rate?r={rating token}`); refuses an unsolved ticket (422 *"HD-1042 is not solved yet."*), a queue not
  surveyed, a ticket already surveyed; `external_send` for an agent. **The worker (slice 8)** runs the same function
  (`send_due_surveys()`) for tickets solved more than `send_delay_minutes` ago with no rating row.

## Files (exactly these)
- `html/request.php` · `html/t.php` · `html/t/reply.php` · `html/t/confirm.php` · `html/t/reopen.php` · `html/t/rate.php` · `html/t/attachment.php` · `html/ratings/index.php` · `html/ratings/save.php` · `html/ratings/send.php` · `html/settings/csat.php`
- `app/features/public/form.php` (the request form's validation, limits, create) · `secure.php` (`secure_ticket_open()`, `present_secure_ticket()`, the status words, the abuse guard) · `queries.php` — `app/features/ratings/queries.php` · `present.php` (`ensure_rating_row()`, `send_survey()`, `send_due_surveys()`, the CSAT percentage)
- `app/views/public-layout.php` · `app/views/public/request.php` · `request-done.php` · `request-closed.php` (the form is off) · `secure-ticket.php` · `partials/public-message.php` · `dead-link.php` · `rate.php` — `app/views/ratings/list.php` · `partials/rating-block.php` (the member's, included by slice 3's `requester-actions.php`) — `app/views/settings/csat.php`

## Query functions (signatures fixed)
- `request_form_enabled(PDO): bool` · `request_rate_limited(PDO, string $email, string $ip): bool` · `record_form_submission(PDO, string $email, ?string $ip, string $outcome, ?int $ticketId): void` · `create_request(PDO, array $f, array $files, ?string $ip): array` (`[ticket_id, number, requester_id, raw_link]`; calls slice 3's `create_ticket()` with `channel form` and `actor_requester_id`)
- `secure_ticket_open(PDO, string $token): ?int` (`hd_secure_link_ticket()`) · `present_secure_ticket(PDO, int $ticketId): array` (the whitelist) · `secure_status_words(string $category): string` · `secure_may(array $ticket): array` (`reply`, `confirm`, `reopen`, `rate` booleans with the window and the survey settings) · `secure_abuse_guard(PDO, int $linkId): bool`
- `find_ratings(PDO, int $memberId, array $filters, int $limit = 50): array` (`mcp_satisfaction_ratings` + `mcp_tickets`) · `csat_percent(array $rows): ?float` · `find_rating_for_ticket(PDO, int $ticketId): ?array` · `ensure_rating_row(PDO, int $ticketId, int $requesterId): array` (`[row, raw_token|null]`) · `submit_rating(PDO, int $ratingId, int $score, ?string $reason, ?string $comment): bool` (false when already answered) · `rating_token_valid(array $row, string $raw): bool`
- `send_survey(PDO, int $ticketId, ?int $by): array` · `send_due_surveys(PDO, int $limit = 100): int` · `find_csat_settings(PDO): array` · `save_csat_settings(PDO, array $f, int $by): array`

## Handlers (every one: `require_post(); verify_csrf();` the gate — a token or the form for the public ones; one transaction; `log_activity` with `ticket_id`/`queue_id` and `actor_requester_id` on the public ones; `emit_action_status`; `HX-Trigger`)
- `request.php` POST: as above; outcome page always 200; `location` is never a ticket URL (no session to open it).
- `t/reply.php` · `t/confirm.php` · `t/reopen.php` · `t/rate.php`: token → ticket (else the dead-link page, 404); the guard; the action as above; redirect back to `/t/{token}` with a flash; logs `ticket.reply` / `ticket.confirm` + `ticket.transition` (`after.via = 'requester'`) / `ticket.reopen` + `ticket.transition` / `rating.submit`.
- `t/attachment.php` GET: token → ticket; the attachment must be on a public message of that ticket; streamed through slice 3's `attachment_stream()`; log nothing (the page view logged `screen.view` with `ticket_id` and `actor_requester_id`).
- `ratings/save.php` (`rating_submit`): `require_login()`; the ticket's own requester (by `member_id`) → `submit_rating()`; log `rating.submit`; `HX-Trigger: ticketChanged`.
- `ratings/send.php` (`survey_send`): `require_can_work_ticket`; `send_survey()`; log `survey.send` (`after.requester_id`); `location` `/tickets/{id}`.
- `settings/csat.php` POST (`csat_settings_save`): `require_right('settings.manage')`; validate (delay 0–10080, window 1–90, scale, reasons ≤ 20 of ≤ 80 chars, queue ids exist); log `csat_settings.save` (`before`/`after` of the changed fields); unchanged → *"Nothing changed"*, no row.

## Action manifest entries
- Screens: `request-form`, `secure-ticket` (public), `ratings-list`, `csat-settings`.
- Actions: `rating_submit`, `survey_send`, `csat_settings_save`. Agent approvals: `survey_send` (`external_send`),
  `csat_settings_save` (`other`). The public POSTs are **not actions** (the manifest's "Public doors").

## Activity log events
- `screen.view` (`request-form` with no actor; `secure-ticket` with `actor_requester_id` and `ticket_id`; the two member
  screens), `ticket.create` (`after.channel = 'form'`, `after.requester_id`, `after.queue_id`, `after.priority`),
  `ticket.reply` (`after.kind`, `after.excerpt`, `after.attachments`, `after.channel = 'portal'`), `ticket.confirm`,
  `ticket.reopen`, `ticket.transition` (`after.via = 'requester'`), `rating.submit` (`after.score`, `after.bad_reason`),
  `survey.send` (`after.requester_id`), `csat_settings.save`, `report.export` (the ratings CSV), `request_form.rate_limited`
  and `request_form.honeypot` (no actor; `after.outcome` — never the address). A secure token or a rating token is never in a payload.

## Out of scope for this slice
- The mail that carries the link and the survey (slice 7 sends what this slice queues); the worker's timing of the
  survey (slice 8 calls `send_due_surveys()`); public knowledge base pages (slice 9); the magic-link "all my tickets" page
  for externals (Extended); the member's `my-tickets`, `ticket_confirm`, `ticket_reopen` (slice 3).

## Proof (`tests/phase3/slice6/run.sh`: the scratch database, slice 3's world, curl with an anonymous session for the public doors, members through dev hand-offs and action tokens for the rest, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-03: 130 checks green** (form 22, link 11, actions 19, rating 21, members 13, settings 12, json 8, browser 23, the registry); Phase 2's and slices 1–5's suites
still green; the installer's `plan` clean.
- [x] **The form.** GET: the form with a honeypot and a CSRF token, no shell; a submission with a category and a file → one ticket (channel form, routed by the category to IT at high, `created_by`
  NULL, no submitter), the requester an external claimed by Customer Co's domain, one public message authored by the requester on the portal channel with its file, one secure link row (its hash
  only), one `created` notice carrying `/t/` + 48 hex and the number and nothing else of the ticket, the mailed token the live link, `ticket.create` logged with `actor_requester_id`, source
  portal and channel form, the ledger row with the IP; the thank-you page shows the number and the address as typed and nothing else; a member's address lands on their requester row (no
  second row); the honeypot filled → the same page, nothing created, outcome honeypot; no CSRF → 403; validation → the form again with the errors (422); the sixth from one address in an hour →
  the same page, nothing created, outcome rate_limited (the log never carries the address); past the IP limit a fresh address is limited too; the form off → *"Requests are taken by email at
  help@example.invalid."* and POST 404.
- [x] **The link is the authority.** The mailed token opens the page (the number, the subject, the reply box, no shell); `last_used_at` stamped; `screen.view` logged with `ticket_id` and
  `actor_requester_id`; a malformed or short token never reaches PHP (the router, as Apache, answers 404); unknown (twice), rotated and expired → 404 and identical bytes; the fresh link works
  again once its expiry is lifted; with a note, a CC, an assignee, a tag, a custom value and a breached SLA on the ticket the page shows none of them and the reply under the first name only; no
  link into the application; the ticket's own attachment downloads (nosniff); another ticket's by id and a file on a note → the dead-link page.
- [x] **Reply, confirm, reopen.** A reply with a file on a pending ticket: back to the page with the notice, the trigger reopens it, a public reply on the portal channel by the requester, the
  assignee's `replied` notice, `ticket.reply` logged with `actor_requester_id` and channel portal, the page shows the notice and the reply as "You"; after a reply from us "Did this resolve
  it?" — confirm → solved with resolved, `ticket.confirm` and `ticket.transition` (via requester) with `actor_requester_id` and no `actor_member_id`, the page shows the reopen box; reopen
  within the window → open, reopen_count 1, the reason a public reply, `ticket.reopen` logged; after the window (solved 20 days back) → *"This was closed on … Start a new request"* and POST 422;
  a reply on a closed ticket → the closed notice, nothing written, the page sends them to the form; thirty replies in an hour pass, the 31st → 429.
- [x] **Rating.** `survey_send` on an unsolved ticket → 422 *"HD-… is not solved yet."*; no block on an open ticket; good_bad → two buttons and the question; the row made on first view of the
  solved ticket (hash, expiry); the bad-reason list the configured one; five_point → five buttons; a bad rating with a reason and a comment written once, `rating.submit` logged with the score
  and the reason, the assignee's `rated` bell notice; a second POST → already received, the row unchanged, the page says so; `survey_send` on a solved ticket → a survey notice carrying
  `/t/{secure}/rate?r={rating token}` (a second live link — the created one still works), `survey.send` logged; again → 422; the survey page by the mailed link carries the rating token; a
  wrong token → the dead-link page, nothing written; after `expires_at` dead; with the live token → rated Good, once only; a queue not surveyed → 422.
- [x] **Members.** Priya's solved ticket shows the rating block (not on Marco's view); Marco's `rating_submit` on it → 403 *"Only the requester rates a ticket."*; Priya rates (written, logged,
  Marco's bell), a second → *"Thank you — already received."*; `ratings-list`: Lena every rating, bad first, a CSAT percentage; Marco IT's only; Priya → 403 *"You may not see reports."*; the bad
  filter; the ledger page; the CSV logged as `report.export`; the registry marks `survey_send` external_send and `csat_settings_save` other (the kernel pauses an agent by action).
- [x] **Settings.** The screen with the live sentence and the queues; support and a requester → 403; the same values → *"Nothing changed"*, no row; a change → `csat_settings.save` with only the
  changed fields; a delay out of range and an unknown scale → 422; queues chosen and cleared; `send_due_surveys()` leaves a ticket solved 3 minutes ago under a 5-minute delay and surveys it under
  a 2-minute one (logged with source cron), a second run sends nothing, the survey off sends nothing.
- [x] **JSON mode.** `rating_submit` (422 by field for a bad score; `{ok, did, record_id, score, location}`), `survey_send` (the sentence; `{ok, did, rating_id, location}`), `csat_settings_save`
  (`{ok, did, changed}`, Nothing changed, 403 for support); the settings screen answers JSON.
- [x] **375 × 740 and 1280 × 800.** The request form, the secure page and the dead-link page fit with every button ≥ 44 px and no shell; the desktop page centred at a readable width; the
  form files a request and the thank-you page shows the number; the solved ticket shows the rating block; the reply box full width; no page errors.

**Decisions taken while building (2026-10-03):** the rate limits are `app_settings.form_limit_per_email` / `form_limit_per_ip` (the schema had them; the open question is closed); the
thank-you page for a refused submission (honeypot, rate-limited) is the same page without a number — there is no ticket to name; a malformed token is refused by the router and Apache before
PHP, so "identical bytes" holds for every token PHP sees; the survey mail carries an **extra** live secure link (`mint_extra_secure_link()`) — `hd_secure_link_mint()` rotates earlier links
and would kill the requester's created mail; the abuse guard counts the writes through the link (reply, confirm, reopen, rate) in the activity trail, never the form's create; the `rated`
notice is the assignee's bell (mail follows their preferences, as every notice does); the worker's `survey.send` rows carry source `cron` (the schema's list); `create_ticket()` takes a nullable
actor (`created_by NULL` for the form) and a form's first message is on the portal channel; `transition_ticket()` for the requester's own confirm and reopen passes `checkRight = false` with via
requester (no new option was needed); the public POSTs render the page themselves on a refusal (422) and redirect back with a notice on success; `t/rate.php` on GET is the ticket page.

## Open questions
- Rate limits: 5 an hour per address and 20 per IP are proposals; the owner names the numbers (settings, not constants —
  `app_settings` has no columns for them; this spec reads them from env `REQUEST_FORM_LIMITS` with those defaults until a
  migration adds them).
- Should the thank-you page show the ticket number at all? It is harmless (the number is not a secret, the link is) and
  helps a caller on the phone; this spec shows it.
