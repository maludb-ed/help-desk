<?php /** A report's table: columns (strings), rows (string cells), totals (string cells|null), id, danger (fn(row, i) => bool, optional) */ $id = $id ?? 'report-table'; ?>
<div class="table-responsive" id="<?= e($id) ?>-wrap"><table class="table table-hover table-sm mb-0 fs-12 text-nowrap" id="<?= e($id) ?>">
    <thead class="thead-light"><tr><?php foreach ($columns as $i => $c): ?><th class="<?= $i === 0 ? '' : 'text-end' ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php if ($rows === []): ?><tr><td colspan="<?= count($columns) ?>" class="text-center text-muted py-4" id="<?= e($id) ?>-empty">Nothing in this period.</td></tr><?php endif; ?>
    <?php foreach ($rows as $i => $r): ?><tr id="<?= e($id) ?>-row-<?= $i ?>"><?php foreach ($r as $j => $cell): ?><td class="<?= $j === 0 ? 'fw-semibold' : 'text-end' ?>"><?= e($cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody>
    <?php if (!empty($totals) && $rows !== []): ?><tfoot><tr class="fw-bold" id="<?= e($id) ?>-totals"><?php foreach ($totals as $j => $cell): ?><td class="<?= $j === 0 ? '' : 'text-end' ?>"><?= e($cell) ?></td><?php endforeach; ?></tr></tfoot><?php endif; ?>
</table></div>
