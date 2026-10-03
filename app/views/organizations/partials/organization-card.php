<?php /** One organization as a card. Data: o, here */ $id = (int) $o['organization_id']; ?>
<div class="card h-100" id="organization-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0" id="organization-card-<?= $id ?>-name"><?= hx_link(with_back('/organizations/' . $id, $here), e($o['name']), 'text-dark') ?></div>
        <?php if ($o['support_tier']): ?><span class="badge bg-soft-primary text-primary" id="organization-card-<?= $id ?>-tier"><?= e($o['support_tier']) ?></span><?php endif; ?>
    </div>
    <div class="fs-12 text-muted mt-1 text-truncate" id="organization-card-<?= $id ?>-domains"><?= $o['domains'] === [] ? 'No email domain' : e(implode(', ', $o['domains'])) ?></div>
    <div class="fs-12 mt-1" id="organization-card-<?= $id ?>-facts"><span class="fw-semibold"><?= (int) $o['open_count'] ?> open</span> <span class="text-muted">· <?= (int) $o['people_count'] ?> <?= (int) $o['people_count'] === 1 ? 'person' : 'people' ?></span>
        <?php if ($o['members_see_each_other'] === true): ?> <span class="wd-10 ht-10 bg-success d-inline-block rounded-circle ms-1" title="Members see each other's tickets" id="organization-card-<?= $id ?>-see"></span><?php endif; ?></div>
    <div class="mt-2"><?= hx_link(with_back('/organizations/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="organization-card-' . $id . '-open-btn"') ?></div>
</div></div>
