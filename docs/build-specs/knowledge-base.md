# Build spec: the knowledge base — slice 9

Replicates slice 3's files, gates, flow and proof style. What exists at the end: sections and articles, **public ones with
no session** (a customer reads `/kb/how-to-reset-a-password` from the link in a reply; the public search at `/kb/`),
internal ones for members with `kb.read`; editors (`kb.edit`) write, publish, archive; support staff **suggest** an article
on a ticket or **send** it as a public reply from the composer; an article can be written **from a ticket**; the
frontline agent's answers are grounded here (`search_articles`). Articles are **Markdown** rendered server-side.
Schema: `kb_sections`, `articles`, `article_links` (db/012); `attachments.article_id` (db/010); `messages` (db/010, through slice 3's
`add_message()`); the views `mcp_kb_sections`, `mcp_articles`, `mcp_article_links` (db/015). Never modify them.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `kb-home` | `/kb/` | Sections and a search; public to everyone; internal ones added for `kb.read` (`?q=`) |
| `kb-section` | `/kb/s/{slug}` | One section's published articles |
| `kb-article` | `/kb/{slug}` | One article; a public one needs no session |
| `kb-admin` | `/kb/admin/` | Every article by status with its counts, and the sections, for editors (`?status=`, `?section=`) |
| `article-add` | `/kb/admin/new` | Write an article (`?section=`, `?from_ticket=`) |
| `article-edit` | `/kb/admin/{id}/edit` | Change an article |

