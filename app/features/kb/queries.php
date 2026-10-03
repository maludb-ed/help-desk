<?php
declare(strict_types=1);

/** The knowledge base (slice 9): one visibility rule in kb_visible_filter(); the base tables (a visitor has no member, the views are empty to them) narrowed by that filter everywhere. */
require_once __DIR__ . '/markdown.php';

const ARTICLE_STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];
const KB_VISIBILITIES = ['public' => 'Public — anyone with the link', 'internal' => 'Internal — members only'];

/** ['visibility' => [...], 'drafts' => bool, 'member' => bool]: a visitor or an external sees public published; kb.read adds internal; kb.edit adds drafts and archived (admin). */
function kb_visible_filter(?array $member): array
{
    if ($member === null || !empty($member['is_external'])) {
        return ['visibility' => ['public'], 'drafts' => false, 'member' => false];
    }
    return ['visibility' => has_right('kb.read') ? ['public', 'internal'] : ['public'], 'drafts' => has_right('kb.edit'), 'member' => true];
}

function kb_filter_sql(array $filter, string $alias = 'a', bool $publishedOnly = true): array
{
    $where = [$alias . '.visibility = ANY (CAST(:vis AS text[]))'];
    $args = ['vis' => pg_array_literal($filter['visibility'])];
    if ($publishedOnly || !$filter['drafts']) {
        $where[] = $alias . ".status = 'published'";
    }
    return [implode(' AND ', $where), $args];
}

function section_cast(array $s): array
{
    foreach (['section_id', 'parent_id', 'sort_order', 'article_count'] as $k) {
        if (array_key_exists($k, $s)) {
            $s[$k] = $s[$k] === null ? null : (int) $s[$k];
        }
    }
    return $s;
}

function article_cast(array $a): array
{
    foreach (['article_id', 'section_id', 'author_member_id', 'view_count', 'helpful_count', 'unhelpful_count'] as $k) {
        if (array_key_exists($k, $a)) {
            $a[$k] = $a[$k] === null ? null : (int) $a[$k];
        }
    }
    $a['tags'] = is_array($a['tags'] ?? null) ? $a['tags'] : pg_text_array((string) ($a['tags'] ?? '{}'));
    return $a;
}

