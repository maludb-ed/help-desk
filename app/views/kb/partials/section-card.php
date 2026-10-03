<?php /** One section as a card (with its children). Data: s, children (sections), filter */ $id = (int) $s['section_id']; ?>
<div class="col-12 col-md-6 col-xl-4"><div class="card h-100 mb-0" id="kb-section-card-<?= $id ?>"><div class="card-body d-flex flex-column gap-2">
    <div class="d-flex align-items-start gap-2"><span class="avatar-text avatar-md bg-soft-primary text-primary rounded"><i class="feather-folder"></i></span>
        <div class="min-w-0 flex-grow-1"><div class="fw-bold"><a href="/kb/s/<?= e($s['slug']) ?>" class="text-dark" id="kb-section-card-<?= $id ?>-link"><?= e($s['name']) ?></a><?= $s['visibility'] === 'internal' ? ' <span class="badge bg-soft-secondary text-secondary">Internal</span>' : '' ?></div>
            <?php if ($s['description']): ?><div class="fs-12 text-muted"><?= e($s['description']) ?></div><?php endif; ?></div>
        <span class="badge bg-soft-primary text-primary" id="kb-section-card-<?= $id ?>-count"><?= (int) $s['article_count'] ?></span></div>
    <?php if ($children !== []): ?><div class="fs-12"><?php foreach ($children as $c): ?><a href="/kb/s/<?= e($c['slug']) ?>" class="d-block py-1" id="kb-section-child-<?= (int) $c['section_id'] ?>"><i class="feather-corner-down-right me-1 text-muted"></i><?= e($c['name']) ?> <span class="text-muted">· <?= (int) $c['article_count'] ?></span></a><?php endforeach; ?></div><?php endif; ?>
</div></div></div>
