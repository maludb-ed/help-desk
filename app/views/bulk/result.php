<?php /** The outcome of a bulk change. Data: targets (by id), changed, refused, here */ $byId = []; foreach ($targets as $t) { $byId[$t['ticket_id']] = $t; } $why = []; foreach ($refused as $r) { $why[$r['ticket_id']] = $r['why']; } ?>
<?= view('shared/header.php', ['id' => 'bulk-result', 'title' => count($changed) . ' changed' . ($refused !== [] ? ', ' . count($refused) . ' refused' : ''), 'crumbs' => [['Home', '/'], ['Inbox', '/inbox'], ['Change selected', null]], 'back' => ['/inbox', 'Inbox']]) ?>
<div class="main-content" id="bulk-result-content">
    <div class="d-flex gap-2 mb-3" id="bulk-result-counts"><span class="badge bg-success fs-12" id="bulk-result-changed"><?= count($changed) ?> changed</span><?php if ($refused !== []): ?><span class="badge bg-danger fs-12" id="bulk-result-refused"><?= count($refused) ?> refused</span><?php endif; ?></div>
    <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12" id="bulk-result-table"><thead class="thead-light"><tr><th>#</th><th>Subject</th><th>Outcome</th></tr></thead><tbody>
        <?php foreach (array_merge($changed, array_map(static fn (array $r): int => $r['ticket_id'], $refused)) as $id): $t = $byId[$id] ?? null; ?>
            <tr id="bulk-result-<?= (int) $id ?>"><td class="text-nowrap"><?= $t ? hx_link(with_back('/tickets/' . (int) $id, $here), e($t['number']), 'fw-semibold') : '#' . (int) $id ?></td><td><?= e($t['subject'] ?? '') ?></td>
                <td><?= isset($why[$id]) ? '<span class="text-danger"><i class="feather-x"></i> ' . e($why[$id]) . '</span>' : '<span class="text-success"><i class="feather-check"></i> changed</span>' ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
    <div class="mt-3"><?= hx_link('/inbox', 'Back to the inbox', 'btn btn-light btn-touch w-100', 'id="bulk-result-back"') ?></div>
</div>
