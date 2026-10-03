# Build spec: email and notifications — slice 7 (D4)

Replicates slice 3's files, gates, flow and proof style. What exists at the end: a queue has a **mailbox on MaluMail**;
mail to it becomes a ticket or a reply within seconds (the signed new-mail webhook) and never later than a minute (the
poll); every notice Help Desk owes anyone — a reply to a requester with their secure link, an assignment, a mention, an
SLA warning — leaves through **one outbox**, by email through MaluMail and, for members who want it, by text through the
kernel (K6), every refusal of a text a skip while the email still goes; the in-app bell shows what is unread; each person
chooses what they are told. Slices 3–6 queue rows; this slice sends them, and slice 8's worker runs the sending.
Schema: `mailboxes`, `inbound_email`, `outbound_email` (db/012), `notifications`, `notification_prefs`, `notification_outbox`
(db/013), `messages.email_message_id` / `in_reply_to` (db/010), the views `mcp_mailboxes`, `mcp_inbound_email`,
`mcp_notifications` (db/015). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `mailboxes-list` | `/settings/mailboxes/` | The queues' mailboxes, their last poll and inbound failures count |
| `mailbox-add` | `/settings/mailboxes/new` | Add a mailbox for a queue |
| `mailbox-edit` | `/settings/mailboxes/{id}/edit` | Change a mailbox |
| `inbound-list` | `/settings/mailboxes/inbound?status=&mailbox=` | Inbound mail that failed, bounced or was ignored — retry or dismiss |
| `notifications` | `/notifications` | My in-app notifications, unread first |
| `settings` | `/settings/?tab=notify` | **How I am told** (this slice fills the tab Phase 2 left empty) |

## Mailboxes
- Cards: address, display name, queue, provider (MaluMail), polling on/off, last polled, the count of inbound rows not
  `processed` in 7 days (a `danger` chip when > 0, linking to `inbound-list`), **Poll now** (`mailbox_poll_now`), **Edit**.
  Empty: *"No mailbox yet — add the queue's address and point MaluMail's new-mail webhook at
  https://{host}/channels/malumail/mail."*
- Form: address, display name (the From name), queue, **webhook secret env key** (`webhook_secret_ref`, e.g.
  `MAILBOX_SUPPORT_WEBHOOK_SECRET` — the secret itself lives in `config/.env`; the form shows ✓ when the key is set, never
  the value), signature (appended to outgoing replies), polling on/off. The page says what the owner does in MaluMail
  (create the mailbox, set the webhook URL and secret, put the secret in `.env` under that key).
- `inbound-list`: a table — received, from (address), subject, mailbox, status (`failed` danger, `bounce` dark,
  `auto_reply` secondary, `ignored` muted, `suppressed`), error, ticket (link when linked), **Retry** (`inbound_retry`),
  **Ignore** (`inbound_ignore`, a reason). Never the body on this screen (the admin opens the ticket, or MaluMail).

## Inbound — the webhook and the poll
- `html/channels/malumail-mail.php` (public, allow-listed): modelled on the kernel's: POST only (405 else); the raw body
  (≤ 64 KB) parsed; the mailbox found by `address` (active, provider malumail); the secret read from
  `env($mailbox.webhook_secret_ref)`; `malumail_signature_valid($secret, X-MaluMail-Signature, $raw)` — HMAC-SHA256,
  constant-time, with a timestamp at most **five minutes** old; no mailbox, no secret or a bad signature → **403** and an
  `error_log` line (no activity row — an unauthenticated caller writes nothing); `type = message.new` and `folder = INBOX`
  and `uid > 0` → `mail_inbound_uid($pdo, $mailbox, $uid)` inside try/catch (an exception is logged and the poll picks it
  up); **204** whatever became of the message. `$GLOBALS['__public_door'] = 'webhook'` so every row is `source webhook`.
- **The poll** (`mail_poll_mailbox($pdo, $mailbox)`, called by slice 8's worker every minute per active mailbox with
  `poll_enabled`): `list_messages(unseen)` since `last_uid`, each through `mail_inbound_uid()`; `last_polled_at`,
  `last_uid` advanced only past what was handled.
- **MaluMail's mailbox MCP** (M3) is reached by `malumail_mcp_call(string $tool, array $args): array` in `app/mail.php`
  (JSON-RPC over HTTPS to `MALUMAIL_MCP_URL`, default `https://api.malumail.com/mcp`, bearer `MALUMAIL_API_KEY`; tools
  `list_messages`, `read_message`, `mark_message`; the mailbox named by address; 15 s timeout; a failure raises). Help
  Desk holds no mailbox password.
