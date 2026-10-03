<?php /** The statuses (screen `statuses-list`). Data: rows, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'statuses-list', 'title' => 'Statuses', 'crumbs' => [['Home', '/'], ['Statuses', null]],
    'action' => hx_link('/settings/statuses/new', '<i class="feather-plus me-1"></i>Add status', 'btn btn-primary btn-touch', 'id="statuses-list-add-btn"')]) ?>
<div class="main-content" id="statuses-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3"><?= hx_link('/settings/statuses/transitions', '<i class="feather-git-branch me-1"></i>Transitions — which moves are allowed', 'btn btn-light btn-touch w-100', 'id="statuses-list-transitions-btn"') ?></div>
    <div class="card" id="statuses-card"><div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="statuses-table">
        <thead class="thead-light"><tr><th>Status</th><th class="d-none d-md-table-cell">Key</th><th>Category</th><th class="d-none d-md-table-cell">On board</th><th class="d-none d-md-table-cell">Clock</th><th class="text-end">Tickets</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $i => $s): ?><?= view('statuses/partials/status-row.php', ['s' => $s, 'first' => $i === 0, 'last' => $i === count($rows) - 1, 'here' => $here]) ?><?php endforeach; ?></tbody>
    </table></div></div></div>
    <div class="fs-12 text-muted mt-2">The order is the board's column order. A built-in status (lock) is renamed, never archived; a custom one lives inside open, pending, on hold or solved.</div>
</div>
