<?php /** One status as a ledger row. Data: s, first, last, here */ $id = (int) $s['status_id']; ?>
<tr id="status-row-<?= $id ?>" class="<?= $s['archived_at'] !== null ? 'text-muted' : '' ?>">
    <td><span class="wd-10 ht-10 bg-<?= e($s['color']) ?> d-inline-block rounded-circle me-2"></span><?= hx_link(with_back('/settings/statuses/' . $id . '/edit', $here), e($s['label']), 'text-dark fw-semibold', 'id="status-row-' . $id . '-label"') ?><?= $s['is_system'] ? ' <i class="feather-lock text-muted" title="Built-in"></i>' : '' ?><?= $s['is_default'] ? ' <span class="badge bg-soft-primary text-primary">Start</span>' : '' ?></td>
    <td class="d-none d-md-table-cell"><code><?= e($s['key']) ?></code></td>
    <td><span class="badge bg-soft-secondary text-secondary" id="status-row-<?= $id ?>-category"><?= e(STATUS_CATEGORIES[$s['category']]) ?></span></td>
    <td class="d-none d-md-table-cell"><?= $s['on_board'] ? '<i class="feather-check text-success"></i>' : '<span class="text-muted">—</span>' ?></td>
    <td class="d-none d-md-table-cell" id="status-row-<?= $id ?>-clock"><?= $s['clock_runs'] ? 'runs' : 'pauses' ?></td>
    <td class="text-end" id="status-row-<?= $id ?>-count"><?= (int) $s['ticket_count'] ?></td>
    <td class="text-end text-nowrap">
        <form method="post" action="/settings/statuses/save.php" hx-post="/settings/statuses/save.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $id ?>"><input type="hidden" name="move" value="up"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="status-row-<?= $id ?>-up-btn" aria-label="Move up" <?= $first ? 'disabled' : '' ?>><i class="feather-arrow-up"></i></button></form>
        <form method="post" action="/settings/statuses/save.php" hx-post="/settings/statuses/save.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $id ?>"><input type="hidden" name="move" value="down"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="status-row-<?= $id ?>-down-btn" aria-label="Move down" <?= $last ? 'disabled' : '' ?>><i class="feather-arrow-down"></i></button></form>
    </td>
</tr>
