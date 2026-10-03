<?php /** My tickets (screen `my-tickets`): the empty shape; slice 3 fills it. Data: state, canCreate */ ?>
<?= view('shared/header.php', ['id' => 'my-tickets', 'title' => 'My tickets', 'crumbs' => [['Home', '/'], ['My tickets', null]],
    'action' => $canCreate ? hx_link('/tickets/new', '<i class="feather-plus-circle me-1"></i>New request', 'btn btn-primary btn-touch', 'id="my-tickets-new-btn"') : '']) ?>
<div class="main-content" id="my-tickets-content">
    <div class="d-flex flex-wrap gap-1 mb-3" id="my-tickets-states">
        <?php foreach (['open' => 'Open', 'waiting' => 'Waiting on me', 'solved' => 'Solved', 'all' => 'All'] as $k => $label): ?>
            <?= hx_link('/my-tickets?state=' . $k, e($label), 'btn btn-touch ' . ($state === $k ? 'btn-primary' : 'btn-light'), 'id="my-tickets-state-' . e($k) . '"') ?>
        <?php endforeach; ?>
    </div>
    <div class="card" id="my-tickets-card"><div class="card-body">
        <div class="empty-state" id="my-tickets-empty">
            <span class="avatar-text avatar-lg rounded"><i class="feather-inbox"></i></span>
            <div><div class="fw-semibold">You have asked for nothing yet</div>
                <div class="fs-12 text-muted"><?= $canCreate ? 'Press New request when something needs doing. ' : '' ?>The tickets you asked for, filed, are cc\'d on or follow show here.</div></div>
        </div>
    </div></div>
</div>
