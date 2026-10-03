<?php
declare(strict_types=1);

/** Saved views (slice 4): reads through mcp_saved_views (global, mine, my queues'), writes to saved_views. A view the caller may not see does not exist to them. */
require_once __DIR__ . '/filters.php';

function view_cast(array $v): array
{
    foreach (['view_id', 'owner_member_id', 'queue_id', 'sort_order'] as $k) {
        if (array_key_exists($k, $v)) {
            $v[$k] = $v[$k] === null ? null : (int) $v[$k];
        }
    }
    $v['filters'] = is_array($v['filters'] ?? null) ? $v['filters'] : (json_decode((string) ($v['filters'] ?? '{}'), true) ?: []);
    $v['board'] = board_settings($v);
    $v['columns'] = is_array($v['columns'] ?? null) ? $v['columns'] : pg_text_array((string) ($v['columns'] ?? '{}'));
    $v['is_system'] = (bool) ($v['is_system'] ?? false);
    return $v;
}

const VIEW_SELECT = 'SELECT v.*, q.name AS queue_name, m.display_name AS owner_name FROM mcp_saved_views v LEFT JOIN queues q ON q.id = v.queue_id LEFT JOIN members m ON m.id = v.owner_member_id';

/** The views I may open, standard first then by name. $layout narrows to list or board. */
function find_views(PDO $pdo, int $memberId, ?string $q = null, ?string $layout = null, int $limit = 50): array
{
    $where = [];
    $args = [];
    if ($q !== null && $q !== '') {
        $where[] = 'v.name ILIKE :q';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    }
    if ($layout !== null && isset(VIEW_LAYOUTS[$layout])) {
        $where[] = 'v.layout = :layout';
        $args['layout'] = $layout;
    }
    $st = $pdo->prepare(VIEW_SELECT . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)) . ' ORDER BY v.is_system DESC, v.sort_order, lower(v.name), v.view_id LIMIT ' . (int) $limit);
    $st->execute($args);
    return array_map('view_cast', $st->fetchAll());
}

function find_view(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(VIEW_SELECT . ' WHERE v.view_id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    return $r === false ? null : view_cast($r);
}

function find_view_by_key(PDO $pdo, string $systemKey): ?array
{
    $st = $pdo->prepare(VIEW_SELECT . ' WHERE v.system_key = :k');
    $st->execute(['k' => $systemKey]);
    $r = $st->fetch();
    return $r === false ? null : view_cast($r);
}

/** The view a request names: an id, a system key, else null. */
function view_from_param(PDO $pdo, string $param): ?array
{
    if ($param === '') {
        return null;
    }
    return filter_var($param, FILTER_VALIDATE_INT) !== false ? find_view($pdo, (int) $param) : find_view_by_key($pdo, $param);
}

/** How many tickets the view holds for the caller (through the translator). */
function view_count(PDO $pdo, array $view, int $memberId): int
{
    [$where, $args] = view_filters_sql($view['filters'], $memberId);
    $st = $pdo->prepare('SELECT count(*) FROM mcp_tickets t WHERE ' . $where);
    $st->execute($args);
    return (int) $st->fetchColumn();
}

/** May the caller change or delete this view? Own, or a shared one with views.shared; a standard one never. */
function view_may_change(array $view): bool
{
    if ($view['is_system']) {
        return false;
    }
    if ($view['scope'] === 'personal') {
        return $view['owner_member_id'] === (int) current_member_id();
    }
    return has_right('views.shared');
}

/** Create or change a view. $f: name, scope, queue_id, filters (normalised), sort_by, sort_dir, columns, layout, board. */
function save_view(PDO $pdo, ?int $id, array $f, int $by): int
{
    $row = ['name' => $f['name'], 'scope' => $f['scope'], 'owner_member_id' => $f['scope'] === 'personal' ? ($f['owner_member_id'] ?? $by) : null, 'queue_id' => $f['scope'] === 'queue' ? $f['queue_id'] : null,
            'filters' => json_encode((object) $f['filters']), 'sort_by' => $f['sort_by'], 'sort_dir' => $f['sort_dir'], 'columns' => pg_array_literal(array_values($f['columns'])), 'layout' => $f['layout'],
            'board' => json_encode(['column_field' => $f['board']['column_field'], 'swimlane' => $f['board']['swimlane'], 'wip' => (object) $f['board']['wip'], 'solved_days' => $f['board']['solved_days']])];
    if ($id === null) {
        $st = $pdo->prepare('INSERT INTO saved_views (name, scope, owner_member_id, queue_id, filters, sort_by, sort_dir, columns, layout, board) VALUES (:name, :scope, :owner_member_id, :queue_id, CAST(:filters AS jsonb), :sort_by, :sort_dir, CAST(:columns AS text[]), :layout, CAST(:board AS jsonb)) RETURNING id');
        $st->execute($row);
        return (int) $st->fetchColumn();
    }
    $pdo->prepare('UPDATE saved_views SET name = :name, scope = :scope, owner_member_id = :owner_member_id, queue_id = :queue_id, filters = CAST(:filters AS jsonb), sort_by = :sort_by, sort_dir = :sort_dir, columns = CAST(:columns AS text[]), layout = :layout, board = CAST(:board AS jsonb) WHERE id = :id AND NOT is_system')->execute($row + ['id' => $id]);
    return $id;
}

/** Remove a view (never a standard one — the caller checked). */
function delete_view(PDO $pdo, int $id, int $by): void
{
    $pdo->prepare('DELETE FROM saved_views WHERE id = :id AND NOT is_system')->execute(['id' => $id]);
}

/** The state a log row records for a view. */
function view_log_state(array $v): array
{
    return ['name' => $v['name'], 'scope' => $v['scope'], 'queue_id' => $v['queue_id'], 'layout' => $v['layout'], 'filter_keys' => array_keys($v['filters']), 'sort_by' => $v['sort_by'], 'sort_dir' => $v['sort_dir'],
            'columns' => array_values($v['columns']), 'board' => $v['board']];
}
