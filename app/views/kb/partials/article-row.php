<?php /** One article in a list. Data: a */ $id = (int) $a['article_id']; ?>
<a href="/kb/<?= e($a['slug']) ?>" class="list-group-item list-group-item-action btn-touch" id="article-row-<?= $id ?>">
    <div class="d-flex justify-content-between gap-2"><span class="fw-semibold"><?= e($a['title']) ?><?= $a['visibility'] === 'internal' ? ' <span class="badge bg-soft-secondary text-secondary">Internal</span>' : '' ?></span><span class="fs-11 text-muted text-nowrap"><?= e(updated_words($a['updated_at'])) ?></span></div>
    <div class="fs-12 text-muted"><?= e(article_summary($a, 160)) ?><?= $a['helpful_count'] > 0 ? ' · <i class="feather-thumbs-up"></i> ' . (int) $a['helpful_count'] : '' ?></div>
</a>
