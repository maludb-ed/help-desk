<?php /** One requester (screen `requester-view`). Data: r, organizations, may (manage), notice, here */ $id = (int) $r['requester_id']; $managed = $r['kind'] !== 'external'; ?>
<?= view('shared/header.php', ['id' => 'requester-view', 'title' => $r['display_name'], 'crumbs' => [['Home', '/'], ['Requesters', '/requesters/'], [$r['display_name'], null]], 'back' => back_link() ?? ['/requesters/', 'Requesters'],
    'action' => hx_link(with_back('/requesters/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="requester-view-edit-btn"')]) ?>
<div class="main-content" id="requester-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($r['merged_into_id'] !== null): ?><div class="alert alert-secondary" id="requester-view-merged">This requester was merged into <?= hx_link('/requesters/' . (int) $r['merged_into_id'], 'another', 'alert-link') ?>.</div><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="requester-view-facts"><div class="card-body">
                <span class="badge bg-soft-<?= $r['kind'] === 'agent' ? 'info text-info' : ($r['kind'] === 'member' ? 'secondary text-secondary' : 'primary text-primary') ?> mb-2" id="requester-view-kind"><?= e(REQUESTER_KINDS[$r['kind']]) ?></span>
                <?php if ($managed): ?><div class="fs-12 text-muted mb-2" id="requester-view-managed">Managed by the OS — the name and address are the directory's.</div><?php endif; ?>
                <dl class="row mb-0 fs-12">
                    <dt class="col-5 text-muted">Organization</dt><dd class="col-7" id="requester-view-organization"><?= $r['organization_id'] === null ? 'None' : hx_link(with_back('/organizations/' . (int) $r['organization_id'], $here), e($r['organization_name'])) ?></dd>
                    <?php if ($r['phone']): ?><dt class="col-5 text-muted">Phone</dt><dd class="col-7" id="requester-view-phone"><?= e($r['phone']) ?></dd><?php endif; ?>
                    <?php if ($r['locale'] || $r['timezone']): ?><dt class="col-5 text-muted">Locale · zone</dt><dd class="col-7" id="requester-view-locale"><?= e(trim(($r['locale'] ?? '') . ' · ' . ($r['timezone'] ?? ''), ' ·')) ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Tickets</dt><dd class="col-7" id="requester-view-tickets"><?= (int) $r['ticket_count'] ?> (slice 3 lists them)</dd>
                    <?php if ($r['last_seen_at']): ?><dt class="col-5 text-muted">Last seen</dt><dd class="col-7" id="requester-view-seen"><?= e(format_ts($r['last_seen_at'], member_timezone())) ?></dd><?php endif; ?>
                </dl>
                <?php if ($r['notes'] !== null && $r['notes'] !== ''): ?><div class="border-top pt-2 mt-2 fs-12" id="requester-view-notes"><div class="text-muted">Notes (support's; never shown to them)</div><?= nl2br(e($r['notes'])) ?></div><?php endif; ?>
                <?php if ($may['manage'] && $r['merged_into_id'] === null): ?><div class="mt-3"><?= hx_link(with_back('/requesters/merge.php?requester=' . $id, $here), '<i class="feather-git-merge me-1"></i>Merge into…', 'btn btn-light btn-touch w-100', 'id="requester-view-merge-btn"') ?></div><?php endif; ?>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card" id="requester-view-addresses"><div class="card-header"><h5 class="card-title mb-0">Addresses</h5></div>
                <div class="list-group list-group-flush" id="requester-view-address-list">
                    <?php foreach ($r['emails'] as $i => $e): ?>
                        <div class="list-group-item d-flex align-items-center gap-2 py-2" id="requester-email-<?= $i ?>">
                            <div class="min-w-0 flex-grow-1 text-truncate"><?= e($e['email']) ?><?= $i === 0 ? ' <span class="badge bg-soft-primary text-primary">Primary</span>' : '' ?><?= $e['verified_at'] !== null ? ' <i class="feather-check-circle text-success" title="Verified"></i>' : '' ?></div>
                            <?php if (count($r['emails']) > 1 && !$managed): ?>
                                <form method="post" action="/requesters/email-remove.php" hx-post="/requesters/email-remove.php" hx-target="#flash" hx-confirm="Remove <?= e($e['email']) ?>?"><?= csrf_field() ?><input type="hidden" name="requester" value="<?= $id ?>"><input type="hidden" name="email" value="<?= e($e['email']) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                                    <button type="submit" class="btn btn-light btn-sm btn-touch" id="requester-email-<?= $i ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($r['emails'] === []): ?><div class="list-group-item text-muted" id="requester-view-addresses-empty">No address yet.</div><?php endif; ?>
                </div>
                <?php if (!$managed): ?>
                <div class="card-body border-top">
                    <form method="post" action="/requesters/email.php" hx-post="/requesters/email.php" hx-target="#flash" class="input-group" id="requester-email-form">
                        <?= csrf_field() ?><input type="hidden" name="requester" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                        <input type="email" name="email" id="requester-email-form-field-email" class="form-control btn-touch" placeholder="another@example.com" maxlength="200" required>
                        <button type="submit" class="btn btn-primary btn-touch" id="requester-email-form-save-btn">Add</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