- **Two layouts.** A visitor with no session (or an external) sees the **bare public layout** (slice 6's, as the request form and the secure page use: the business's name, no sidebar, no command bar, a *Sign in* link to the launcher) and only `visibility = public`, `status = published`. A signed-in member sees the shell; with `kb.read` the internal sections and articles join the lists, marked *Internal*; with `kb.edit` drafts appear in admin only. The rule lives in `kb_visible_filter(): array` (one place), never in a view's `if`.
- **kb-home**: the search box (`#kb-search`, Pattern A into `#kb-results`, 300 ms debounce, `?q=`), then the sections as cards (`kb-section-card-{id}`: name, description, article count) in `sort_order`, nested one level (a child under its parent). Empty: *"No articles yet."* — and for an editor a button **Write the first one**. A search answers title, section, the first 160 characters, newest first; no results: *"Nothing matches 'vpn'. Try another word, or file a request."* (the link to `/request` or `/tickets/new` by who is asking).
- **kb-section**: breadcrumb (Knowledge base › parent › this), the description, the published articles as a list (`article-row-{id}`: title, updated, helpful count), children sections first.
- **kb-article**: title, section crumb, *Updated 3 days ago*, the rendered body (`#article-body`), attachments (served through the gate — a public article's through `/kb/attachment.php?id=` with the article's own visibility check), tags, and at the foot **Was this helpful? Yes · No** (POST `/kb/feedback.php`, public, one per IP per article per day, a `thanks` line replaces the buttons; **not an action**). A view increments `view_count` once per session (or per IP for a visitor) — never on an editor's own visit. An internal article to a visitor, a draft to a reader: **404** *"Article not found."* (the same sentence either way). Editors see **Edit** · **Unpublish** · **Archive**.
- **kb-admin** (`kb.edit`): tabs *Published · Drafts · Archived*; a table (ledger): title, section, visibility, views, helpful / unhelpful, updated, by whom; filters `?status=`, `?section=`; **New article**; the sections as a side list with **Add section** / inline edit (`section_save`) / **Archive** (confirm). An archived section's articles stay reachable by slug and are listed under *No section*.
- **article-add / article-edit**: full page: title (the slug derives from it — shown, editable, unique: `my-title-2` on a clash), section, visibility (*Public — anyone with the link · Internal — members only*), tags, the body as **Markdown** in a plain textarea with a **Preview** tab (GET `?preview=1`, rendered server-side, nothing saved); attachments (upload via `attachment_add` with `article` instead of `ticket` — the same handler, the same gate: slice 3's `html/tickets/attachments/save.php` accepts `article`), **Save draft** / **Save and publish**. `?from_ticket=HD-1042` prefills the title from the subject and the body from the conversation's public replies (quoted, requester names stripped to first names, no notes), and on save writes `article_links (created_from)` and a note on the ticket.

## Markdown (`app/features/kb/markdown.php`, no library)
`md_render(string $text): string` — a whitelist renderer: `#`–`###` headings, paragraphs, `**bold**`, `*italic*`, `` `code` ``,
fenced code blocks, ordered and unordered lists, blockquotes, links `[text](https://…)` (http/https/mailto only, `rel="noopener"`),
images only for `/kb/attachment.php?id=` URLs, horizontal rules. Everything else is escaped text. The output is cached in
`body_html` on save; `body_text` stays the source and what search and the tools read. Raw HTML in the source is escaped.

## Suggest and send (the composer, slice 3's `app/views/tickets/partials/composer.php`, gains two buttons for `work`)
- **Suggest article** → `#article-picker` (Pattern A: a search over `kb_search()`); choosing one POSTs `article_suggest`: an `article_links` row kind `suggested` and an internal note *"Suggested article: {title}"* with the admin link; the ticket page shows suggested articles above the composer with **Send** / **Dismiss** (dismiss deletes the link row — no action, the same handler with `remove=1`).
- **Send article** → `article_send`: refused *"That article is internal."* for an internal article when the requester is **external** (`requesters.kind = external`); allowed to a member requester (the link needs their session); allowed always for a public article. The reply = `body` (the words typed, optional) + the title as a link to `/kb/{slug}` (absolute, `APP_URL`) + a two-line summary (the first 300 characters of `body_text`, cut at a word); posted through slice 3's `add_message()` as a **public reply** (so it mails, meets first response, and pauses for an agent exactly as `ticket_reply` does); an `article_links` row kind `sent`. Log `article.send`.

## Files (exactly these)
- `html/kb/index.php` · `section.php` (`/kb/s/{slug}` → `section.php?slug=`) · `article.php` (`/kb/{slug}` → `article.php?slug=`) · `feedback.php` · `attachment.php`
- `html/kb/admin/index.php` · `form.php` · `save.php` · `publish.php` · `archive.php` · `delete.php` · `section.php` · `section-archive.php` · `link.php` · `send.php`
- `app/features/kb/queries.php` · `present.php` · `markdown.php` · `search.php`
- `app/views/kb/home.php` · `section.php` · `article.php` · `admin.php` · `form.php` · `partials/section-card.php` · `partials/article-row.php` · `partials/search-results.php` · `partials/article-picker.php` (the composer's)
- **Two rewrites added to `deploy/apache-helpdesk.conf` (both vhosts) and `tests/dev_router.php`** — a Phase 3 change to the template, named here, not made now: `RewriteRule ^/kb/s/([a-z0-9-]+)$ /kb/section.php?slug=$1` and `RewriteRule ^/kb/([a-z0-9-]+)$ /kb/article.php?slug=$1` (after `/kb/admin/` and `/kb/attachment.php`, which must match first). `/kb/` is already on the public allow-list (design §13).

## Query functions (signatures fixed)
- `kb_visible_filter(?array $member): array` (`['visibility' => ['public'] | ['public','internal'], 'drafts' => bool]`) · `find_sections(PDO, array $filter, bool $withCounts = true): array` · `find_section_by_slug(PDO, string $slug, array $filter): ?array` · `find_articles(PDO, array $filter, ?int $sectionId = null, ?string $status = null, int $page = 1): array` · `find_article(PDO, int $id, array $filter): ?array` · `find_article_by_slug(PDO, string $slug, array $filter): ?array`
- `kb_search(PDO, string $q, array $filter, int $limit = 20): array` (pg_trgm over `title || ' ' || body_text`, `articles_search_idx`; `similarity` desc, then `published_at` desc; `q` ≥ 2 characters)
- `save_article(PDO, ?int $id, array $f, int $by): int` (renders `body_html`, derives the slug, writes `created_from` when `from_ticket`) · `publish_article(PDO, int $id, bool $published, int $by): void` · `archive_article(PDO, int $id, int $by): void` · `delete_article(PDO, int $id, int $by): void` · `save_section(PDO, ?int $id, array $f, int $by): int` · `archive_section(PDO, int $id, int $by): void`
- `article_from_ticket(PDO, int $ticketId): array` (title + Markdown body from the public replies) · `link_article(PDO, int $ticketId, int $articleId, string $kind, ?int $by): void` · `unlink_article(PDO, int $ticketId, int $articleId, string $kind): void` · `article_summary(array $article, int $chars = 300): string`
- `record_view(PDO, int $articleId, string $viewerKey): void` · `record_feedback(PDO, int $articleId, bool $helpful, string $ip): bool` · `md_render(string $text): string`

## Handlers (every one: `require_post(); verify_csrf();` the gate; one transaction; `log_activity`; `emit_action_status`; `HX-Trigger: articleChanged`)
- `admin/save.php` (`article_create` / `article_update`): `require_right('kb.edit')`; title 1–200, body ≤ 200,000 characters, a slug unique (`-2`, `-3` on a clash), a section that exists; `location` `/kb/admin/{id}/edit`; log `article.create` / `article.update` (`after`: title, slug, section_id, visibility, tags, from_ticket). `publish.php` (`article_publish`): `published_at` set once, kept on unpublish; log `article.publish` (`after.published`). `archive.php` (confirm): log `article.archive`. `delete.php` (`article_delete`, `settings.manage`, confirm; refused while `article_links` rows kind `sent` exist: *"This article was sent on N tickets — archive it instead."*): log `article.delete`.
- `admin/section.php` (`section_save`) / `section-archive.php` (confirm; the articles keep their rows, `section_id` set NULL): `kb.edit`; log `section.save` / `section.archive`.
- `admin/link.php` (`article_suggest`; `remove=1` dismisses): `require_can_work_ticket($ticket)`; an article the caller may read; the note via `add_message(kind internal_note)`; log `article.suggest` (`after.article_id`). `admin/send.php` (`article_send`): `require_can_work_ticket`; the internal-to-external refusal (422 with the sentence); `add_message(public_reply)` → the reply's own `ticket.reply` row, plus `article.send` (`after.article_id`, `after.message_id`); **pauses for an agent (`external_send`)**.
- `kb/feedback.php` (public; CSRF of the bare layout; one vote per IP per article per day in `request_form_submissions`? **no** — a `kb_feedback` table does not exist: the limit is kept in APCu/session when present and the counts are increment-only; say in the open questions whether a table is wanted): increments `helpful_count` / `unhelpful_count`; `source = portal`; log `article.feedback` (`after.helpful`); not an action.
- Agents: `article_send` pauses (`external_send`); `article_delete` pauses (`deletion`); everything else never.

## Action manifest entries
- Screens: `kb-home`, `kb-section`, `kb-article`, `kb-admin`, `article-add`, `article-edit` (and the public door `kb-public`). Actions: `section_save`, `section_archive`, `article_create`, `article_update`, `article_publish`, `article_archive`, `article_delete`, `article_suggest`, `article_send`. Tool (Phase 4): `search_articles` (`mcp_articles`, `mcp_kb_sections`; `q` alone = the resolver's list).

## Activity log events
- `article.create`, `article.update`, `article.publish`, `article.archive`, `article.delete` (`after`: title, slug, section_id, visibility, tags, from_ticket, published); `section.save`, `section.archive`; `article.suggest`, `article.send` (`after.article_id`, `after.message_id`; `ticket_id`, `queue_id`); `article.feedback` (`after.helpful`, `actor_requester_id` null, `source = portal`); `screen.view` on `kb-article` (`after.article_id`, `source` `portal` for a visitor). A body is never in a row (a 200-character excerpt at most).

## Out of scope for this slice
- Article versions and review dates; translations; a public article feedback **table** (counts only in v1); "convert this reply into an article" beyond `?from_ticket=`; the frontline agent's grounding (Phase 4 gives it the tool; the skill `answer-from-the-knowledge-base` says how).

## Proof (scratch database; curl with no cookie, as an external by secure link, as members; headless Chromium at 375 × 740 and 1280 × 800)
- **Visibility**: a visitor with no session reads a public published article (200, bare layout, no sidebar, no command bar) and gets 404 *"Article not found."* for an internal one, a draft and an archived one — the same sentence; Priya (`kb.read`) reads internal; a member without `kb.read` (roles `{}` with capability write would have it — use an agent granted nothing) does not; drafts appear for Lena (`kb.edit`) in admin only; search never returns what the caller may not open.
- **Markdown**: a body with a script tag, an `onclick`, a `javascript:` link and raw HTML renders escaped; headings, lists, code, a safe link render; the preview equals the saved render.
- **From a ticket**: `?from_ticket=HD-1042` prefills from public replies only (the fixture's note text is absent), names requesters by first name; saving writes `created_from` and a note on the ticket.
- **Send**: an internal article to an external requester → 422 *"That article is internal."*; a public one → a public reply with the link and the 300-character summary, mailed by the outbox, `ticket.reply` + `article.send` rows, `article_links (sent)`; the agent's `article_send` → `pending_approval` against the stubbed hook; suggest → the link row and the note, dismiss removes the row.
- **Feedback**: Yes once per IP per day (a second POST answers *"Thanks — you already told us."*), counts move, logged `portal`.
- **Slugs**: two articles titled *VPN access* → `vpn-access`, `vpn-access-2`; renaming keeps the slug unless edited; a slug clash on edit → 422.
- **Rights and JSON**: support → 403 on admin screens with *"You may not write articles."*; delete without `settings.manage` → 403; JSON mode on every handler; `bin/build_action_registry.php --check` OK; 375 px: the article's code blocks scroll inside, the page never sideways.

## Open questions
- A `kb_feedback` table (per vote, with IP hash and day) instead of counts only — an additive migration if the owner wants the "who said no" question answered.
- Public articles indexable by search engines (a `robots` meta) — proposed: `noindex` until the owner says otherwise.
