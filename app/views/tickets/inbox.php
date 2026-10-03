<?php /** The inbox (screen `inbox`). Data: views, view (system_key), saved (the view row or null), filters, adhoc, queues, assignees, rows, page, more, query, here, tz, notice */
$fromQuery = http_build_query(array_diff_key($query, ['view' => 1, 'sort' => 1]) + ($saved !== null && !$saved['is_system'] ? ['view' => $saved['view_id']] : [])); ?>
<?= view('shared/header.php', ['id' => 'inbox', 'title' => 'Inbox', 'crumbs' => [['Home', '/'], ['Inbox', null]],
    'action' => has_right('tickets.create') ? hx_link('/tickets/new', '<i class="feather-plus-circle me-1"></i>New ticket', 'btn btn-primary btn-touch', 'id="inbox-new-btn"') : '']) ?>
<div class="main-content" id="inbox-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="inbox-views">
        <?php foreach ($views as $v): ?>
            <?php if ($v['system_key'] === 'team_board'): ?><?= hx_link('/board', e($v['name']), 'btn btn-touch btn-light', 'id="inbox-view-team_board"') ?>
            <?php else: ?><?= hx_link('/inbox?view=' . e($v['system_key']), e($v['name']), 'btn btn-touch ' . ($view === $v['system_key'] ? 'btn-primary' : 'btn-light'), 'id="inbox-view-' . e($v['system_key']) . '"') ?><?php endif; ?>
        <?php endforeach; ?>
        <?php if ($saved !== null && !$saved['is_system']): ?><?= hx_link('/inbox?view=' . (int) $saved['view_id'], e($saved['name']), 'btn btn-touch btn-primary', 'id="inbox-view-' . (int) $saved['view_id'] . '"') ?><?php endif; ?>
        <?= hx_link('/views/', '<i class="feather-bookmark me-1"></i>Views', 'btn btn-touch btn-light', 'id="inbox-views-link"') ?>
        <?php if (has_right('views.personal') || has_right('views.shared')): ?><?= hx_link('/views/new?' . $fromQuery, '<i class="feather-save me-1"></i>Save as view', 'btn btn-touch btn-light', 'id="inbox-save-view-link"') ?><?php endif; ?>
        <?php if ($saved !== null && $saved['layout'] === 'board'): ?><?= hx_link('/board?view=' . (int) $saved['view_id'], '<i class="feather-trello me-1"></i>Board', 'btn btn-touch btn-light', 'id="inbox-board-link"') ?><?php endif; ?>
    </div>
    <form method="get" action="/inbox" hx-get="/inbox" hx-target="#ticket-list" hx-select="#ticket-list" hx-swap="outerHTML" hx-push-url="true" hx-trigger="submit, change from:#inbox-filter-status, change from:#inbox-filter-queue, change from:#inbox-filter-assignee, change from:#inbox-filter-priority, change from:#inbox-filter-sort" class="card mb-3" id="inbox-filters"><div class="card-body d-flex flex-wrap gap-2">
        <?php if ($view !== ''): ?><input type="hidden" name="view" value="<?= e($view) ?>"><?php endif; ?>
        <input type="search" name="q" id="inbox-filter-q" class="form-control btn-touch flex-grow-1" placeholder="Number, subject or requester…" value="<?= e($filters['q']) ?>" maxlength="80" aria-label="Search">
        <select name="status" id="inbox-filter-status" class="form-select btn-touch w-auto" aria-label="Status"><option value="">Any status</option><?php foreach (STATUS_CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select name="queue" id="inbox-filter-queue" class="form-select btn-touch w-auto" aria-label="Queue"><option value="">Any queue</option><?php foreach ($queues as $q): ?><option value="<?= (int) $q['queue_id'] ?>" <?= $filters['queue'] === (int) $q['queue_id'] ? 'selected' : '' ?>><?= e($q['name']) ?></option><?php endforeach; ?></select>
        <select name="assignee" id="inbox-filter-assignee" class="form-select btn-touch w-auto" aria-label="Assignee"><option value="">Anyone</option><option value="me" <?= $filters['assignee'] === 'me' ? 'selected' : '' ?>>Me</option><option value="none" <?= $filters['assignee'] === 'none' ? 'selected' : '' ?>>Nobody</option><?php foreach ($assignees as $m): ?><option value="<?= (int) $m['member_id'] ?>" <?= $filters['assignee'] === (string) $m['member_id'] ? 'selected' : '' ?>><?= e($m['display_name']) ?><?= $m['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select>
        <select name="priority" id="inbox-filter-priority" class="form-select btn-touch w-auto" aria-label="Priority"><option value="">Any priority</option><?php foreach (PRIORITY_COLORS as $k => $c): ?><option value="<?= e($k) ?>" <?= $filters['priority'] === $k ? 'selected' : '' ?>><?= e(ucfirst($k)) ?></option><?php endforeach; ?></select>
        <select name="sort" id="inbox-filter-sort" class="form-select btn-touch w-auto" aria-label="Sort"><?php foreach (['last_activity' => 'Last activity', 'sla_due' => 'SLA due', 'priority' => 'Priority', 'created_at' => 'Newest', 'updated_at' => 'Updated'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['sort'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button type="submit" class="btn btn-light btn-touch" id="inbox-filter-btn" aria-label="Search"><i class="feather-search"></i></button>
    </div></form>
    <?= view('tickets/partials/ticket-list.php', ['rows' => $rows, 'page' => $page, 'more' => $more, 'query' => $query, 'here' => $here, 'tz' => $tz, 'base' => '/inbox']) ?>
</div>
