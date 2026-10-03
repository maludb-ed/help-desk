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

## Proof (`tests/phase3/slice9/run.sh`: the scratch database, slice 3's world, curl with no cookie as the visitor, members through dev hand-offs and action tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`)
**Built and proven 2026-10-03: 160 checks green** (visibility 17, markdown 13, ticket 15, send 14, feedback 12, screens 42, json 18, browser 28, the registry); Phase 2's and slices
1–8's suites still green (Phase 2's placeholder checks moved to Reports, the last placeholder; its vhost check now expects an unknown slug to be 404); the installer's `plan` clean.
- [x] **Visibility.** A visitor reads a public published article bare (no sidebar, no command bar) and gets 404 *"Article not found."* — the same sentence — for an internal one, a draft and
  an archived one; the public home lists the public section only; a visitor's search never finds the internal article's word, finds the public one, and says *"Nothing matches 'x'. Try another
  word, or file a request."*; the internal section is 404 to a visitor; Priya (`kb.read`) reads internal in the shell; a draft and an archived one are 404 to readers; the external member sees
  the public one bare and the internal one as 404; search never returns a draft, not even to an editor; Lena (`kb.edit`) opens the draft with Edit and the desk's Drafts and Archived tabs list
  them; the public pages leak no address and no token.
- [x] **Markdown.** A script tag, HTML with handlers, `javascript:` and `data:` links render as escaped text; `#`/`##`/`###` → h2/h3/h4; bold, italic, code; both lists; a blockquote, a fenced
  block (escaped inside) and a rule; http and mailto links with `rel="noopener"`; an image only from `/kb/attachment.php?id=`; `body_text` is the source, `body_html` the cached render;
  the Preview tab renders the same HTML and saves nothing; the public page shows the raw tag as text.
- [x] **From a ticket.** `?from_ticket=HD-1001` prefills the title from the subject and the body from the public replies only (the fixture's note is absent), authors by first name; saving
  writes `article_links (created_from)`, a note naming the article on the ticket and `article.create` with the ticket and `from_ticket`.
- [x] **Suggest and send.** Suggest → the `suggested` row, the note with the admin link, `article.suggest`; the ticket page shows the suggestion with Send and Dismiss; the picker searches and
  offers both; dismiss removes the row; a requester who may not see the ticket gets 404, a collaborator 403; an internal article may be suggested. Send: an internal article to an external
  requester → 422 *"That article is internal."*; a draft cannot be sent; a public one → a public reply by the sender = the words, the title, the absolute link and the 300-character summary cut
  at a word, mailed to the requester (the outbox), `ticket.reply` + `article.send` (ticket and queue on the row), `article_links (sent)`, the first response met; an internal article goes to a
  member requester; the registry marks `article_send` external_send and `article_delete` deletion (the kernel's hook pauses an agent by key); delete while sent → 422 *"This article was
  sent on 1 ticket — archive it instead."*; archived instead: gone from the public.
- [x] **Feedback and views.** Two visits in one session count one view; an editor's visit never counts; a member's counts once. Yes: counted, back to the article with thanks, the thanks line
  replaces the buttons, `article.feedback` with source portal and nobody as actor; a second vote the same day → *"Thanks — you already told us."*, nothing counted; another visitor's No counts;
  no CSRF → 403; a vote on an article the visitor may not read → 404.
- [x] **Rights, slugs, sections, publishing, attachments.** Support → 403 *"You may not write articles."* on the desk, the form and every handler; delete without `settings.manage` → 403;
  two articles titled the same → `smoke-slug-test`, `smoke-slug-test-2`; renaming keeps the slug; a clash on edit → 422 *"That address is taken."*; a malformed slug → 422; an edited slug is
  honoured; validation by field (title, visibility, section). A section and a child (`section.save` twice); a child under a child → 422; inline edit keeps what was not sent; the home nests the
  child; the section page lists under the parent's crumb; archiving a section leaves its articles under No section (reachable by slug; the section page gone; the desk filters No section).
  A draft has no `published_at`; publish sets it and `article.publish` logs; unpublish keeps it; "Save and publish" on a draft publishes without resetting it; an editor sees Edit · Unpublish ·
  Archive. `attachment_add` with `article` stores on the article and answers the url; a visitor fetches a public article's file; made internal, the file is 404 to a visitor and served to
  `kb.read`; support may not attach; the edit page lists the file with its Markdown; delete removes the article, its file and logs `article.delete`.
- [x] **JSON mode.** `section_save`, `article_create` (`{ok, record_id, slug, location, url}`), `article_update` keeps what it was not sent, 422 by field, `article_publish` (`{ok, published}`,
  left out = yes), `article_suggest`, `article_send` (record_id = the ticket, as `ticket_reply` answers), support → 403, `article_archive`, `article_delete`, `section_archive`; the screens
  kb-home (sections, results, may.edit), kb-article with the body, kb-admin, article-edit, article-add with `from_ticket`, kb-section; ticket-view's JSON carries the linked articles.
- [x] **375 × 740 and 1280 × 800.** A visitor's article: bare, the long code line scrolls inside its block, no sideways scroll; the public home's search is a plain form (Enter lists the
  match — the bare layout has no HTMX); in the shell typing searches as you type and pushes `?q=`; the desk, the form's Preview tab rendering in place, the ticket's article picker; no console errors.

**Decisions taken while building (2026-10-03):** the base tables are read everywhere through `kb_visible_filter()` (a visitor has no member and the views are empty to them); an
external member is a visitor to the knowledge base (public only, the bare layout); an archived article is 404 to everyone but an editor; `article_update` only moves TO published
(unpublishing is `article_publish`); `published_at` is set once and kept; the vote and the view are remembered in PHP's session (no table — the open question stands); a visitor's 404
for a section is *"Section not found."*; the dashboard's Knowledge base card lists the five newest articles the caller may read; an article's file is uploaded through slice 3's
`attachment_add` with `article` and served by `/kb/attachment.php` under the article's own visibility; the first-name rule takes the first word of a display name; `record_view()` never
counts an editor; `record_id` of `article_send` is the ticket, as every ticket action answers.

## Open questions
- A `kb_feedback` table (per vote, with IP hash and day) instead of counts only — an additive migration if the owner wants the "who said no" question answered.
- Public articles indexable by search engines (a `robots` meta) — proposed: `noindex` until the owner says otherwise.
