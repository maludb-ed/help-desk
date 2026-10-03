<?php
declare(strict_types=1);

/** Types, the categories tree and tags (slice 2). Reads through mcp_ticket_types / mcp_categories / mcp_tags, writes to the base tables. */

const TICKET_PRIORITIES = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

// ---- types ------------------------------------------------------------------------------------------------------------
function find_types(PDO $pdo, bool $includeArchived = false): array
{
    $st = $pdo->prepare('SELECT y.type_id, y.key, y.label, y.description, y.sort_order, y.is_system, y.archived_at,
                                (SELECT count(*) FROM tickets t WHERE t.type_id = y.type_id) AS ticket_count,
                                (SELECT count(*) FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.type_id = y.type_id AND s.category NOT IN (\'solved\', \'closed\')) AS open_count
                           FROM mcp_ticket_types y' . ($includeArchived ? '' : ' WHERE y.archived_at IS NULL') . ' ORDER BY y.sort_order, y.type_id');
    $st->execute();
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        foreach (['type_id', 'sort_order', 'ticket_count', 'open_count'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['is_system'] = (bool) $r['is_system'];
    }
    unset($r);
    return $rows;
}

function find_type(PDO $pdo, int $id): ?array
{
    foreach (find_types($pdo, true) as $t) {
        if ($t['type_id'] === $id) {
            return $t;
        }
    }
    return null;
}

/** Add ($id null) or change a type. $f: label, description, sort_order. */
function save_type(PDO $pdo, ?int $id, array $f): int
{
    $dupe = $pdo->prepare('SELECT 1 FROM ticket_types WHERE lower(label) = lower(:l) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['l' => $f['label'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A type labelled ' . $f['label'] . ' already exists.');
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO ticket_types (key, label, description, sort_order) VALUES (:k, :l, :d, :o) RETURNING id');
        $st->execute(['k' => derive_key($pdo, 'ticket_types', $f['label']), 'l' => $f['label'], 'd' => $f['description'], 'o' => $f['sort_order']]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE ticket_types SET label = :l, description = :d, sort_order = :o WHERE id = :id')->execute(['l' => $f['label'], 'd' => $f['description'], 'o' => $f['sort_order'], 'id' => $id]);
    return $id;
}

function archive_type(PDO $pdo, int $id): void
{
    $t = find_type($pdo, $id) ?? throw new DomainException('Not found.');
    if ($t['is_system']) {
        throw new DomainException('A built-in type is renamed, never archived.');
    }
    if ($t['archived_at'] !== null) {
        throw new DomainException($t['label'] . ' is already archived.');
    }
    if ($t['open_count'] > 0) {
        throw new DomainException($t['open_count'] . ' open ticket' . ($t['open_count'] === 1 ? ' is' : 's are') . ' a ' . $t['label'] . ' — change ' . ($t['open_count'] === 1 ? 'it' : 'them') . ' first.');
    }
    $pdo->prepare('UPDATE ticket_types SET archived_at = now() WHERE id = :id')->execute(['id' => $id]);
}

// ---- categories --------------------------------------------------------------------------------------------------------
/** The tree, depth-first in sort order, each row with depth, path, the defaults' names and a ticket count. */
function find_category_tree(PDO $pdo, bool $includeArchived = false): array
{
    $st = $pdo->prepare('SELECT c.category_id, c.parent_id, c.name, c.description, c.default_queue_id, q.name AS default_queue_name, c.default_type_id, y.label AS default_type_label,
                                c.default_priority, c.sla_policy_id, p.name AS policy_name, c.requester_visible, c.sort_order, c.archived_at, c.path,
                                (SELECT count(*) FROM tickets t WHERE t.category_id = c.category_id) AS ticket_count
                           FROM mcp_categories c LEFT JOIN queues q ON q.id = c.default_queue_id LEFT JOIN ticket_types y ON y.id = c.default_type_id LEFT JOIN sla_policies p ON p.id = c.sla_policy_id'
                        . ($includeArchived ? '' : ' WHERE c.archived_at IS NULL') . ' ORDER BY c.sort_order, lower(c.name), c.category_id');
    $st->execute();
    $byParent = [];
    foreach ($st->fetchAll() as $r) {
        foreach (['category_id', 'parent_id', 'default_queue_id', 'default_type_id', 'sla_policy_id', 'sort_order', 'ticket_count'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        $r['requester_visible'] = (bool) $r['requester_visible'];
        $byParent[$r['parent_id'] ?? 0][] = $r;
    }
    $out = [];
    $walk = static function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
        foreach ($byParent[$parent] ?? [] as $r) {
            $r['depth'] = $depth;
            $out[] = $r;
            $walk($r['category_id'], $depth + 1);
        }
    };
    $walk(0, 0);
    // a live child of an archived parent (never made by this slice, but possible by hand) hangs off the root
    $seen = array_column($out, 'category_id');
    foreach ($byParent as $parent => $rows) {
        if ($parent !== 0 && !in_array($parent, $seen, true)) {
            foreach ($rows as $r) {
                if (!in_array($r['category_id'], $seen, true)) {
                    $r['depth'] = 0;
                    $out[] = $r;
                }
            }
        }
    }
    return $out;
}

function find_category(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS category_id, parent_id, name, description, default_queue_id, default_type_id, default_priority, sla_policy_id, requester_visible, sort_order, archived_at FROM categories WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    foreach (['category_id', 'parent_id', 'default_queue_id', 'default_type_id', 'sla_policy_id', 'sort_order'] as $k) {
        $r[$k] = $r[$k] === null ? null : (int) $r[$k];
    }
    $r['requester_visible'] = (bool) $r['requester_visible'];
    return $r;
}

function category_log_state(array $c): array
{
    return array_intersect_key($c, array_flip(['name', 'parent_id', 'description', 'default_queue_id', 'default_type_id', 'default_priority', 'sla_policy_id', 'requester_visible', 'sort_order']));
}

/** The ids of a category and everything under it. */
function category_descendant_ids(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('WITH RECURSIVE down AS (SELECT id FROM categories WHERE id = :id UNION ALL SELECT c.id FROM categories c JOIN down ON c.parent_id = down.id) SELECT id FROM down');
    $st->execute(['id' => $id]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Add ($id null) or change a category. $f: name, parent_id, description, default_queue_id, default_type_id, default_priority, sla_policy_id, requester_visible, sort_order. A cycle is refused. */
function save_category(PDO $pdo, ?int $id, array $f): int
{
    if ($f['parent_id'] !== null) {
        $p = find_category($pdo, $f['parent_id']) ?? throw new DomainException('That parent category is not here.');
        if ($p['archived_at'] !== null) {
            throw new DomainException('That parent category is archived.');
        }
        if ($id !== null && in_array($f['parent_id'], category_descendant_ids($pdo, $id), true)) {
            throw new DomainException('A category cannot sit under itself.');
        }
    }
    $dupe = $pdo->prepare('SELECT 1 FROM categories WHERE COALESCE(parent_id, 0) = COALESCE(:p, 0) AND lower(name) = lower(:n) AND archived_at IS NULL AND id IS DISTINCT FROM :id');
    $dupe->execute(['p' => $f['parent_id'], 'n' => $f['name'], 'id' => $id]);
    if ($dupe->fetchColumn() !== false) {
        throw new DomainException('A category named ' . $f['name'] . ' already sits there.');
    }
    $args = ['n' => $f['name'], 'p' => $f['parent_id'], 'd' => $f['description'], 'q' => $f['default_queue_id'], 't' => $f['default_type_id'], 'pr' => $f['default_priority'], 'pol' => $f['sla_policy_id'], 'v' => $f['requester_visible'] ? 't' : 'f', 'o' => $f['sort_order']];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO categories (name, parent_id, description, default_queue_id, default_type_id, default_priority, sla_policy_id, requester_visible, sort_order) VALUES (:n, :p, :d, :q, :t, :pr, :pol, :v, :o) RETURNING id');
        $st->execute($args);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE categories SET name = :n, parent_id = :p, description = :d, default_queue_id = :q, default_type_id = :t, default_priority = :pr, sla_policy_id = :pol, requester_visible = :v, sort_order = :o WHERE id = :id')->execute($args + ['id' => $id]);
    return $id;
}

/** Archive a category and everything under it (tickets keep their category_id; the view still names it). Answers the ids archived. */
function archive_category(PDO $pdo, int $id): array
{
    $c = find_category($pdo, $id) ?? throw new DomainException('Not found.');
    if ($c['archived_at'] !== null) {
        throw new DomainException($c['name'] . ' is already archived.');
    }
    $ids = category_descendant_ids($pdo, $id);
    $pdo->prepare('UPDATE categories SET archived_at = now() WHERE id = ANY (CAST(:ids AS bigint[])) AND archived_at IS NULL')->execute(['ids' => '{' . implode(',', $ids) . '}']);
    return $ids;
}

// ---- tags -------------------------------------------------------------------------------------------------------------
function slugify_tag(string $text): string
{
    $s = strtolower(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)) ?? '', '-'));
    return substr($s, 0, 50);
}

/** The tags with their live usage (ticket_tags), most used first. */
function find_tags(PDO $pdo, ?string $q = null): array
{
    $st = $pdo->prepare('SELECT g.tag_id, g.slug, g.label, (SELECT count(*) FROM ticket_tags tt WHERE tt.tag_id = g.tag_id) AS usage FROM mcp_tags g'
        . ($q !== null && $q !== '' ? ' WHERE g.label ILIKE :q OR g.slug ILIKE :q' : '') . ' ORDER BY usage DESC, lower(g.label)');
    $st->execute($q !== null && $q !== '' ? ['q' => '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%'] : []);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['tag_id'] = (int) $r['tag_id'];
        $r['usage'] = (int) $r['usage'];
    }
    unset($r);
    return $rows;
}

function find_tag(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id AS tag_id, slug, label, (SELECT count(*) FROM ticket_tags tt WHERE tt.tag_id = tags.id) AS usage FROM tags WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        return null;
    }
    $r['tag_id'] = (int) $r['tag_id'];
    $r['usage'] = (int) $r['usage'];
    return $r;
}

/** Add ($id null) or rename a tag; the slug is derived from the label when null; unique. */
function save_tag(PDO $pdo, ?int $id, string $label, ?string $slug): int
{
    $slug = $slug === null || $slug === '' ? slugify_tag($label) : strtolower($slug);
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $slug)) {
        throw new DomainException('A slug is letters, digits, dashes and underscores (up to 50).');
    }
    $dupe = $pdo->prepare('SELECT label FROM tags WHERE slug = :s AND id IS DISTINCT FROM :id');
    $dupe->execute(['s' => $slug, 'id' => $id]);
    $other = $dupe->fetchColumn();
    if ($other !== false) {
        throw new DomainException('The slug ' . $slug . ' is already ' . $other . '\'s.');
    }
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO tags (slug, label) VALUES (:s, :l) RETURNING id');
        $st->execute(['s' => $slug, 'l' => $label]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE tags SET slug = :s, label = :l WHERE id = :id')->execute(['s' => $slug, 'l' => $label, 'id' => $id]);
    return $id;
}

/** Delete a tag: every ticket loses it. Answers how many did. */
function delete_tag(PDO $pdo, int $id): int
{
    $st = $pdo->prepare('DELETE FROM ticket_tags WHERE tag_id = :id');
    $st->execute(['id' => $id]);
    $n = $st->rowCount();
    $d = $pdo->prepare('DELETE FROM tags WHERE id = :id');
    $d->execute(['id' => $id]);
    if ($d->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
    return $n;
}

/** The tag a slug or a label names, made when missing ("Hard Ware" → hard-ware). Slice 3 calls it. */
function tag_by_slug_or_create(PDO $pdo, string $slugOrLabel): int
{
    $slug = slugify_tag($slugOrLabel);
    if ($slug === '') {
        throw new DomainException('A tag needs a name.');
    }
    $st = $pdo->prepare('SELECT id FROM tags WHERE slug = :s');
    $st->execute(['s' => $slug]);
    $id = $st->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    $ins = $pdo->prepare('INSERT INTO tags (slug, label) VALUES (:s, :l) ON CONFLICT (slug) DO UPDATE SET label = tags.label RETURNING id');
    $ins->execute(['s' => $slug, 'l' => trim($slugOrLabel)]);
    return (int) $ins->fetchColumn();
}
