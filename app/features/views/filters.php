<?php
declare(strict_types=1);

/**
 * The ONE translator (slice 4, docs/build-specs/views-board.md): a saved view's filters, the inbox's ad-hoc ones and the board's header filters are the same JSON object over a
 * bounded vocabulary, and become SQL over mcp_tickets here and nowhere else. The view already decides who sees what; a filter only narrows. Never a request value as an identifier:
 * sort keys and columns come from the allow-lists, the rest are bound parameters. The Phase 4 tools port this table to Python, key for key.
 */

const VIEW_FILTER_KEYS = ['status_category', 'status', 'queue_id', 'assignee', 'requester', 'organization_id', 'priority', 'type_id', 'category_id', 'tags', 'needs_reply', 'sla', 'stale_days', 'channel', 'text'];
const VIEW_STATUS_CATEGORIES = ['new', 'open', 'pending', 'on_hold', 'solved', 'closed'];
const VIEW_PRIORITIES = ['low', 'normal', 'high', 'urgent'];
const VIEW_SLA_STATES = ['at_risk', 'breached'];
const VIEW_CHANNELS = ['web', 'email', 'phone', 'chat', 'agent', 'api', 'portal'];
/** sort_by → ORDER BY (the direction appended; `priority` is urgent first when desc). */
const VIEW_SORTS = ['last_activity_at' => 't.last_activity_at', 'created_at' => 't.created_at', 'priority' => "CASE t.priority WHEN 'urgent' THEN 3 WHEN 'high' THEN 2 WHEN 'normal' THEN 1 ELSE 0 END",
    'sla_next_due_at' => 't.sla_next_due_at', 'number' => 't.number', 'status' => 't.status_label', 'requester_name' => 't.requester_name', 'assignee_name' => 't.assignee_name'];
/** The inbox's short sort keys (slice 3) → [sort_by, sort_dir]. */
const VIEW_SORT_ALIASES = ['last_activity' => ['last_activity_at', 'desc'], 'sla_due' => ['sla_next_due_at', 'asc'], 'priority' => ['priority', 'desc'], 'created_at' => ['created_at', 'desc'], 'updated_at' => ['last_activity_at', 'desc']];
const VIEW_COLUMNS = ['number', 'subject', 'requester', 'organization', 'queue', 'status', 'priority', 'type', 'category', 'assignee', 'tags', 'sla', 'needs_reply', 'created', 'updated', 'due'];
const VIEW_LAYOUTS = ['list' => 'List', 'board' => 'Board'];
const VIEW_SCOPES = ['personal' => 'Personal', 'queue' => 'Queue', 'global' => 'Global'];
const BOARD_COLUMN_FIELDS = ['status' => 'Status', 'priority' => 'Priority', 'assignee' => 'Assignee', 'queue' => 'Queue', 'type' => 'Type'];
const BOARD_SWIMLANES = ['' => 'No swimlanes', 'priority' => 'Priority', 'queue' => 'Queue', 'assignee' => 'Assignee', 'type' => 'Type', 'organization' => 'Organization'];
const BOARD_DEFAULTS = ['column_field' => 'status', 'swimlane' => null, 'wip' => [], 'solved_days' => 3];

