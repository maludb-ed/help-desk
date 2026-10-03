<?php /** The composer's article picker (Pattern A, under the composer). Data: rows, ticket, q, external (bool: the requester is external) */ ?>
<div class="card mt-2" id="article-picker-card"><div class="card-body p-2">
    <div class="d-flex gap-1 mb-2">
        <input type="search" name="q" id="article-picker-q" class="form-control btn-touch" placeholder="Find an article…" value="<?= e($q) ?>" maxlength="80" aria-label="Find an article" hx-get="/kb/?picker=1&amp;ticket=<?= (int) $ticket ?>" hx-trigger="keyup changed delay:300ms" hx-target="#article-picker" hx-swap="innerHTML">
        <button type="button" class="btn btn-light btn-touch" id="article-picker-close" aria-label="Close" onclick="document.getElementById('article-picker').innerHTML = ''"><i class="feather-x"></i></button>
    </div>
    <?php if ($rows === []): ?><div class="text-muted fs-12 px-1" id="article-picker-empty">No article matches.</div><?php endif; ?>
    <div class="list-group list-group-flush" id="article-picker-list">
    <?php foreach ($rows as $a): $aid = (int) $a['article_id']; $internalToExternal = $external && $a['visibility'] === 'internal'; ?>
        <div class="list-group-item" id="article-pick-<?= $aid ?>">
            <div class="d-flex justify-content-between gap-2 align-items-start">
                <div class="min-w-0"><div class="fw-semibold fs-12"><?= e($a['title']) ?><?= $a['visibility'] === 'internal' ? ' <span class="badge bg-soft-secondary text-secondary">Internal</span>' : '' ?></div><div class="fs-11 text-muted"><?= e(article_summary($a, 120)) ?></div></div>
                <div class="d-flex gap-1">
                    <form method="post" action="/kb/admin/link.php" hx-post="/kb/admin/link.php" hx-target="#flash" class="mb-0"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= (int) $ticket ?>"><input type="hidden" name="article" value="<?= $aid ?>"><button type="submit" class="btn btn-light btn-touch" id="article-pick-<?= $aid ?>-suggest" title="Suggest">Suggest</button></form>
                    <form method="post" action="/kb/admin/send.php" hx-post="/kb/admin/send.php" hx-target="#flash" class="mb-0"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= (int) $ticket ?>"><input type="hidden" name="article" value="<?= $aid ?>"><button type="submit" class="btn btn-primary btn-touch" id="article-pick-<?= $aid ?>-send" title="<?= $internalToExternal ? 'Internal — cannot be sent to an outside requester' : 'Send as a reply' ?>" <?= $internalToExternal ? 'disabled' : '' ?>>Send</button></form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
</div></div>
