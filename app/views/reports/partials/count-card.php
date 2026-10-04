<?php /** One headline count. Data: id, label, n (string or int), href, tone (primary|danger|warning|secondary), sub (html, optional), icon */ $tone = $tone ?? 'primary'; $sub = $sub ?? ''; ?>
<div class="col-6 col-md-4 col-xl-2"><div class="card h-100 mb-0 count-card" id="<?= e($id) ?>">
    <a href="<?= e($href) ?>" class="card-body p-3 d-block text-decoration-none" id="<?= e($id) ?>-link">
        <div class="d-flex align-items-center gap-2"><span class="avatar-text avatar-sm bg-soft-<?= e($tone) ?> text-<?= e($tone) ?> rounded"><i class="<?= e($icon ?? 'feather-hash') ?>"></i></span><span class="fs-12 text-muted text-truncate"><?= e($label) ?></span></div>
        <div class="fs-3 fw-bold text-<?= $tone === 'danger' ? 'danger' : 'dark' ?> mt-1" id="<?= e($id) ?>-n"><?= e((string) $n) ?></div>
        <?php if ($sub !== ''): ?><div class="fs-11 text-muted" id="<?= e($id) ?>-sub"><?= $sub ?></div><?php endif; ?>
    </a>
</div></div>
