<?php
declare(strict_types=1);
/**
 * Actions `view_create` / `view_update` (by the presence of `view`; log `view.create` / `view.update`: scope, layout, the filter keys, queue_id): personal → views.personal; queue or global → views.shared
 * ("You may not change shared views.") and the queue held; a standard view → 422 "A standard view cannot be changed — save a copy." (copy=yes creates from it); filters as JSON (an agent) or
 * filters[key] (the form) through view_filters_validate() — 422 by key, an unknown key refused by name. Lands on /views/{id}/edit.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('view') ?? request_integer('view_id');
$cur = $id === null ? null : (find_view($pdo, $id) ?? refuse(404, 'View not found.'));
$base = null;
if ($cur !== null && $cur['is_system']) {
    if (!hd_yes('copy', false)) {
        refuse(422, 'A standard view cannot be changed — save a copy.');
    }
    $base = $cur;
    $cur = null;
    $id = null;
}
if ($cur !== null && !view_may_change($cur)) {
    refuse(403, $cur['scope'] === 'personal' ? 'That view is not yours.' : 'You may not change shared views.');
}
$prior = $cur ?? $base;
$errors = [];
$scope = req_has('scope') ? (string) req_val('scope') : ($cur['scope'] ?? 'personal');
if (!isset(VIEW_SCOPES[$scope])) {
    $errors['scope'] = 'The scope is personal, queue or global.';
    $scope = 'personal';
}
if ($scope !== 'personal') {
    if (!has_right('views.shared')) {
        refuse(403, 'You may not change shared views.');
    }
} else {
    require_right('views.personal');
}
$queueId = null;
if ($scope === 'queue') {
    $queueId = req_has('queue') ? (int) req_val('queue') : ($cur['queue_id'] ?? null);
    $held = array_map('intval', $pdo->query('SELECT * FROM hd_held_queue_ids()')->fetchAll(PDO::FETCH_COLUMN));            // a queue I hold — view_all alone does not make me its holder
    if ($queueId === null || !in_array($queueId, $held, true)) {
        $errors['queue'] = 'Choose a queue you hold.';
    }
}
$name = req_has('name') ? (string) req_val('name') : (string) ($prior['name'] ?? '');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Give the view a name of up to 80 characters.';
}
$filters = $prior['filters'] ?? [];
if (req_has('filters')) {
    $raw = $_POST['filters'];
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
        if (!is_array($raw)) {
            $errors['filters'] = 'filters is a JSON object over the filter vocabulary.';
            $raw = [];
        }
    } else {
        $raw = (array) $raw;
        if (isset($raw['tags']) && is_string($raw['tags'])) {
            $raw['tags'] = array_values(array_filter(array_map('trim', explode(',', $raw['tags']))));
        }
        foreach ($raw as $k => $v) {                                             // the form: a blank control is no filter
            if ($v === '' || $v === [] || $v === null) {
                unset($raw[$k]);
            }
        }
    }
    $norm = [];
    $errors += view_filters_validate($raw, $norm);
    $filters = $norm;
}
$sortBy = req_has('sort_by') ? (string) req_val('sort_by') : (string) ($prior['sort_by'] ?? 'last_activity_at');
if ($sortBy === '') {
    $sortBy = 'last_activity_at';
}
if (!isset(VIEW_SORTS[$sortBy])) {
    $errors['sort_by'] = 'sort_by is one of ' . implode(', ', array_keys(VIEW_SORTS)) . '.';
}
$sortDir = strtolower(req_has('sort_dir') ? (string) req_val('sort_dir') : (string) ($prior['sort_dir'] ?? 'desc')) ?: 'desc';
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $errors['sort_dir'] = 'sort_dir is asc or desc.';
}
$columns = request_list('columns') ?? ($prior['columns'] ?? []);
$columns = array_values(array_intersect(VIEW_COLUMNS, $columns));
if ($columns === []) {
    $columns = ['number', 'subject', 'requester', 'status', 'priority', 'assignee', 'updated'];
}
$layout = req_has('layout') ? (string) req_val('layout') : (string) ($prior['layout'] ?? 'list');
if (!isset(VIEW_LAYOUTS[$layout])) {
    $errors['layout'] = 'The layout is list or board.';
}
$board = $prior['board'] ?? board_settings([]);
if (req_has('board')) {
    $rawB = $_POST['board'];
    if (is_string($rawB)) {
        $rawB = json_decode($rawB, true);
        if (!is_array($rawB)) {
            $errors['board'] = 'board is a JSON object: column_field, swimlane, wip, solved_days.';
            $rawB = [];
        }
    }
    $rawB = (array) $rawB;
    if (isset($rawB['column_field']) && $rawB['column_field'] !== '' && !isset(BOARD_COLUMN_FIELDS[$rawB['column_field']])) {
        $errors['board'] = 'column_field is one of ' . implode(', ', array_keys(BOARD_COLUMN_FIELDS)) . '.';
    }
    if (isset($rawB['swimlane']) && $rawB['swimlane'] !== '' && $rawB['swimlane'] !== null && !isset(BOARD_SWIMLANES[$rawB['swimlane']])) {
        $errors['board'] = 'swimlane is one of ' . implode(', ', array_filter(array_keys(BOARD_SWIMLANES))) . '.';
    }
    $board = board_settings(['board' => ['column_field' => $rawB['column_field'] ?? $board['column_field'], 'swimlane' => array_key_exists('swimlane', $rawB) ? $rawB['swimlane'] : $board['swimlane'], 'wip' => $rawB['wip'] ?? $board['wip'], 'solved_days' => $rawB['solved_days'] ?? $board['solved_days']]]);
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['name' => $name, 'scope' => $scope, 'queue_id' => $queueId, 'filters' => $filters, 'sort_by' => $sortBy, 'sort_dir' => $sortDir, 'columns' => $columns, 'layout' => $layout, 'board' => $board, 'owner_member_id' => $cur['owner_member_id'] ?? $me];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f, $me): int {
    $pdo->beginTransaction();
    $newId = save_view($pdo, $id, $f, $me);
    $after = view_log_state(find_view($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'view.create', 'saved_view', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(view_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'view.update', 'saved_view', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Saved the view ' : 'Changed the view ') . $name, $newId, hd_land(return_path('/views/' . $newId . '/edit'), 'saved'), 'viewChanged', ['view_id' => $newId, 'layout' => $layout]);
