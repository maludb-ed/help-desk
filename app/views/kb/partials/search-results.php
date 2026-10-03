<?php /** The search's answer (Pattern A, #kb-results). Data: q, rows, filter, askUrl */ ?>
<div id="kb-results">
    <?php if ($q === ''): ?>
    <?php elseif (mb_strlen($q) < 2): ?><div class="fs-12 text-muted" id="kb-results-short">Type at least two characters.</div>
    <?php elseif ($rows === []): ?><div class="card"><div class="card-body text-muted" id="kb-results-empty">Nothing matches '<?= e($q) ?>'. Try another word, or <a href="<?= e($askUrl) ?>" id="kb-results-ask">file a request</a>.</div></div>
    <?php else: ?><div class="list-group" id="kb-results-list"><?php foreach ($rows as $a): ?><?= view('kb/partials/article-row.php', ['a' => $a]) ?><?php endforeach; ?></div><?php endif; ?>
</div>
