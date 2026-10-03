<?php /** The SLA targets, expanded (queue holders). Data: t, tz */ ?>
<div id="ticket-sla">
    <?php if ($t['targets'] === []): ?><div class="fs-12 text-muted">No SLA target<?= $t['sla_policy_id'] === null ? ' (no policy matched)' : '' ?>.</div><?php endif; ?>
    <?php foreach ($t['targets'] as $g): $st = $g['state']; ?>
        <div class="d-flex justify-content-between fs-12 py-1 border-bottom" id="ticket-sla-<?= e($g['metric']) ?>">
            <span><?= e(SLA_METRICS[$g['metric']] ?? $g['metric']) ?></span>
            <span class="<?= $st === 'breached' ? 'text-danger' : ($st === 'at_risk' ? 'text-warning' : ($st === 'met' ? 'text-success' : 'text-muted')) ?>">
                <?php if ($st === 'met'): ?><i class="feather-check"></i> met <?= e(format_ts($g['met_at'], $tz, 'M j, g:i A')) ?>
                <?php elseif ($st === 'paused'): ?><i class="feather-pause"></i> paused
                <?php elseif ($st === 'n/a'): ?>no target
                <?php else: ?><?= e(sla_due_words($g['due_at'], $st)) ?> <small class="text-muted">(<?= e(format_ts($g['due_at'], $tz, 'M j, g:i A')) ?>)</small><?php endif; ?>
            </span>
        </div>
    <?php endforeach; ?>
    <?php if ($t['sla_policy_id'] !== null): ?><div class="fs-11 text-muted mt-1">Policy: <?= e($t['policy_name']) ?></div><?php endif; ?>
</div>
