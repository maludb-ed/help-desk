<?php /** A rule's runs (screen `rule-runs`): the last 30 days, 50 a page. Data: r, rows, page, more, tz, here */ $id = (int) $r['rule_id']; ?>
<?= view('shared/header.php', ['id' => 'rule-runs', 'title' => $r['name'] . ' — runs', 'crumbs' => [['Home', '/'], ['Automation', '/settings/automation/'], [$r['name'], null]], 'back' => ['/settings/automation/', 'Automation']]) ?>
<div class="main-content" id="rule-runs-content">
    <div class="fs-12 text-muted mb-3" id="rule-runs-words"><?= e(rule_words($r)) ?> · runs <?= (int) $r['run_count'] ?> · last <?= e(ago_words($r['last_run_at'])) ?></div>
    <div class="card" id="rule-runs-table"><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>When</th><th>Ticket</th><th>Outcome</th><th>Detail</th></tr></thead><tbody>
        <?php if ($rows === []): ?><tr><td colspan="4" class="text-center text-muted py-4" id="rule-runs-empty">No runs in the last 30 days.</td></tr><?php endif; ?>
        <?php foreach ($rows as $x): $d = $x['detail']; $words = isset($d['error']) ? $d['error'] : implode('; ', array_map(static fn (array $a): string => $a['do'] . ' ' . $a['outcome'] . ($a['detail'] !== null && $a['detail'] !== '' ? ' (' . $a['detail'] . ')' : ''), (array) ($d['actions'] ?? []))); ?>
            <tr id="rule-run-<?= (int) $x['run_id'] ?>"><td class="text-nowrap text-muted"><?= e(format_ts($x['ran_at'], $tz, 'M j, g:i A')) ?></td><td class="text-nowrap"><?= $x['ticket_id'] !== null ? hx_link(with_back('/tickets/' . $x['ticket_id'], $here), e($x['number'] ?? '#' . $x['ticket_id']), 'fw-semibold') : '—' ?></td>
                <td><span class="badge bg-<?= $x['outcome'] === 'applied' ? 'success' : ($x['outcome'] === 'error' ? 'danger' : 'secondary') ?>"><?= e($x['outcome']) ?></span></td><td class="<?= $x['outcome'] === 'error' ? 'text-danger' : 'text-muted' ?>"><?= e(mb_substr($words, 0, 300)) ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div></div></div>
    <?php if ($page > 1 || $more): ?><nav class="py-2"><ul class="pagination mb-0"><?php if ($page > 1): ?><li class="page-item"><?= hx_link('/settings/automation/' . $id . '/runs?page=' . ($page - 1), 'Newer', 'page-link') ?></li><?php endif; ?><?php if ($more): ?><li class="page-item"><?= hx_link('/settings/automation/' . $id . '/runs?page=' . ($page + 1), 'Older', 'page-link') ?></li><?php endif; ?></ul></nav><?php endif; ?>
</div>
