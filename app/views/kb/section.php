<?php /** One section (screen `kb-section`). Data: s, parent, children, rows, public, canEdit */ ?>
<?php if (!$public): ?><?= view('shared/header.php', ['id' => 'kb-section', 'title' => $s['name'], 'crumbs' => array_values(array_filter([['Home', '/'], ['Knowledge base', '/kb/'], $parent ? [$parent['name'], '/kb/s/' . $parent['slug']] : null, [$s['name'], null]])), 'back' => ['/kb/', 'Knowledge base']]) ?><?php endif; ?>
<div class="<?= $public ? '' : 'main-content' ?>" id="kb-section-content">
    <?php if ($public): ?><div class="fs-12 text-muted mb-1"><a href="/kb/">Knowledge base</a><?= $parent ? ' › <a href="/kb/s/' . e($parent['slug']) . '">' . e($parent['name']) . '</a>' : '' ?></div><h4 class="mb-2" id="kb-section-title"><?= e($s['name']) ?></h4><?php endif; ?>
    <?php if ($s['description']): ?><p class="text-muted" id="kb-section-description"><?= e($s['description']) ?></p><?php endif; ?>
    <?php if ($children !== []): ?><div class="list-group mb-3" id="kb-section-children"><?php foreach ($children as $c): ?><a href="/kb/s/<?= e($c['slug']) ?>" class="list-group-item list-group-item-action btn-touch" id="kb-section-child-<?= (int) $c['section_id'] ?>"><i class="feather-folder me-2 text-muted"></i><?= e($c['name']) ?> <span class="text-muted fs-12">· <?= (int) $c['article_count'] ?></span></a><?php endforeach; ?></div><?php endif; ?>
    <?php if ($rows === []): ?><div class="card"><div class="card-body text-muted" id="kb-section-empty">No articles here yet.</div></div>
    <?php else: ?><div class="list-group" id="kb-section-articles"><?php foreach ($rows as $a): ?><?= view('kb/partials/article-row.php', ['a' => $a]) ?><?php endforeach; ?></div><?php endif; ?>
</div>