/** The filters as SQL over mcp_tickets (alias t): [$where, $params]. Merged tickets never show. Unknown keys are ignored (an old row). */
function view_filters_sql(array $f, int $memberId): array
{
    $where = ['t.merged_into_id IS NULL'];
    $args = [];
    $cats = array_values(array_intersect(VIEW_STATUS_CATEGORIES, array_map('strval', (array) ($f['status_category'] ?? []))));
    if ($cats !== []) {
        $where[] = 't.status_category = ANY (CAST(:cats AS text[]))';
        $args['cats'] = pg_array_literal($cats);
    }
    if (($f['status'] ?? '') !== '') {
        $where[] = 't.status = :status';
        $args['status'] = (string) $f['status'];
    }
    $queues = array_values(array_filter(array_map('intval', (array) ($f['queue_id'] ?? []))));
    if ($queues !== []) {
        $where[] = 't.queue_id = ANY (CAST(:queues AS bigint[]))';
        $args['queues'] = '{' . implode(',', $queues) . '}';
    }
    $a = $f['assignee'] ?? null;
    if ($a === 'me') {
        $where[] = 't.assignee_member_id = :me';
        $args['me'] = $memberId;
    } elseif ($a === 'none') {
        $where[] = 't.assignee_member_id IS NULL';
    } elseif ($a !== null && $a !== '' && filter_var($a, FILTER_VALIDATE_INT) !== false) {
        $where[] = 't.assignee_member_id = :assignee';
        $args['assignee'] = (int) $a;
    }
    $r = $f['requester'] ?? null;
    if ($r === 'me') {
        $where[] = 't.requester_id = (SELECT hd_my_requester_id())';
    } elseif ($r !== null && $r !== '' && filter_var($r, FILTER_VALIDATE_INT) !== false) {
        $where[] = 't.requester_id = :requester';
        $args['requester'] = (int) $r;
    }
    if (($f['organization_id'] ?? null) !== null && $f['organization_id'] !== '' && !is_array($f['organization_id'])) {
        $where[] = 't.organization_id = :organization_id';
        $args['organization_id'] = (int) $f['organization_id'];
    }
    foreach (['type_id', 'category_id'] as $k) {
        $ids = array_values(array_filter(array_map('intval', (array) ($f[$k] ?? []))));
        if ($ids !== []) {
            $where[] = "t.$k = ANY (CAST(:$k AS bigint[]))";
            $args[$k] = '{' . implode(',', $ids) . '}';
        }
    }
    $pr = array_values(array_intersect(VIEW_PRIORITIES, array_map('strval', (array) ($f['priority'] ?? []))));
    if ($pr !== []) {
        $where[] = 't.priority = ANY (CAST(:priorities AS text[]))';
        $args['priorities'] = pg_array_literal($pr);
    }
    $tags = array_values(array_filter(array_map('strval', (array) ($f['tags'] ?? ($f['tag'] ?? [])))));
    if ($tags !== []) {
        $where[] = 't.tags && CAST(:tags AS text[])';
        $args['tags'] = pg_array_literal($tags);
    }
    if (!empty($f['needs_reply'])) {
        $where[] = 't.needs_reply';
    }
    if (in_array($f['sla'] ?? '', ['at_risk', 'breached', 'paused', 'on_track'], true)) {
        $where[] = 't.sla_state = :sla';
        $args['sla'] = $f['sla'];
    }
    if (($f['stale_days'] ?? null) !== null && (int) $f['stale_days'] > 0) {
        $where[] = 't.last_activity_at < now() - make_interval(days => :stale)';
        $args['stale'] = min(365, (int) $f['stale_days']);
    }
    if (($f['channel'] ?? '') !== '') {
        $where[] = 't.channel = :channel';
        $args['channel'] = (string) $f['channel'];
    }
    $q = trim((string) ($f['text'] ?? ($f['q'] ?? '')));
    if ($q !== '') {
        $where[] = '(t.number ILIKE :qn OR t.subject ILIKE :q OR t.requester_name ILIKE :q)';
        $args['q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $args['qn'] = str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    }
    return [implode(' AND ', $where), $args];
}

/** The ORDER BY for an allow-listed sort (a stranger falls back to the default; never SQL from the request). */
function view_sort_sql(string $sortBy, string $sortDir): string
{
    if (isset(VIEW_SORT_ALIASES[$sortBy])) {
        [$sortBy, $sortDir] = VIEW_SORT_ALIASES[$sortBy];
    }
    $expr = VIEW_SORTS[$sortBy] ?? VIEW_SORTS['last_activity_at'];
    if (!isset(VIEW_SORTS[$sortBy])) {
        $sortDir = 'desc';
    }
    $dir = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';
    $nulls = $sortBy === 'sla_next_due_at' ? ' NULLS LAST' : '';
    return $expr . ' ' . $dir . $nulls . ', t.last_activity_at DESC, t.ticket_id DESC';
}

/** The inbox's and the board header's query keys → the vocabulary (unknown ignored, empty dropped). */
function view_filters_from_request(array $get): array
{
    $f = [];
    $s = static fn (string $k): string => trim((string) ($get[$k] ?? ''));
    if ($s('q') !== '' || $s('text') !== '') {
        $f['text'] = mb_substr($s('text') !== '' ? $s('text') : $s('q'), 0, 80);
    }
    foreach (['status_category' => 'status', 'priority' => 'priority'] as $key => $param) {
        $vals = array_values(array_filter(array_map('trim', is_array($get[$param] ?? null) ? $get[$param] : explode(',', $s($param)))));
        $vals = array_values(array_intersect($key === 'priority' ? VIEW_PRIORITIES : VIEW_STATUS_CATEGORIES, $vals));
        if ($vals !== []) {
            $f[$key] = $vals;
        }
    }
    if (isset($get['status_key']) && $s('status_key') !== '') {
        $f['status'] = $s('status_key');
    }
    foreach (['queue_id' => 'queue', 'type_id' => 'type', 'category_id' => 'category'] as $key => $param) {
        $ids = array_values(array_filter(array_map('intval', is_array($get[$param] ?? null) ? $get[$param] : explode(',', $s($param)))));
        if ($ids !== []) {
            $f[$key] = $ids;
        }
    }
    foreach (['assignee', 'requester'] as $k) {
        $v = $s($k);
        if ($v === 'me' || $v === 'none' || filter_var($v, FILTER_VALIDATE_INT) !== false) {
            $f[$k] = $v === 'me' || $v === 'none' ? $v : (int) $v;
        }
    }
    if (filter_var($s('organization'), FILTER_VALIDATE_INT) !== false) {
        $f['organization_id'] = (int) $s('organization');
    }
    $tags = array_values(array_filter(array_map('trim', is_array($get['tags'] ?? null) ? $get['tags'] : explode(',', $s('tags') !== '' ? $s('tags') : $s('tag')))));
    if ($tags !== []) {
        $f['tags'] = $tags;
    }
    if (in_array($s('needs_reply'), ['1', 'yes', 'true'], true)) {
        $f['needs_reply'] = true;
    }
    if (in_array($s('sla'), VIEW_SLA_STATES, true)) {
        $f['sla'] = $s('sla');
    }
    if ((int) $s('stale_days') > 0) {
        $f['stale_days'] = min(365, (int) $s('stale_days'));
    }
    if (in_array($s('channel'), VIEW_CHANNELS, true)) {
        $f['channel'] = $s('channel');
    }
    return $f;
}

/** Errors by key for a filters object a person or an agent sent — an unknown key is refused by name. The object comes back normalised in $out. */
function view_filters_validate(array $filters, ?array &$out = null): array
{
    $errors = [];
    $out = [];
    foreach ($filters as $k => $v) {
        if (!in_array((string) $k, VIEW_FILTER_KEYS, true)) {
            $errors[(string) $k] = "'" . $k . "' is not a filter.";
            continue;
        }
        if ($v === null || $v === '' || $v === []) {
            continue;
        }
        switch ($k) {
            case 'status_category':
                $vals = array_values(array_unique(array_map('strval', (array) $v)));
                if (array_diff($vals, VIEW_STATUS_CATEGORIES) !== []) { $errors[$k] = 'status_category is a list of ' . implode(', ', VIEW_STATUS_CATEGORIES) . '.'; break; }
                $out[$k] = $vals;
                break;
            case 'priority':
                $vals = array_values(array_unique(array_map('strval', (array) $v)));
                if (array_diff($vals, VIEW_PRIORITIES) !== []) { $errors[$k] = 'priority is a list of ' . implode(', ', VIEW_PRIORITIES) . '.'; break; }
                $out[$k] = $vals;
                break;
            case 'status':
            case 'channel':
            case 'text':
                if (!is_scalar($v)) { $errors[$k] = $k . ' is one value.'; break; }
                if ($k === 'channel' && !in_array((string) $v, VIEW_CHANNELS, true)) { $errors[$k] = 'channel is one of ' . implode(', ', VIEW_CHANNELS) . '.'; break; }
                $out[$k] = mb_substr(trim((string) $v), 0, 80);
                break;
            case 'queue_id':
            case 'type_id':
            case 'category_id':
            case 'tags':
                $vals = array_values(array_unique(array_filter(array_map(static fn ($x) => $k === 'tags' ? trim((string) $x) : (filter_var($x, FILTER_VALIDATE_INT) !== false ? (int) $x : null), (array) $v), static fn ($x) => $x !== null && $x !== '')));
                if ($vals === []) { $errors[$k] = $k . ' is a list of ' . ($k === 'tags' ? 'tag slugs' : 'ids') . '.'; break; }
                $out[$k] = $vals;
                break;
            case 'assignee':
                if ($v === 'me' || $v === 'none') { $out[$k] = $v; break; }
                if (filter_var($v, FILTER_VALIDATE_INT) === false) { $errors[$k] = 'assignee is me, none or a member id.'; break; }
                $out[$k] = (int) $v;
                break;
            case 'requester':
                if ($v !== 'me') { $errors[$k] = 'requester is "me".'; break; }
                $out[$k] = 'me';
                break;
            case 'organization_id':
                if (filter_var($v, FILTER_VALIDATE_INT) === false) { $errors[$k] = 'organization_id is an id.'; break; }
                $out[$k] = (int) $v;
                break;
            case 'needs_reply':
                if (!in_array($v, [true, 'true', '1', 1, 'yes', 'on'], true)) { $errors[$k] = 'needs_reply is true or left out.'; break; }
                $out[$k] = true;
                break;
            case 'sla':
                if (!in_array($v, VIEW_SLA_STATES, true)) { $errors[$k] = 'sla is at_risk or breached.'; break; }
                $out[$k] = $v;
                break;
            case 'stale_days':
                if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1 || (int) $v > 365) { $errors[$k] = 'stale_days is 1 to 365.'; break; }
                $out[$k] = (int) $v;
                break;
        }
    }
    return $errors;
}

/** The board settings of a view, defaults filled and the strangers dropped. */
function board_settings(array $view): array
{
    $b = is_array($view['board'] ?? null) ? $view['board'] : (json_decode((string) ($view['board'] ?? '{}'), true) ?: []);
    $cf = isset(BOARD_COLUMN_FIELDS[$b['column_field'] ?? '']) ? $b['column_field'] : 'status';
    $sw = ($b['swimlane'] ?? null) !== null && isset(BOARD_SWIMLANES[$b['swimlane']]) && $b['swimlane'] !== '' ? $b['swimlane'] : null;
    $wip = [];
    foreach ((array) ($b['wip'] ?? []) as $k => $n) {
        if ((int) $n > 0) {
            $wip[(string) $k] = (int) $n;
        }
    }
    $sd = isset($b['solved_days']) && filter_var($b['solved_days'], FILTER_VALIDATE_INT) !== false ? max(0, min(30, (int) $b['solved_days'])) : 3;
    return ['column_field' => $cf, 'swimlane' => $sw, 'wip' => $wip, 'solved_days' => $sd];
}
