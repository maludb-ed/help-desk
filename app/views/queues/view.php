<?php /** One queue (screen `queue-view`). Data: q, members, presence, candidates, may (manage, members), notice, here */ $id = (int) $q['queue_id']; ?>
<?= view('shared/header.php', ['id' => 'queue-view', 'title' => $q['name'], 'crumbs' => [['Home', '/'], ['Queues', '/settings/queues/'], [$q['name'], null]], 'back' => back_link() ?? ['/settings/queues/', 'Queues'],
    'action' => $may['manage'] ? hx_link(with_back('/settings/queues/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="queue-view-edit-btn"') : '']) ?>
<div class="main-content" id="queue-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card" id="queue-view-facts"><div class="card-body">
                <?php if ($q['is_default']): ?><span class="badge bg-soft-primary text-primary mb-2" id="queue-view-default">Default queue</span><?php endif; ?>
                <?php if ($q['description']): ?><p class="mb-2" id="queue-view-description"><?= e($q['description']) ?></p><?php endif; ?>
                <dl class="row mb-0 fs-12">
                    <dt class="col-5 text-muted">Key</dt><dd class="col-7" id="queue-view-key"><code><?= e($q['key']) ?></code></dd>
                    <dt class="col-5 text-muted">Department</dt><dd class="col-7" id="queue-view-department"><?= $q['department_id'] === null ? 'None' : e($q['department_name']) . ($q['include_department'] ? ' · members join' : ' · named members only') ?></dd>
                    <dt class="col-5 text-muted">Assignment</dt><dd class="col-7" id="queue-view-assignment"><?= e(queue_method_words($q)) ?><?= $q['default_assignee_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">Agent</span>' : '' ?></dd>
                    <dt class="col-5 text-muted">Open tickets</dt><dd class="col-7" id="queue-view-open"><?= (int) $q['open_count'] ?></dd>
                    <dt class="col-5 text-muted">SLA policy</dt><dd class="col-7" id="queue-view-policy"><?= $q['sla_policy_id'] === null ? 'Whichever matches' : hx_link(with_back('/settings/sla/' . (int) $q['sla_policy_id'] . '/edit', $here), e($q['policy_name'])) ?></dd>
                    <dt class="col-5 text-muted">Hours</dt><dd class="col-7" id="queue-view-schedule"><?= $q['business_hours_id'] === null ? 'Calendar hours' : hx_link(with_back('/settings/sla/hours?schedule=' . (int) $q['business_hours_id'], $here), e($q['schedule_name'])) ?></dd>
                    <dt class="col-5 text-muted">Mailbox</dt><dd class="col-7" id="queue-view-email"><?= $q['email_address'] ? e($q['email_address']) : '<span class="text-muted">None (slice 7)</span>' ?></dd>
                </dl>
                <?php if ($may['manage'] && !$q['is_default']): ?>
                    <form method="post" action="/settings/queues/archive.php" hx-post="/settings/queues/archive.php" hx-target="#flash" class="mt-3" hx-confirm="Archive <?= e($q['name']) ?>? Its <?= (int) $q['open_count'] ?> open ticket<?= (int) $q['open_count'] === 1 ? ' moves' : 's move' ?> to the default queue.">
                        <?= csrf_field() ?><input type="hidden" name="queue" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="queue-view-archive-btn">Archive</button></form>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="queue-view-members"><div class="card-header"><h5 class="card-title mb-0">Members</h5></div>
                <div class="list-group list-group-flush" id="queue-view-member-list">
                    <?php if ($members === []): ?><div class="list-group-item text-muted" id="queue-view-members-empty">Nobody yet. Name someone below, or link a department.</div><?php endif; ?>
                    <?php foreach ($members as $m): ?><?= view('queues/partials/member-row.php', ['m' => $m, 'q' => $q, 'may' => $may, 'presence' => $presence[$m['member_id']] ?? null, 'here' => $here]) ?><?php endforeach; ?>
                </div>
                <?php if ($may['members']): ?>
                <div class="card-body border-top">
                    <form method="post" action="/settings/queues/member.php" hx-post="/settings/queues/member.php" hx-target="#flash" id="queue-member-form" class="row g-2 align-items-end">
                        <?= csrf_field() ?><input type="hidden" name="queue" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <div class="col-12 col-md-7"><label class="form-label fs-12 text-muted" for="queue-member-form-field-member">Add a member</label>
                            <select name="member" id="queue-member-form-field-member" class="form-select btn-touch" required>
                                <option value="">Choose a person or an agent…</option>
                                <?php foreach ($candidates as $c): ?><option value="<?= (int) $c['member_id'] ?>"><?= e($c['display_name']) ?> (<?= $c['member_kind'] === 'agent' ? 'agent' : 'person' ?>)</option><?php endforeach; ?>
                            </select></div>
                        <div class="col-7 col-md-3"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="queue-member-form-field-lead"><input type="checkbox" class="form-check-input mt-0" name="is_lead" value="yes" id="queue-member-form-field-lead">As lead</label></div>
                        <div class="col-5 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="queue-member-form-save-btn">Add</button></div>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
