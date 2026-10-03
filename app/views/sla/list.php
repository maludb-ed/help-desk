<?php /** SLA policies and schedules (screen `sla-list`). Data: policies, schedules, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'sla-list', 'title' => 'SLA and hours', 'crumbs' => [['Home', '/'], ['SLA and hours', null]],
    'action' => hx_link('/settings/sla/new', '<i class="feather-plus me-1"></i>Add policy', 'btn btn-primary btn-touch', 'id="sla-list-add-btn"')]) ?>
<div class="main-content" id="sla-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <h6 class="text-muted text-uppercase fs-11 mb-2">Policies, in match order</h6>
    <div class="row g-3 mb-4" id="sla-list-policies">
        <?php foreach ($policies as $i => $p): ?><div class="col-12 col-xl-6"><?= view('sla/partials/policy-card.php', ['p' => $p, 'first' => $i === 0, 'last' => $i === count($policies) - 1, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="text-muted text-uppercase fs-11 mb-0">Schedules</h6><?= hx_link('/settings/sla/hours', '<i class="feather-plus me-1"></i>New schedule', 'btn btn-light btn-sm btn-touch', 'id="sla-list-new-schedule-btn"') ?></div>
    <div class="row g-3" id="sla-list-schedules">
        <?php foreach ($schedules as $s): $sid = (int) $s['schedule_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="schedule-card-<?= $sid ?>"><div class="card-body p-3">
                <div class="fw-bold" id="schedule-card-<?= $sid ?>-name"><?= e($s['name']) ?><?= $s['is_default'] ? ' <span class="badge bg-soft-primary text-primary">Default</span>' : '' ?></div>
                <div class="fs-12 text-muted" id="schedule-card-<?= $sid ?>-summary"><?= e($s['summary']) ?> · <?= e($s['timezone']) ?></div>
                <div class="fs-12 text-muted" id="schedule-card-<?= $sid ?>-holidays"><?= count($s['holidays']) ?> holiday<?= count($s['holidays']) === 1 ? '' : 's' ?></div>
                <div class="mt-2"><?= hx_link(with_back('/settings/sla/hours?schedule=' . $sid, $here), 'Edit hours', 'btn btn-light btn-touch w-100', 'id="schedule-card-' . $sid . '-edit-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
