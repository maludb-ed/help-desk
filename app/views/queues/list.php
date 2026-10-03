<?php /** The queues (screen `queues-list`). Data: rows, may, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'queues-list', 'title' => 'Queues', 'crumbs' => [['Home', '/'], ['Queues', null]],
    'action' => $may['manage'] ? hx_link('/settings/queues/new', '<i class="feather-plus me-1"></i>Add queue', 'btn btn-primary btn-touch', 'id="queues-list-add-btn"') : '']) ?>
<div class="main-content" id="queues-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3" id="queues-list-cards">
        <?php foreach ($rows as $q): ?><div class="col-12 col-md-6 col-xl-4"><?= view('queues/partials/queue-card.php', ['q' => $q, 'may' => $may, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
</div>
