<?php /** Merge a requester into another (tickets.manage). Data: r (the one that goes away), candidates (the search's), q, into (the chosen, or null), here */ $id = (int) $r['requester_id']; ?>
<?= view('shared/header.php', ['id' => 'requester-merge', 'title' => 'Merge ' . $r['display_name'], 'crumbs' => [['Home', '/'], ['Requesters', '/requesters/'], [$r['display_name'], '/requesters/' . $id], ['Merge', null]], 'back' => back_link() ?? ['/requesters/' . $id, 'the requester']]) ?>
<div class="main-content" id="requester-merge-content">
    <?php if ($into === null): ?>
        <form method="get" action="/requesters/merge.php" hx-get="/requesters/merge.php" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="requester-merge-search"><div class="card-body">
            <input type="hidden" name="requester" value="<?= $id ?>">
            <label class="form-label fs-12 text-muted" for="requester-merge-field-q">Who stays? Search by name or email</label>
            <div class="input-group"><input type="search" name="q" id="requester-merge-field-q" class="form-control btn-touch" value="<?= e($q) ?>" maxlength="80" autofocus><button type="submit" class="btn btn-light btn-touch" id="requester-merge-search-btn"><i class="feather-search"></i></button></div>
        </div></form>
        <?php if ($q !== '' && $candidates === []): ?><div class="card"><div class="card-body text-muted" id="requester-merge-none">Nobody else matches.</div></div><?php endif; ?>
        <div class="list-group" id="requester-merge-candidates">
            <?php foreach ($candidates as $c): if ((int) $c['requester_id'] === $id) { continue; } ?>
                <?= hx_link('/requesters/merge.php?requester=' . $id . '&into=' . (int) $c['requester_id'], '<span class="fw-semibold">' . e($c['display_name']) . '</span> <span class="badge bg-soft-secondary text-secondary">' . e(REQUESTER_KINDS[$c['kind']]) . '</span><div class="fs-12 text-muted">' . e($c['email'] ?? '') . ' · ' . (int) $c['ticket_count'] . ' tickets</div>', 'list-group-item list-group-item-action py-2', 'id="requester-merge-candidate-' . (int) $c['requester_id'] . '"') ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card" id="requester-merge-confirm"><div class="card-body">
            <p class="mb-3"><strong><?= e($r['display_name']) ?></strong>'s <?= (int) $r['ticket_count'] ?> ticket<?= (int) $r['ticket_count'] === 1 ? '' : 's' ?> and <?= count($r['emails']) ?> address<?= count($r['emails']) === 1 ? '' : 'es' ?> move to <strong><?= e($into['display_name']) ?></strong> (<?= e(REQUESTER_KINDS[$into['kind']]) ?>). <?= e($r['display_name']) ?> goes away. This cannot be undone.</p>
            <form method="post" action="/requesters/merge.php" hx-post="/requesters/merge.php" hx-target="#flash" id="requester-merge-form">
                <?= csrf_field() ?><input type="hidden" name="requester" value="<?= $id ?>"><input type="hidden" name="into" value="<?= (int) $into['requester_id'] ?>">
                <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="requester-merge-save-btn">Merge</button>
                <?= hx_link('/requesters/' . $id, 'Cancel', 'btn btn-light btn-touch w-100', 'id="requester-merge-cancel-link"') ?>
            </form>
        </div></div>
    <?php endif; ?>
</div>
