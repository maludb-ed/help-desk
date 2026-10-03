<?php /** One organization (screen `organization-view`). Data: o, people, may (manage), notice, here */ $id = (int) $o['organization_id']; ?>
<?= view('shared/header.php', ['id' => 'organization-view', 'title' => $o['name'], 'crumbs' => [['Home', '/'], ['Organizations', '/organizations/'], [$o['name'], null]], 'back' => back_link() ?? ['/organizations/', 'Organizations'],
    'action' => hx_link(with_back('/organizations/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="organization-view-edit-btn"')]) ?>
<div class="main-content" id="organization-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="organization-view-facts"><div class="card-body">
                <?php if ($o['support_tier']): ?><span class="badge bg-soft-primary text-primary mb-2" id="organization-view-tier"><?= e($o['support_tier']) ?></span><?php endif; ?>
                <dl class="row mb-0 fs-12">
                    <dt class="col-5 text-muted">Open tickets</dt><dd class="col-7" id="organization-view-open"><?= (int) $o['open_count'] ?> of <?= (int) $o['ticket_count'] ?> (slice 3 lists them)</dd>
                    <dt class="col-5 text-muted">SLA</dt><dd class="col-7" id="organization-view-policy"><?= $o['sla_policy_id'] === null ? 'Whichever matches' : hx_link(with_back('/settings/sla/' . (int) $o['sla_policy_id'] . '/edit', $here), e($o['policy_name'])) ?></dd>
                    <dt class="col-5 text-muted">Members see each other</dt><dd class="col-7" id="organization-view-see"><?= $o['members_see_each_other'] === null ? 'The business default' : ($o['members_see_each_other'] ? 'Yes' : 'No') ?></dd>
                    <?php if ($o['tags'] !== []): ?><dt class="col-5 text-muted">Tags</dt><dd class="col-7" id="organization-view-tags"><?php foreach ($o['tags'] as $t): ?><span class="badge bg-soft-secondary text-secondary me-1"><?= e($t) ?></span><?php endforeach; ?></dd><?php endif; ?>
                </dl>
                <?php if ($o['notes'] !== null && $o['notes'] !== ''): ?><div class="border-top pt-2 mt-2 fs-12" id="organization-view-notes"><div class="text-muted">Notes (support's)</div><?= nl2br(e($o['notes'])) ?></div><?php endif; ?>
                <?php if ($may['manage']): ?>
                    <form method="post" action="/organizations/archive.php" hx-post="/organizations/archive.php" hx-target="#flash" class="mt-3" hx-confirm="Archive <?= e($o['name']) ?>? Its people and tickets stay; it leaves the lists.">
                        <?= csrf_field() ?><input type="hidden" name="organization" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="organization-view-archive-btn">Archive</button></form>
                <?php endif; ?>
            </div></div>
            <div class="card" id="organization-view-domains"><div class="card-header"><h5 class="card-title mb-0">Email domains</h5></div><div class="card-body">
                <div class="mb-2" id="organization-view-domain-chips">
                    <?php if ($o['domains'] === []): ?><span class="fs-12 text-muted" id="organization-view-domains-empty">None yet. A domain claims every new requester who writes from it.</span><?php endif; ?>
                    <?php foreach ($o['domains'] as $d): ?>
                        <form method="post" action="/organizations/save.php" hx-post="/organizations/save.php" hx-target="#flash" class="d-inline-block me-1 mb-1" hx-confirm="Remove <?= e($d) ?>?">
                            <?= csrf_field() ?><input type="hidden" name="organization" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <?php foreach ($o['domains'] as $keep): if ($keep !== $d): ?><input type="hidden" name="domains[]" value="<?= e($keep) ?>"><?php endif; endforeach; ?><input type="hidden" name="domains[]" value="">
                            <button type="submit" class="badge bg-soft-secondary text-secondary border-0" id="organization-view-domain-<?= e(str_replace('.', '-', $d)) ?>"><?= e($d) ?> <i class="feather-x"></i></button></form>
                    <?php endforeach; ?>
                </div>
                <form method="post" action="/organizations/save.php" hx-post="/organizations/save.php" hx-target="#flash" class="input-group" id="organization-domain-form">
                    <?= csrf_field() ?><input type="hidden" name="organization" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <?php foreach ($o['domains'] as $keep): ?><input type="hidden" name="domains[]" value="<?= e($keep) ?>"><?php endforeach; ?>
                    <input type="text" name="domains[]" id="organization-domain-form-field-domain" class="form-control btn-touch" placeholder="example.com" maxlength="120" required>
                    <button type="submit" class="btn btn-primary btn-touch" id="organization-domain-form-save-btn">Add</button>
                </form>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card" id="organization-view-people"><div class="card-header"><h5 class="card-title mb-0">People</h5><?= hx_link(with_back('/requesters/new?organization=' . $id, $here), 'Add', 'fs-12', 'id="organization-view-add-person"') ?></div>
                <div class="card-body">
                    <?php if ($people === []): ?><div class="text-muted" id="organization-view-people-empty">Nobody yet. People appear here when they write in from a claimed domain, or are added by hand.</div><?php endif; ?>
                    <div class="row g-2">
                        <?php foreach ($people as $p): ?><div class="col-12 col-md-6"><?= view('requesters/partials/requester-card.php', ['r' => $p, 'here' => $here, 'compact' => true]) ?></div><?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
