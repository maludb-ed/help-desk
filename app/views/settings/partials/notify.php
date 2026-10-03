<?php /** The "How I am told" tab (slice 7): the choices (Phase 2's prefs partial) and what was sent lately — each notice's channel and outcome, never a body. Data: prefs, refusal, osChannels, recent */ ?>
<?= view('settings/partials/prefs.php', ['prefs' => $prefs, 'refusal' => $refusal, 'osChannels' => $osChannels]) ?>
<?php if ($recent !== []): ?>
<div class="card mt-3" id="prefs-recent"><div class="card-header"><h5 class="card-title mb-0">Sent to you lately</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table mb-0 fs-12"><thead class="thead-light"><tr><th>When</th><th>What</th><th>By</th><th>Outcome</th></tr></thead><tbody>
    <?php foreach ($recent as $r): ?><tr id="prefs-recent-<?= (int) $r['id'] ?>"><td class="text-nowrap text-muted"><?= e(format_ts($r['created_at'], member_timezone(), 'M j, g:i A')) ?></td><td><?= e($r['subject'] ?? $r['kind']) ?></td><td><?= e($r['channel']) ?></td><td><span class="badge bg-soft-<?= $r['status'] === 'sent' ? 'success text-success' : ($r['status'] === 'queued' ? 'secondary text-secondary' : 'warning text-warning') ?>"><?= e($r['status']) ?><?= $r['status'] === 'skipped' && $r['detail'] ? ' · ' . e($r['detail']) : '' ?></span></td></tr><?php endforeach; ?>
</tbody></table></div></div></div>
<?php endif; ?>
