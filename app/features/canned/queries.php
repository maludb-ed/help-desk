<?php
declare(strict_types=1);

/** Canned responses (slice 5): reads through mcp_canned_responses (global, mine, my queues' — tickets.work), writes to canned_responses. */

const CANNED_SCOPES = ['personal' => 'Personal', 'queue' => 'Queue', 'global' => 'Everyone'];
const CANNED_SELECT = 'SELECT c.*, q.name AS queue_name, m.display_name AS owner_name FROM mcp_canned_responses c LEFT JOIN queues q ON q.id = c.queue_id LEFT JOIN members m ON m.id = c.owner_member_id';

function canned_cast(array $c): array
{
    foreach (['canned_id', 'owner_member_id', 'queue_id', 'usage_count'] as $k) {
        if (array_key_exists($k, $c)) {
            $c[$k] = $c[$k] === null ? null : (int) $c[$k];
        }
    }
    $se = is_array($c['side_effects'] ?? null) ? $c['side_effects'] : (json_decode((string) ($c['side_effects'] ?? '{}'), true) ?: []);
    $c['side_effects'] = array_filter(['status' => $se['status'] ?? null, 'priority' => $se['priority'] ?? null, 'assign' => $se['assign'] ?? null, 'add_tags' => array_values(array_filter(array_map('strval', (array) ($se['add_tags'] ?? []))))], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    return $c;
}

/** The responses I may use: by words in the title or body, by folder; global first, then by folder and title. */
function find_canned(PDO $pdo, int $memberId, ?string $q = null, ?string $folder = null, int $limit = 100): array
{
    $where = [];
    $args = [];
    if ($q !== null && $q !== '') {
        $where[] = '(c.title ILIKE :q OR c.body_text ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    }
    if ($folder !== null && $folder !== '') {
        $where[] = 'c.folder = :folder';
        $args['folder'] = $folder;
    }
    $st = $pdo->prepare(CANNED_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY lower(coalesce(c.folder, \'\')), lower(c.title), c.canned_id LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map('canned_cast', $st->fetchAll());
}

function find_canned_one(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(CANNED_SELECT . ' WHERE c.canned_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : canned_cast($r);
}

/** The folders among the responses I may use. */
function canned_folders(PDO $pdo, int $memberId): array
{
    return $pdo->query("SELECT DISTINCT folder FROM mcp_canned_responses WHERE folder IS NOT NULL AND folder <> '' ORDER BY folder")->fetchAll(PDO::FETCH_COLUMN);
}

/** May the caller change or delete it? Own, or tickets.manage for any (a lead). */
function canned_may_change(array $c): bool
{
    return ($c['scope'] === 'personal' && $c['owner_member_id'] === (int) current_member_id()) || has_right('tickets.manage');
}

/** Create or change. $f: title, folder, body_text, scope, queue_id, side_effects (validated), owner_member_id. */
function save_canned(PDO $pdo, ?int $id, array $f, int $by): int
{
    $row = ['title' => $f['title'], 'folder' => $f['folder'] === '' ? null : $f['folder'], 'body_text' => $f['body_text'], 'body_html' => body_to_html($f['body_text']), 'scope' => $f['scope'],
            'owner_member_id' => $f['scope'] === 'personal' ? ($f['owner_member_id'] ?? $by) : null, 'queue_id' => $f['scope'] === 'queue' ? $f['queue_id'] : null, 'side_effects' => json_encode((object) $f['side_effects'])];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO canned_responses (title, folder, body_text, body_html, scope, owner_member_id, queue_id, side_effects, created_by) VALUES (:title, :folder, :body_text, :body_html, :scope, :owner_member_id, :queue_id, CAST(:side_effects AS jsonb), :by) RETURNING id');
        $st->execute($row + ['by' => $by]);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE canned_responses SET title = :title, folder = :folder, body_text = :body_text, body_html = :body_html, scope = :scope, owner_member_id = :owner_member_id, queue_id = :queue_id, side_effects = CAST(:side_effects AS jsonb) WHERE id = :id')->execute($row + ['id' => $id]);
    return $id;
}

function delete_canned(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('UPDATE canned_responses SET archived_at = now() WHERE id = :id AND archived_at IS NULL')->execute(['id' => $id]);
}

function bump_canned_usage(PDO $pdo, int $id): void
{
    $pdo->prepare('UPDATE canned_responses SET usage_count = usage_count + 1 WHERE id = :id')->execute(['id' => $id]);
}

/** What a log row records — never the body. */
function canned_log_state(array $c): array
{
    return ['title' => $c['title'], 'scope' => $c['scope'], 'queue_id' => $c['queue_id'], 'folder' => $c['folder'], 'side_effects' => array_keys($c['side_effects'])];
}
