<?php /** The matrix (screen `transitions`). Data: grid, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'transitions', 'title' => 'Transitions', 'crumbs' => [['Home', '/'], ['Statuses', '/settings/statuses/'], ['Transitions', null]], 'back' => back_link() ?? ['/settings/statuses/', 'Statuses']]) ?>
<div class="main-content" id="transitions-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3"><div class="card-body custom-card-action p-0"><div class="table-responsive"><table class="table table-sm mb-0 fs-12 align-top" id="transitions-grid">
        <thead class="thead-light"><tr><th>From ↓ · To →</th><?php foreach (STATUS_CATEGORIES as $t => $tl): ?><th class="text-center"><?= e($tl) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach (STATUS_CATEGORIES as $f => $fl): ?>
            <tr id="transitions-row-<?= e($f) ?>"><th class="fw-semibold text-nowrap"><?= e($fl) ?><?= $f === 'closed' ? ' <span class="fs-11 text-muted">terminal</span>' : '' ?></th>
            <?php foreach (STATUS_CATEGORIES as $t => $tl): ?>
                <td class="text-center" style="min-width: 7rem">
                    <?php if ($f === $t): ?><span class="text-muted text-decoration-line-through" id="transition-<?= e($f) ?>-<?= e($t) ?>-same">same</span>
                    <?php elseif ($f === 'closed'): ?><span class="text-muted" id="transition-<?= e($f) ?>-<?= e($t) ?>-never">never</span>
                    <?php else: ?><?= view('statuses/partials/transition-cell.php', ['from' => $f, 'to' => $t, 'r' => $grid[$f][$t], 'here' => $here]) ?><?php endif; ?>
                </td>
            <?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div></div>
    <div class="card" id="transitions-words"><div class="card-header"><h5 class="card-title mb-0">What the rules say</h5></div><div class="card-body fs-12">
        <ul class="mb-0" id="transitions-words-list">
            <?php foreach ($grid as $f => $row): foreach ($row as $t => $r): if ($r === null) { continue; } ?>
                <li id="transitions-words-<?= e($f) ?>-<?= e($t) ?>"><strong><?= e(STATUS_CATEGORIES[$f]) ?> → <?= e(STATUS_CATEGORIES[$t]) ?></strong>: <?= e(transition_words($r)) ?></li>
            <?php endforeach; endforeach; ?>
        </ul>
        <div class="text-muted mt-2">A move inside one category (Open → a custom open status) is always allowed. Closed is terminal: a requester's reply on a closed ticket makes a follow-up ticket. A requester's own confirm and reopen are the application's, not this table's.</div>
    </div></div>
</div>