/** The live sections the filter admits, with the published article count each (nested one level by parent_id in the caller's order). */
function find_sections(PDO $pdo, array $filter, bool $withCounts = true): array
{
    [$w, $args] = kb_filter_sql($filter, 'a');
    $st = $pdo->prepare("SELECT k.id AS section_id, k.parent_id, k.name, k.slug, k.description, k.visibility, k.sort_order,
                                (SELECT count(*) FROM articles a WHERE a.section_id = k.id AND $w) AS article_count
                           FROM kb_sections k WHERE k.archived_at IS NULL AND k.visibility = ANY (CAST(:vis AS text[])) ORDER BY k.sort_order, lower(k.name), k.id");
    $st->execute($args);
    return array_map('section_cast', $st->fetchAll());
}

function find_section_by_slug(PDO $pdo, string $slug, array $filter): ?array
{
    foreach (find_sections($pdo, $filter) as $s) {
        if ($s['slug'] === $slug) {
            return $s;
        }
    }
    return null;
}

function find_section(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS section_id, parent_id, name, slug, description, visibility, sort_order, archived_at FROM kb_sections WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : section_cast($r);
}

const ARTICLE_SELECT = 'SELECT a.id AS article_id, a.section_id, k.name AS section_name, k.slug AS section_slug, a.title, a.slug, a.body_text, a.body_html, a.status, a.visibility, a.tags, a.author_member_id, m.display_name AS author_name,
                               a.view_count, a.helpful_count, a.unhelpful_count, a.published_at, a.created_at, a.updated_at FROM articles a LEFT JOIN kb_sections k ON k.id = a.section_id LEFT JOIN members m ON m.id = a.author_member_id';

/** Articles the filter admits; $status narrows for editors (draft|published|archived), else published only. 50 a page. */
function find_articles(PDO $pdo, array $filter, ?int $sectionId = null, ?string $status = null, int $page = 1): array
{
    $where = ['a.visibility = ANY (CAST(:vis AS text[]))'];
    $args = ['vis' => pg_array_literal($filter['visibility'])];
    if ($status !== null && $filter['drafts'] && isset(ARTICLE_STATUSES[$status])) {
        $where[] = 'a.status = :s';
        $args['s'] = $status;
    } else {
        $where[] = "a.status = 'published'";
    }
    if ($sectionId !== null) {
        $where[] = $sectionId === 0 ? 'a.section_id IS NULL' : 'a.section_id = :sec';
        if ($sectionId !== 0) {
            $args['sec'] = $sectionId;
        }
    }
    $st = $pdo->prepare(ARTICLE_SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.updated_at DESC, a.id DESC LIMIT 51 OFFSET ' . (max(1, $page) - 1) * 50);
    $st->execute($args);
    return array_map('article_cast', $st->fetchAll());
}

function find_article(PDO $pdo, int $id, array $filter): ?array
{
    [$w, $args] = kb_filter_sql($filter, 'a', false);
    $st = $pdo->prepare(ARTICLE_SELECT . ' WHERE a.id = :id AND ' . $w);
    $st->execute($args + ['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : article_cast($r);
}

function find_article_by_slug(PDO $pdo, string $slug, array $filter): ?array
{
    [$w, $args] = kb_filter_sql($filter, 'a', false);
    $st = $pdo->prepare(ARTICLE_SELECT . ' WHERE a.slug = :slug AND ' . $w);
    $st->execute($args + ['slug' => $slug]);
    $r = $st->fetch();
    return $r === false ? null : article_cast($r);
}

/** An editor's read of any article, whatever its status. */
function find_article_any(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(ARTICLE_SELECT . ' WHERE a.id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : article_cast($r);
}

/** "VPN access" → vpn-access, unique (-2, -3 on a clash); an edited slug validated by the caller. */
function article_slug(PDO $pdo, string $title, ?int $exceptId = null, ?string $wanted = null): string
{
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($wanted !== null && $wanted !== '' ? $wanted : $title))), '-'));
    $base = $base === '' || !preg_match('/^[a-z0-9]/', $base) ? 'article' . ($base !== '' ? '-' . ltrim($base, '-') : '') : $base;
    $base = mb_substr($base, 0, 110);
    $slug = $base;
    for ($i = 2; $i < 1000; $i++) {
        $st = $pdo->prepare('SELECT 1 FROM articles WHERE slug = :s AND id <> :id');
        $st->execute(['s' => $slug, 'id' => $exceptId ?? 0]);
        if ($st->fetchColumn() === false) {
            return $slug;
        }
        $slug = $base . '-' . $i;
    }
    return $base . '-' . bin2hex(random_bytes(3));
}

/** Create or change. $f: title, body_text, section_id, slug (wanted), visibility, tags, status?, from_ticket. Renders body_html; writes created_from when from_ticket. */
function save_article(PDO $pdo, ?int $id, array $f, int $by): int
{
    $slug = article_slug($pdo, $f['title'], $id, $f['slug'] ?? null);
    $row = ['title' => $f['title'], 'slug' => $slug, 'body_text' => $f['body_text'], 'body_html' => md_render($f['body_text']), 'section_id' => $f['section_id'], 'visibility' => $f['visibility'], 'tags' => pg_array_literal(array_values($f['tags']))];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO articles (title, slug, body_text, body_html, section_id, visibility, tags, author_member_id, status) VALUES (:title, :slug, :body_text, :body_html, :section_id, :visibility, CAST(:tags AS text[]), :by, :status) RETURNING id');
        $st->execute($row + ['by' => $by, 'status' => $f['status'] ?? 'draft']);
        $id = (int) $st->fetchColumn();
        if (($f['status'] ?? 'draft') === 'published') {
            $pdo->prepare('UPDATE articles SET published_at = now() WHERE id = :id')->execute(['id' => $id]);
        }
    } else {
        $pdo->prepare('UPDATE articles SET title = :title, slug = :slug, body_text = :body_text, body_html = :body_html, section_id = :section_id, visibility = :visibility, tags = CAST(:tags AS text[]) WHERE id = :id')->execute($row + ['id' => $id]);
    }
    if (!empty($f['from_ticket'])) {
        $pdo->prepare("INSERT INTO article_links (ticket_id, article_id, kind, created_by) VALUES (:t, :a, 'created_from', :by) ON CONFLICT DO NOTHING")->execute(['t' => (int) $f['from_ticket'], 'a' => $id, 'by' => $by]);
    }
    return $id;
}

/** published_at set once, kept on unpublish. */
function publish_article(PDO $pdo, int $id, bool $published, int $by): void
{
    $pdo->prepare("UPDATE articles SET status = :s, published_at = CASE WHEN :p AND published_at IS NULL THEN now() ELSE published_at END WHERE id = :id")->execute(['s' => $published ? 'published' : 'draft', 'p' => $published ? 't' : 'f', 'id' => $id]);
}

function archive_article(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare("UPDATE articles SET status = 'archived' WHERE id = :id")->execute(['id' => $id]);
}

