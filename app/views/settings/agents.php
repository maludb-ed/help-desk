<?php /** Which agents work here (screen `agents`, read-only). Data: agents, osUrl, tz */ ?>
<?= view('shared/header.php', ['id' => 'agents', 'title' => 'Agents', 'crumbs' => [['Home', '/'], ['Agents', null]]]) ?>
<div class="main-content" id="agents-content">
    <div class="fs-12 text-muted mb-3" id="agents-note">Hiring, grants and duties live in the OS. <a href="<?= e($osUrl) ?>" id="agents-os-link">Open the OS</a>.</div>
    <?php if ($agents === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="agents-empty">No agent works here yet — the installer proposes three.</div></div><?php endif; ?>
    <div class="row g-3" id="agents-cards">
        <?php foreach ($agents as $a): $id = (int) $a['member_id']; $d = $a['last_dispatch']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="agent-card-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex align-items-center gap-2"><span class="avatar-text avatar-md rounded bg-soft-info text-info"><i class="feather-cpu"></i></span>
                    <div class="min-w-0"><div class="fw-bold text-truncate" id="agent-card-<?= $id ?>-name"><?= e($a['display_name']) ?></div><div class="fs-12 text-muted"><?= e($a['job_title'] ?? 'Agent') ?><?= $a['roles'] !== [] ? ' · ' . e(implode(', ', $a['roles'])) : '' ?></div></div></div>
                <div class="fs-12 mt-2" id="agent-card-<?= $id ?>-queues"><?php if ($a['queues'] === []): ?><span class="text-muted">In no queue yet</span><?php else: ?><?php foreach ($a['queues'] as $q): ?><?= hx_link('/settings/queues/' . (int) $q['queue_id'], e($q['name']) . ($q['is_lead'] ? ' (lead)' : ''), 'badge bg-soft-secondary text-secondary me-1 text-decoration-none') ?><?php endforeach; ?><?php endif; ?></div>
                <div class="fs-12 text-muted mt-2" id="agent-card-<?= $id ?>-dispatch"><?= $d === null ? 'Never dispatched' : 'Last: ' . e($d['kind']) . ' via ' . e($d['via']) . ', ' . e(format_ts($d['created_at'], $tz, 'M j, g:i A')) . ' · ' . e($d['status']) ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>
