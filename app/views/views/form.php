<?php /** Save or change a view (screens `view-add`, `view-edit`). Data: cur (view|null), start (name, scope, queue_id, filters, sort_by, sort_dir, columns, layout, board), readOnly, statuses, queues, assignees, types, categories, organizations, here */
$screen = $cur === null ? 'view-add' : 'view-edit'; $id = $cur['view_id'] ?? null; $f = $start['filters']; $dis = $readOnly ? 'disabled' : '';
$chip = static fn (string $name, string $val, string $label, bool $on, string $idp): string => '<label class="btn btn-light btn-touch chip-check position-relative mb-0 me-1" for="' . e($idp) . '"><input type="checkbox" name="' . e($name) . '" value="' . e($val) . '" id="' . e($idp) . '" ' . ($on ? 'checked' : '') . ' ' . $dis . '>' . e($label) . '</label>';
$sel = static fn (string $name, array $opts, string $cur, string $idp, string $empty = 'Any'): string => '<select name="' . e($name) . '" id="' . e($idp) . '" class="form-select btn-touch" ' . $dis . '><option value="">' . e($empty) . '</option>' . implode('', array_map(static fn ($k, $l): string => '<option value="' . e((string) $k) . '" ' . ((string) $k === $cur ? 'selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts)) . '</select>';
$ids = static fn ($v): array => array_map('strval', (array) ($v ?? []));
$assigneeOpts = ['me' => 'Me', 'none' => 'Nobody'] + array_combine(array_map(static fn ($m) => (string) $m['member_id'], $assignees), array_map(static fn ($m) => $m['display_name'] . ($m['member_kind'] === 'agent' ? ' (agent)' : ''), $assignees)); ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Save a view' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Views', '/views/'], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? ['/views/', 'Views']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <?php if ($readOnly): ?><div class="alert alert-info fs-12" id="view-form-standard-note">A standard view cannot be changed — save a copy.</div><?php endif; ?>
    <form method="post" action="/views/save.php" hx-post="/views/save.php" hx-target="#flash" id="view-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="view" value="<?= (int) $id ?>"><?php endif; ?><?php if ($readOnly): ?><input type="hidden" name="copy" value="yes"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="view-form-field-name">Name</label>
            <input type="text" name="name" id="view-form-field-name" class="form-control btn-touch mb-3" maxlength="80" required value="<?= e($readOnly ? 'Copy of ' . $start['name'] : $start['name']) ?>" placeholder="Urgent and unassigned">
            <label class="form-label fs-12 text-muted">Who sees it</label>
            <div class="d-flex flex-wrap gap-2 mb-3" id="view-form-field-scope">
                <?php if (has_right('views.personal') || $readOnly): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="view-form-field-scope-personal"><input type="radio" class="form-check-input mt-0" name="scope" value="personal" id="view-form-field-scope-personal" <?= ($readOnly ? 'personal' : $start['scope']) === 'personal' ? 'checked' : '' ?>>Me</label><?php endif; ?>
                <?php if (has_right('views.shared') && !$readOnly): ?>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="view-form-field-scope-queue"><input type="radio" class="form-check-input mt-0" name="scope" value="queue" id="view-form-field-scope-queue" <?= $start['scope'] === 'queue' ? 'checked' : '' ?>>A queue</label>
                    <select name="queue" id="view-form-field-queue" class="form-select btn-touch w-auto" aria-label="Queue"><option value="">Which queue…</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= $start['queue_id'] === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
                    <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="view-form-field-scope-global"><input type="radio" class="form-check-input mt-0" name="scope" value="global" id="view-form-field-scope-global" <?= $start['scope'] === 'global' ? 'checked' : '' ?>>Everyone</label>
                <?php endif; ?>
            </div>
            <label class="form-label fs-12 text-muted">Layout</label>
            <div class="d-flex flex-wrap gap-2" id="view-form-field-layout">
                <?php foreach (VIEW_LAYOUTS as $k => $l): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="view-form-field-layout-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="layout" value="<?= e($k) ?>" id="view-form-field-layout-<?= e($k) ?>" <?= $start['layout'] === $k ? 'checked' : '' ?>><i class="<?= $k === 'board' ? 'feather-trello' : 'feather-list' ?>"></i><?= e($l) ?></label><?php endforeach; ?>
            </div>
        </div></div>
        <div class="card mb-3" id="view-form-filters"><div class="card-header"><h5 class="mb-0">Filters</h5></div><div class="card-body">
            <?= view('views/partials/filter-row.php', ['key' => 'status_category', 'label' => 'Status category', 'control' => implode('', array_map(static fn (string $c): string => $chip('filters[status_category][]', $c, STATUS_CATEGORIES[$c] ?? ucfirst($c), in_array($c, $ids($f['status_category'] ?? null), true), 'view-filter-status_category-' . $c), VIEW_STATUS_CATEGORIES))]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'status', 'label' => 'One status', 'control' => $sel('filters[status]', array_combine(array_column($statuses, 'key'), array_column($statuses, 'label')), (string) ($f['status'] ?? ''), 'view-filter-status-select')]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'queue_id', 'label' => 'Queues', 'control' => implode('', array_map(static fn (array $q): string => $chip('filters[queue_id][]', (string) $q['queue_id'], $q['name'], in_array((string) $q['queue_id'], $ids($f['queue_id'] ?? null), true), 'view-filter-queue_id-' . $q['queue_id']), $queues))]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'assignee', 'label' => 'Assignee', 'control' => $sel('filters[assignee]', $assigneeOpts, (string) ($f['assignee'] ?? ''), 'view-filter-assignee-select', 'Anyone')]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'requester', 'label' => 'Requester', 'control' => $chip('filters[requester]', 'me', 'My own requests', ($f['requester'] ?? null) === 'me', 'view-filter-requester-me')]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'organization_id', 'label' => 'Organization', 'control' => picker_field(['id' => 'view-filter-organization_id-select', 'name' => 'filters[organization_id]', 'source' => 'organization', 'value' => $f['organization_id'] ?? null, 'placeholder' => 'Any', 'search' => 'Search organizations…', 'disabled' => $readOnly, 'extra' => $readOnly ? ' disabled' : ''])]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'priority', 'label' => 'Priority', 'control' => implode('', array_map(static fn (string $p): string => $chip('filters[priority][]', $p, ucfirst($p), in_array($p, $ids($f['priority'] ?? null), true), 'view-filter-priority-' . $p), VIEW_PRIORITIES))]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'type_id', 'label' => 'Types', 'control' => implode('', array_map(static fn (array $t): string => $chip('filters[type_id][]', (string) $t['type_id'], $t['label'], in_array((string) $t['type_id'], $ids($f['type_id'] ?? null), true), 'view-filter-type_id-' . $t['type_id']), $types))]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'category_id', 'label' => 'Categories', 'control' => implode('', array_map(static fn (array $c): string => $chip('filters[category_id][]', (string) $c['category_id'], $c['path'] ?? $c['name'], in_array((string) $c['category_id'], $ids($f['category_id'] ?? null), true), 'view-filter-category_id-' . $c['category_id']), $categories))]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'tags', 'label' => 'Tags', 'hint' => 'Slugs, comma-separated; any of them', 'control' => '<input type="text" name="filters[tags]" id="view-filter-tags-input" class="form-control btn-touch" value="' . e(implode(', ', (array) ($f['tags'] ?? []))) . '" placeholder="vpn, printer" ' . $dis . '>']) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'needs_reply', 'label' => 'Needs a reply', 'control' => $chip('filters[needs_reply]', 'true', 'The requester wrote last', !empty($f['needs_reply']), 'view-filter-needs_reply-chip')]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'sla', 'label' => 'SLA', 'control' => $sel('filters[sla]', ['at_risk' => 'At risk', 'breached' => 'Breached'], (string) ($f['sla'] ?? ''), 'view-filter-sla-select')]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'stale_days', 'label' => 'Stale for (days)', 'control' => '<input type="number" name="filters[stale_days]" id="view-filter-stale_days-input" class="form-control btn-touch" min="1" max="365" value="' . e((string) ($f['stale_days'] ?? '')) . '" ' . $dis . '>']) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'channel', 'label' => 'Channel', 'control' => $sel('filters[channel]', array_combine(VIEW_CHANNELS, array_map('ucfirst', VIEW_CHANNELS)), (string) ($f['channel'] ?? ''), 'view-filter-channel-select')]) ?>
            <?= view('views/partials/filter-row.php', ['key' => 'text', 'label' => 'Words', 'hint' => 'In the number, the subject or the requester', 'control' => '<input type="text" name="filters[text]" id="view-filter-text-input" class="form-control btn-touch" maxlength="80" value="' . e((string) ($f['text'] ?? '')) . '" ' . $dis . '>']) ?>
        </div></div>
        <div class="card mb-3" id="view-form-list"><div class="card-header"><h5 class="mb-0">The list</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-8"><label class="form-label fs-12 text-muted" for="view-form-field-sort_by">Sort by</label><?= $sel('sort_by', ['last_activity_at' => 'Last activity', 'created_at' => 'Created', 'priority' => 'Priority', 'sla_next_due_at' => 'SLA due', 'number' => 'Number', 'status' => 'Status', 'requester_name' => 'Requester', 'assignee_name' => 'Assignee'], $start['sort_by'], 'view-form-field-sort_by', 'Last activity') ?></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="view-form-field-sort_dir">Direction</label><?= $sel('sort_dir', ['desc' => 'Descending', 'asc' => 'Ascending'], $start['sort_dir'], 'view-form-field-sort_dir', 'Descending') ?></div>
            </div>
            <label class="form-label fs-12 text-muted">Columns</label>
            <div class="d-flex flex-wrap gap-1" id="view-form-field-columns"><?php foreach (VIEW_COLUMNS as $c): ?><?= $chip('columns[]', $c, ucfirst(str_replace('_', ' ', $c)), in_array($c, $start['columns'], true), 'view-form-field-columns-' . $c) ?><?php endforeach; ?></div>
        </div></div>
        <div class="card mb-3" id="view-form-board"><div class="card-header"><h5 class="mb-0">The board</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="view-form-field-column_field">Columns are</label><?= $sel('board[column_field]', BOARD_COLUMN_FIELDS, $start['board']['column_field'], 'view-form-field-column_field', 'Status') ?></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="view-form-field-swimlane">Swimlanes</label><?= $sel('board[swimlane]', array_filter(BOARD_SWIMLANES, static fn ($k) => $k !== '', ARRAY_FILTER_USE_KEY), (string) ($start['board']['swimlane'] ?? ''), 'view-form-field-swimlane', 'None') ?></div>
            </div>
            <label class="form-label fs-12 text-muted">WIP limits per status column (0 = none)</label>
            <div class="row g-2 mb-3" id="view-form-field-wip"><?php foreach ($statuses as $s): ?><?php if ($s['category'] === 'closed' || !$s['on_board']) { continue; } ?><div class="col-6 col-md-3"><label class="fs-11 text-muted" for="view-form-field-wip-<?= e($s['key']) ?>"><?= e($s['label']) ?></label><input type="number" name="board[wip][<?= e($s['key']) ?>]" id="view-form-field-wip-<?= e($s['key']) ?>" class="form-control btn-touch" min="0" max="999" value="<?= (int) ($start['board']['wip'][$s['key']] ?? 0) ?>" <?= $dis ?>></div><?php endforeach; ?></div>
            <label class="form-label fs-12 text-muted" for="view-form-field-solved_days">Show solved cards for (days)</label>
            <input type="number" name="board[solved_days]" id="view-form-field-solved_days" class="form-control btn-touch" min="0" max="30" value="<?= (int) $start['board']['solved_days'] ?>" <?= $dis ?>>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="view-form-save-btn"><?= $readOnly ? 'Save a copy' : 'Save' ?></button>
        <?= hx_link('/views/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="view-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null && view_may_change($cur)): ?>
        <form method="post" action="/views/delete.php" hx-post="/views/delete.php" hx-target="#flash" hx-confirm="Delete the view <?= e($cur['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="view" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="view-form-delete-btn">Delete</button></form>
    <?php endif; ?>
</div>