function delete_article(PDO $pdo, int $id, int $by): void
{
    foreach ($pdo->query('SELECT storage_path FROM attachments WHERE article_id = ' . (int) $id)->fetchAll(PDO::FETCH_COLUMN) as $p) {
        @unlink(APP_ROOT . '/storage/' . $p);
    }
    $pdo->prepare('DELETE FROM articles WHERE id = :id')->execute(['id' => $id]);
}

function save_section(PDO $pdo, ?int $id, array $f, int $by): int
{
    $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($f['slug'] !== '' ? $f['slug'] : $f['name']))), '-'));
    $slug = $slug === '' ? 'section' : mb_substr($slug, 0, 80);
    $base = $slug;
    for ($i = 2; $i < 100; $i++) {
        $st = $pdo->prepare('SELECT 1 FROM kb_sections WHERE slug = :s AND id <> :id');
        $st->execute(['s' => $slug, 'id' => $id ?? 0]);
        if ($st->fetchColumn() === false) {
            break;
        }
        $slug = $base . '-' . $i;
    }
    $row = ['name' => $f['name'], 'slug' => $slug, 'parent_id' => $f['parent_id'], 'description' => $f['description'] === '' ? null : $f['description'], 'visibility' => $f['visibility'], 'sort_order' => $f['sort_order']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO kb_sections (name, slug, parent_id, description, visibility, sort_order) VALUES (:name, :slug, :parent_id, :description, :visibility, :sort_order) RETURNING id');
        $st->execute($row);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE kb_sections SET name = :name, slug = :slug, parent_id = :parent_id, description = :description, visibility = :visibility, sort_order = :sort_order WHERE id = :id')->execute($row + ['id' => $id]);
    return $id;
}

/** The section archived; its articles keep their rows with section_id NULL. */
function archive_section(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('UPDATE articles SET section_id = NULL WHERE section_id = :id')->execute(['id' => $id]);
    $pdo->prepare('UPDATE kb_sections SET archived_at = now(), parent_id = NULL WHERE id = :id')->execute(['id' => $id]);
    $pdo->prepare('UPDATE kb_sections SET parent_id = NULL WHERE parent_id = :id')->execute(['id' => $id]);
}

