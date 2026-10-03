<?php /** One canned response as a card. Data: c */ $id = (int) $c['canned_id']; ?>
<div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="canned-card-<?= $id ?>"><div class="card-body d-flex flex-column gap-2">
    <div class="d-flex align-items-start gap-2">
        <div class="min-w-0 flex-grow-1"><div class="fw-bold text-truncate" id="canned-card-<?= $id ?>-title"><?= e($c['title']) ?></div>
            <div class="fs-11 text-muted"><?php if ($c['folder']): ?><span class="badge bg-soft-secondary text-secondary"><?= e($c['folder']) ?></span> <?php endif; ?><?= $c['scope'] === 'queue' ? e($c['queue_name'] ?? 'Queue') : CANNED_SCOPES[$c['scope']] ?> · used <?= (int) $c['usage_count'] ?>×</div></div>
    </div>
    <div class="fs-12 text-muted" id="canned-card-<?= $id ?>-line"><?= e(canned_first_line($c['body_text'])) ?></div>
    <?php if (($w = side_effect_words($c['side_effects'])) !== []): ?><div class="d-flex flex-wrap gap-1"><?php foreach ($w as $chip): ?><span class="badge bg-soft-primary text-primary"><?= e($chip) ?></span><?php endforeach; ?></div><?php endif; ?>
    <?php if (canned_may_change($c)): ?><div class="mt-auto"><?= hx_link('/canned/' . $id . '/edit', 'Edit', 'btn btn-light btn-touch w-100', 'id="canned-card-' . $id . '-edit"') ?></div><?php endif; ?>
</div></div></div>