- **`mail_inbound_uid()`** — one transaction per message, idempotent on `(mailbox_id, provider_uid)`:
  1. `read_message` → `inbound_email` row (`status received`, headers kept, body text + sanitised HTML, attachments
     downloaded to `storage/attachments/inbound/{id}/` within `ATTACHMENT_MAX_BYTES`).
  2. **Auto-reply / bounce detection** (`Auto-Submitted` ≠ no, `X-Autoreply`, `Precedence: bulk|auto_reply`, a
     `multipart/report` with `report-type=delivery-status`, a From of `mailer-daemon@`/`postmaster@`) → status `auto_reply` or
     `bounce`, `outbound_email` marked `failed` when the bounced Message-ID matches one of ours, **no ticket**, `mark_message(seen)`.
  3. **Threading** (`mail_thread_ticket()`): `In-Reply-To` then each `References` id against `messages.email_message_id`
     and `outbound_email.provider_message_id` → the ticket; else the subject's `[HD-1042]` tag → the ticket; else none.
  4. A **ticket found** and not closed: the sender → `hd_requester_for_email(from, from_name)`; a sender who is the ticket's
     requester or a CC → a **public reply** authored by that requester (`add_message(…, ['author_requester_id', 'channel' =>
     'email', 'email_message_id', 'in_reply_to'])` — the trigger reopens pending/solved-in-window); a sender who is a **member**
     with `tickets.work` in the queue → a public reply **by the member** (support answering from their mail client; the
     `#note` marker as the first line makes it an internal note); any other sender → added as a CC and their text a public
     reply. **Closed** ticket → a new ticket (`channel email`) linked `follow_up_of`, same requester, the subject kept.
  5. **No ticket**: a new ticket in the mailbox's queue (`channel email`, the subject or *"(no subject)"*, the body as the
     first public reply, CCs from the mail's Cc as `cc` followers by requester row, the attachments on the message,
     `hd_secure_link_mint()`), the confirmation notice queued (`notify_requester(kind created)` — carries the link).
  6. **Reply-above-the-line**: `mail_strip_quoted($text)` cuts at the first of: a line starting `On … wrote:`,
     `-----Original Message-----`, `From: … Sent: …`, a run of `>`-quoted lines to the end, our own marker line
     (`## Reply above this line ##`, which every outgoing notice carries); the full text stays in `inbound_email.body_text`.
  7. The row → `processed` with `ticket_id`, `message_ref_id`; `mark_message(seen)`; `log_activity('mail.inbound', 'inbound_email',
     $id, ['after' => [mailbox_id, message_id, outcome, ticket_id]])` with `ticket_id`/`queue_id`. A failure → `failed`
     with the error, the message left unseen, `mail.inbound` with `outcome failed` — `inbound_retry` runs the same function.
  8. A sender address that bounced our mail twice in 7 days, or that `ticket_spam` suppressed, → status `suppressed`, no ticket.

## Outbound — the outbox and its senders
- **The ONE queueing function** (`app/features/notify/queue.php`): `notify_member(PDO, int $memberId, string $kind, ?int
  $ticketId, string $subject, string $body, ?string $dedupe = null, ?int $messageId = null): int` writes one row **per
  channel** the person has on for that kind (`notification_prefs`: email when `email_enabled` and `kind ∈ kinds`; text when
  `text_enabled` and `kind ∈ text_kinds`) — and an in-app `notifications` row always; `notify_requester(PDO, int
  $requesterId, string $kind, int $ticketId, string $subject, string $body, ?string $dedupe = null, ?int $messageId =
  null): int` writes an email row for an external (its address from `requesters.primary_email`) or calls `notify_member()`
  for a member requester. Returns rows queued. `dedupe_key` = `{kind}:{ticket}:{who}:{channel}:{event id}` — unique, so a
  second pass never repeats. **Nothing is queued** for an inactive member, for a requester with no address, or from an
  evaluation run (`is_action_authed()` with `is_eval`).
- **Who is told of what** (`app/features/notify/kinds.php`, the table every slice reads):

