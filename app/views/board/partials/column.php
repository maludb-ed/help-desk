<?php /** One column. Data: col {key, label, color, count, wip, cards}, lane (string|null), field, view, moves, expand, query, tz, here */
$cid = 'board-column-' . ($lane !== null ? $lane . '-' : '') . $col['key']; $over = $col['wip'] !== null && $col['count'] > $col['wip']; ?>
<div class="board-column" id="<?= e($cid) ?>" data-column="<?= e($col['key']) ?>">
    <div class="d-flex align-items-center gap-2 px-3 pt-2 pb-1" id="<?= e($cid) ?>-header">
        <span class="wd-10 ht-10 bg-<?= e($col['color']) ?> d-inline-block rounded-circle"></span>
        <span class="fw-semibold fs-12 text-truncate"><?= e($col['label']) ?></span>
        <span class="fs-11 ms-auto <?= $over ? 'board-wip-over' : 'text-muted' ?>" id="<?= e($cid) ?>-count" title="<?= $col['wip'] !== null ? 'WIP limit ' . (int) $col['wip'] : '' ?>"><?= (int) $col['count'] ?><?= $col['wip'] !== null ? ' / ' . (int) $col['wip'] : '' ?></span>
    </div>
    <div class="board-column-cards" data-board-droppable data-column="<?= e($col['key']) ?>" id="<?= e($cid) ?>-cards">
        <?php foreach ($col['cards'] as $t): ?><?= view('board/partials/card.php', ['t' => $t, 'field' => $field, 'view' => $view, 'moves' => $moves, 'expand' => $expand, 'query' => $query, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?>
        <?php if ($col['cards'] === []): ?><div class="text-muted fs-11 text-center py-2">—</div><?php endif; ?>
    </div>
</div>