/** A title and a Markdown body from a ticket's public replies — requesters by first name, never a note. */
function article_from_ticket(PDO $pdo, int $ticketId): array
{
    $t = ticket_facts($pdo, $ticketId);
    if ($t === null) {
        return ['title' => '', 'body' => ''];
    }
    $st = $pdo->prepare("SELECT m.body_text, m.author_member_id, m.author_requester_id, mm.display_name AS member_name, mm.member_kind, rr.display_name AS requester_name
                           FROM messages m LEFT JOIN members mm ON mm.id = m.author_member_id LEFT JOIN requesters rr ON rr.id = m.author_requester_id WHERE m.ticket_id = :t AND m.kind = 'public_reply' ORDER BY m.id");
    $st->execute(['t' => $ticketId]);
    $parts = [];
    foreach ($st->fetchAll() as $m) {
        $who = $m['author_requester_id'] !== null ? trim((string) strtok((string) $m['requester_name'], ' ')) : ($m['member_kind'] === 'agent' ? (string) $m['member_name'] : trim((string) strtok((string) $m['member_name'], ' ')));
        $parts[] = '**' . ($who !== '' ? $who : 'Someone') . ':**' . "\n\n" . implode("\n", array_map(static fn (string $l): string => '> ' . $l, preg_split('/\r\n|\n/', trim((string) $m['body_text'])) ?: []));
    }
    return ['title' => $t['subject'], 'body' => "## The question\n\n" . implode("\n\n", $parts) . "\n\n## The answer\n\n"];
}

function link_article(PDO $pdo, int $ticketId, int $articleId, string $kind, ?int $by): void
{
    $pdo->prepare('INSERT INTO article_links (ticket_id, article_id, kind, created_by) VALUES (:t, :a, :k, :by) ON CONFLICT DO NOTHING')->execute(['t' => $ticketId, 'a' => $articleId, 'k' => $kind, 'by' => $by]);
}

function unlink_article(PDO $pdo, int $ticketId, int $articleId, string $kind): void
{
    $pdo->prepare('DELETE FROM article_links WHERE ticket_id = :t AND article_id = :a AND kind = :k')->execute(['t' => $ticketId, 'a' => $articleId, 'k' => $kind]);
}

/** The articles linked to a ticket, by kind, that the caller may read. */
function ticket_articles(PDO $pdo, int $ticketId, array $filter): array
{
    [$w, $args] = kb_filter_sql($filter, 'a', false);
    $st = $pdo->prepare('SELECT l.kind, l.created_at AS linked_at, a.id AS article_id, a.title, a.slug, a.visibility, a.status, a.body_text FROM article_links l JOIN articles a ON a.id = l.article_id WHERE l.ticket_id = :t AND ' . $w . ' ORDER BY l.created_at DESC');
    $st->execute($args + ['t' => $ticketId]);
    return array_map(static fn (array $r): array => ['kind' => $r['kind'], 'linked_at' => $r['linked_at'], 'article_id' => (int) $r['article_id'], 'title' => $r['title'], 'slug' => $r['slug'], 'visibility' => $r['visibility'], 'status' => $r['status'], 'summary' => article_summary($r, 160)], $st->fetchAll());
}

/** A view counted once per viewer key (a session or an IP) — never an editor's own. */
function record_view(PDO $pdo, int $articleId, string $viewerKey): void
{
    $seen = $_SESSION['kb_viewed'] ?? [];
    $key = $articleId . ':' . $viewerKey;
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = time();
    if (count($seen) > 200) {
        $seen = array_slice($seen, -200, null, true);
    }
    $_SESSION['kb_viewed'] = $seen;
    $pdo->prepare('UPDATE articles SET view_count = view_count + 1 WHERE id = :id')->execute(['id' => $articleId]);
}

/** One vote per viewer per article per day (the anonymous session remembers — the counts are increment-only). False when already voted today. */
function record_feedback(PDO $pdo, int $articleId, bool $helpful, string $ip): bool
{
    $votes = $_SESSION['kb_votes'] ?? [];
    $key = $articleId . ':' . date('Y-m-d');
    if (isset($votes[$key])) {
        return false;
    }
    $votes[$key] = $helpful ? 'yes' : 'no';
    $_SESSION['kb_votes'] = $votes;
    $pdo->prepare('UPDATE articles SET ' . ($helpful ? 'helpful_count = helpful_count + 1' : 'unhelpful_count = unhelpful_count + 1') . ' WHERE id = :id')->execute(['id' => $articleId]);
    return true;
}

/** An article's attachments: [{attachment_id, filename, byte_size, mime_type}]. */
function article_attachments(PDO $pdo, int $articleId): array
{
    $st = $pdo->prepare('SELECT id AS attachment_id, filename, byte_size, mime_type FROM attachments WHERE article_id = :a ORDER BY id');
    $st->execute(['a' => $articleId]);
    return array_map(static fn (array $r): array => ['attachment_id' => (int) $r['attachment_id'], 'filename' => $r['filename'], 'byte_size' => (int) $r['byte_size'], 'mime_type' => $r['mime_type']], $st->fetchAll());
}

/** Store an uploaded file on an article (the same checks as a ticket's: size, kind). */
function store_article_attachment(PDO $pdo, int $articleId, array $file, int $by): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException('The upload did not arrive.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size > attachment_max_bytes()) {
        throw new DomainException('A file is at most ' . intdiv(attachment_max_bytes(), 1024) . ' KB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $name = preg_replace('/[^\w.\- ()]+/u', '_', basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')))) ?: 'file';
    if (!in_array($mime, ATTACHMENT_MIME_ALLOW, true) || preg_match('/\.(exe|bat|cmd|com|msi|scr|ps1|sh|js|vbs|jar|dll)$/i', $name)) {
        throw new DomainException('That kind of file is not accepted (images, PDFs, text, office documents and zip are).');
    }
    $sha = hash_file('sha256', $tmp);
    $st = $pdo->prepare("INSERT INTO attachments (article_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by_member_id) VALUES (:a, :n, :mt, :s, :h, 'pending', :by) RETURNING id");
    $st->execute(['a' => $articleId, 'n' => $name, 'mt' => $mime, 's' => $size, 'h' => $sha, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $dir = APP_ROOT . '/storage/attachments/articles/' . $articleId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new DomainException('The attachment store is not writable.');
    }
    $path = 'attachments/articles/' . $articleId . '/' . $id . '-' . substr($sha, 0, 12);
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, APP_ROOT . '/storage/' . $path) : rename($tmp, APP_ROOT . '/storage/' . $path))) {
        throw new DomainException('The file could not be stored.');
    }
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $path, 'id' => $id]);
    return $id;
}

function article_log_state(array $a): array
{
    return ['title' => $a['title'], 'slug' => $a['slug'], 'section_id' => $a['section_id'], 'visibility' => $a['visibility'], 'tags' => array_values($a['tags']), 'status' => $a['status']];
}