| Kind | Who | When (queued by) |
|---|---|---|
| `created` | the requester (+ CCs) | a ticket from the form or mail (slices 6, 7) — carries the secure link for an external |
| `replied` | the requester + CCs (a public reply by support); the assignee (a requester's reply) | slice 3's `add_message()` |
| `noted` / `mentioned` | the members named in a note | `add_message()` |
| `assigned` | the new assignee (a person; an agent is dispatched, slice 8) | slice 3's `assign_ticket()` |
| `new_in_queue` | the queue's leads, when a ticket lands unassigned | `create_ticket()`, `assign_ticket()` to a queue |
| `followed_changed` | followers, on status or assignee change | `transition_ticket()`, `assign_ticket()` |
| `sla_warning` / `sla_breached` | the assignee and the queue's leads | the worker (slice 8) |
| `pending_elapsed` | the assignee | the worker |
| `rated` | the assignee | slice 6 |
| `escalated` | the person a ticket was handed to by an agent | `assign_ticket()` when the actor is an agent |
| `survey` | the requester | slice 6 |

- **Bodies** (`notify_body()` templates in `app/views/emails/*.php`, plain text + a simple HTML): the ticket's number and
  subject, what happened, who (first name), the excerpt of a public reply when the recipient may read it (a requester's
  mail carries the reply in full), the link — `/tickets/{id}` for a member, **the secure link** for an external — and the
  marker `## Reply above this line ##` above our text. **Never**: a note, another requester's or CC's address, an agent's
  internals, SLA, a token other than the recipient's own link.
- **Sending** (`app/features/notify/send.php`, `outbox_send_batch(PDO, int $limit = 100): array` — slice 8's worker calls
  it every minute): rows `queued` with `send_after <= now()`:
  - **email**: MaluMail `POST /v1/send` (`MALUMAIL_API_URL`, bearer `MALUMAIL_API_KEY`): from the ticket's queue mailbox
    address and display name (else `MAIL_FROM` / `MAIL_FROM_NAME`), to the one address, subject `[HD-1042] {subject}`,
    `in_reply_to` / `references` = the last outbound/inbound Message-ID of the ticket so mail clients thread, `reply_to`
    the mailbox; the answer's `message_id` kept in `outbound_email` (one row per send, `provider_message_id`) and on the
    message (`messages.email_message_id` when the row carries `message_ref_id`). `2xx` → `sent`; a rejected address or
    MaluMail's 400 → `skipped` (`undeliverable`); 429/5xx/transport → `retry` (one a minute, the 5th failure → `failed`);
    no `MALUMAIL_API_KEY` → the row stays `queued`, attempts not counted (`unconfigured`), texts still go.
  - **text**: `POST {OS_INTERNAL_URL}/api/v1/notify/sms.php` with `OS_APPLICATION_TOKEN`, `{member_id, text ≤ 480,
    reference: "ticket:{id}"}`; `202` → `sent` (`detail` = the kernel's id); `503 no_sender` and every 422 (`no_verified_phone`,
    `opted_out`, `rate_limited`, `not_held`, `invalid`) → `skipped` with the code — **the email row is independent and still
    goes**; 401/5xx/unreachable → `retry`. The text: *"Help Desk: HD-1042 assigned to you — {subject}. {link}"* (the kernel
    puts the application's name in front; this text names nobody else).
  - Every send logs `notification.send` (`after`: kind, channel, outcome, code — no body, no address).
- **The bell**: the shell header's `#notify-bell` with the unread count (`GET /notifications?count=1`, `hx-trigger="every
  60s"`, Pattern A); `notifications` screen: cards newest first, unread bold, each linking to its ticket, **Mark read** per
  card and **Mark all read** (`notification_read` with no id); opening a ticket page marks that ticket's rows read.
- **Settings → How I am told** (`prefs_save`, Phase 2's action, the tab built here): email on/off, text on/off (with
  *"Texts need a phone verified in the operating system — My channels"* linking to `{OS_LAUNCHER_URL}/settings/channels`
  when the last text row for this person was skipped `no_verified_phone`), the kinds as checkboxes for email and for text,
  digest (a daily summary instead of each mail — the worker's daily step, slice 8).

## Files (exactly these)
- `html/channels/malumail-mail.php` · `html/settings/mailboxes/index.php` · `form.php` · `save.php` · `archive.php` · `poll.php` · `inbound.php` · `inbound/retry.php` · `inbound/ignore.php` · `html/notifications.php` · `html/notifications/read.php` · `html/settings/index.php` (the tab) · `html/settings/prefs.php`
- `app/mail.php` (extend: `malumail_send()` with threading, `malumail_mcp_call()`, `malumail_signature_valid()`) · `app/features/mail/inbound.php` (`mail_inbound_uid()`, `mail_poll_mailbox()`) · `thread.php` (`mail_thread_ticket()`) · `parse.php` (`mail_strip_quoted()`, `mail_is_auto()`, `mail_is_bounce()`) · `queries.php` · `present.php` — `app/features/notify/queue.php` · `send.php` · `kinds.php` · `body.php`
- `app/views/settings/mailboxes/list.php` · `form.php` · `inbound.php` — `app/views/notifications/list.php` · `partials/bell.php` — `app/views/settings/partials/notify.php` — `app/views/emails/{created,replied,noted,assigned,new_in_queue,followed_changed,sla,pending_elapsed,rated,escalated,survey}.php`
- `tests/fake_malumail.php` (answers `/mcp` for the three tools and `/v1/send`; records what it got) — the proofs' stub

## Query functions (signatures fixed)
- `find_mailboxes(PDO, bool $failuresOnly = false): array` · `find_mailbox(PDO, int $id): ?array` · `find_mailbox_by_address(PDO, string $address): ?array` · `save_mailbox(PDO, ?int $id, array $f, int $by): int` · `archive_mailbox(PDO, int $id, int $by): void` · `find_inbound(PDO, array $filters, int $limit = 50): array` · `find_inbound_one(PDO, int $id): ?array`
- `mail_inbound_uid(PDO, array $mailbox, int $uid): array` (`[status, ticket_id, message_id]`) · `mail_poll_mailbox(PDO, array $mailbox, int $limit = 50): int` · `mail_thread_ticket(PDO, array $headers, string $subject): ?int` · `mail_strip_quoted(string $text): string` · `mail_is_auto(array $headers): bool` · `mail_is_bounce(array $headers, array $parts): ?string` (the bounced Message-ID) · `mail_sender_suppressed(PDO, string $address): bool`
- `notify_member(…)`, `notify_requester(…)` as above · `notify_kinds(): array` · `notify_body(string $kind, array $ticket, array $ctx, string $audience): array` (`[subject, text, html]`) · `outbox_send_batch(PDO, int $limit = 100): array` (`[sent, skipped, retried, failed]`) · `send_email_row(PDO, array $row): array` · `send_text_row(PDO, array $row): array` (`[outcome, code]`) · `malumail_send(array $msg): array` · `malumail_mcp_call(string $tool, array $args): array` · `malumail_signature_valid(string $secret, string $header, string $raw): bool`
- `find_notifications(PDO, int $memberId, bool $unreadOnly, int $limit = 50): array` · `unread_count(PDO, int $memberId): int` · `mark_read(PDO, int $memberId, ?int $id): int` · `find_prefs(PDO, int $memberId): array` · `save_prefs(PDO, int $memberId, array $f): array` · `last_text_refusal(PDO, int $memberId): ?string`

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity` with `ticket_id`/`queue_id` where there is a ticket; `emit_action_status`; `HX-Trigger`)
- `mailboxes/save.php` (`mailbox_create` / `mailbox_update`): `require_right('settings.manage')`; the address valid and unique; the queue live; the env key named exists in the environment (else a warning, not a refusal — the owner may set it after); log `mailbox.create` / `mailbox.update` (`before`/`after` of the changed fields — never a secret); `location` `/settings/mailboxes/{id}/edit`; `HX-Trigger: mailboxChanged`.
- `mailboxes/archive.php` (`mailbox_archive`): admin; `active = false`; log `mailbox.archive`. `poll.php` (`mailbox_poll_now`): admin; `mail_poll_mailbox()`; log `mailbox.poll` (`after.handled`).
- `inbound/retry.php` (`inbound_retry`): admin; a `failed` row → `mail_inbound_uid()` again; log `mail.inbound_retry`. `inbound/ignore.php` (`inbound_ignore`): admin; status `ignored`, `error` = the reason; log `mail.inbound_ignore`.
- `notifications/read.php` (`notification_read`): own; `mark_read()`; log `notification.read` (`after.count`); the bell partial OOB.
- `settings/prefs.php` (`prefs_save`): own; log `prefs.save` (`before`/`after`: the five fields).
- `channels/malumail-mail.php`: not a handler of the manifest (no CSRF, no session, a signature) — as above.

## Action manifest entries
- Screens: `mailboxes-list`, `mailbox-add`, `mailbox-edit`, `inbound-list`, `notifications`, `settings` (the tab).
- Actions: `mailbox_create`, `mailbox_update`, `mailbox_archive`, `mailbox_poll_now`, `inbound_retry`, `inbound_ignore`,
  `notification_read`, `prefs_save`. Agent approvals: `mailbox_create`, `mailbox_update`, `mailbox_archive` (`other`).

## Activity log events
- `screen.view`, `mailbox.create`, `mailbox.update`, `mailbox.archive`, `mailbox.poll` (`after.handled`), `mail.inbound`
  (`after.mailbox_id`, `after.message_id`, `after.outcome`, `after.ticket_id`; `source webhook` or `cron`), `mail.inbound_retry`,
  `mail.inbound_ignore`, `mail.outbound` (`after.to` count, `after.template`, `after.provider_message_id`), `notification.send`
  (`after.kind`, `after.channel`, `after.outcome`, `after.code`), `notification.read`, `prefs.save`, and — through slice 3's
  functions — `ticket.create` (`after.channel = 'email'`), `ticket.reply` (`after.channel = 'email'`), `ticket.link`
  (`follow_up_of`), `ticket.cc_add`. Every ticket row carries `ticket_id` and `queue_id`. **No body, no address of a third
  party, no secret in any payload.**

## Out of scope for this slice
- The worker itself and its schedule (slice 8 runs `mail_poll_mailbox()`, `outbox_send_batch()`, the SLA warnings, the
  digest); IMAP intake for a non-MaluMail provider (Later); push notifications; a text to an external (K6 is members-only;
  externals get email); dispatching to agents (slice 8).

## Proof (a stubbed MaluMail at `tests/fake_malumail.php` for `/mcp` and `/v1/send`, a stubbed kernel for K6 at `tests/fake_kernel.php`; curl for the webhook; live as members for the screens; headless Chromium at 375 × 740 and 1280 × 800)
- **The webhook**: a signed notice for a known mailbox → 204 and the message read from the stub, a ticket created
  (`channel email`, the subject, the body as the first reply, the requester external and claimed by its domain, the link
  minted, the `created` outbox row carrying `/t/`); the same notice again → 204 and **no second ticket** (`provider_uid`
  unique); a bad signature → 403 and nothing; a six-minute-old timestamp → 403; a notice for an unknown address → 403;
  a GET → 405; `source = webhook` on every row.
- **Threading**: a reply whose `In-Reply-To` is our outbound Message-ID lands on the ticket as the requester's public
  reply and reopens a pending ticket; one with only `[HD-1042]` in the subject lands too; one from a CC lands as the CC's;
  one from Marco (a member with `tickets.work`) lands as **his** public reply, and with `#note` first as a note; one from a
  stranger lands and adds them as CC; a reply to a **closed** ticket makes a follow-up ticket linked `follow_up_of`.
- **Parsing**: the text above `On … wrote:`, above `-----Original Message-----` and above our marker is what the message
  holds; the full text stays in `inbound_email`; an `Auto-Submitted: auto-replied` mail → `auto_reply`, no ticket; a DSN
  for one of our Message-IDs → `bounce`, the outbound row `failed`, no ticket; a sender bounced twice → `suppressed`.
- **The poll**: with the webhook never called, the worker's step picks the unseen message within one pass and advances
  `last_uid`; a message the stub refuses to `read_message` leaves the row `failed` and the uid not advanced; `inbound_retry` then processes it.
- **Outbound and K6**: a reply by Marco queues the requester's email (subject `[HD-1002] …`, `in_reply_to` the inbound
  Message-ID, the marker line, the secure link for an external, the reply in full, **no note**) and nothing for Marco; the
  stub receives it and the row is `sent` with `provider_message_id`; a member with text on and the stubbed kernel answering
  202 gets a text row `sent` and an email row `sent`; with the kernel answering `no_verified_phone`, `opted_out`,
  `rate_limited`, `no_sender`, `not_held` the text row is `skipped` with the code **and the email row is sent**; a 5xx is
  `retry` and the 5th → `failed`; no API key → `queued` and `unconfigured`, attempts 0, texts still sent; the dedupe key
  stops a second queueing of the same event; an inactive member and an evaluation run queue nothing.
- **Nothing sensitive leaves**: grep every queued body and everything the stubs received for the note's text, the other
  requester's address, Dana's surname on a requester mail, any token but the recipient's own link — none.
- **Screens**: `mailboxes-list` for the admin only (Marco 403 *"You may not change Help Desk's settings."*); the form never
  shows a secret value; `inbound-list` filters by status; the bell count follows `mark_read`; the prefs tab round-trips the
  five fields and shows the phone note after a `no_verified_phone` skip; 375 px and 1280 px without horizontal scroll.
- JSON mode for every action; `php bin/build_action_registry.php --check` passes.

## Open questions
- `MALUMAIL_MCP_URL` and `MAILBOX_*_WEBHOOK_SECRET` are not in `maludb-os.json` `env.optional`; they should be added
  (Phase 1 lists this for the owner's approval — a manifest change, not a schema one).
- Should a stranger's reply on someone else's ticket be a CC's public reply (this spec) or a new ticket? The Phase 0 plan
  did not say; CC is the common vendor behaviour.
