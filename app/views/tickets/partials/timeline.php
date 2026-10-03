<?php /** The timeline, newest first, in words. Data: history, internals, tz */ ?>
<div class="card" id="ticket-timeline"><div class="card-header"><h5 class="card-title mb-0">Timeline</h5></div><div class="card-body fs-12">
    <?php $shown = 0; foreach ($history as $r): if (!$internals && history_is_internal($r)) { continue; } $shown++; ?>
        <div class="d-flex gap-2 py-1 border-bottom" id="timeline-<?= (int) $r['activity_id'] ?>"><span class="text-muted text-nowrap"><?= e(format_ts($r['occurred_at'], $tz, 'M j, g:i A')) ?></span><span><?= e(history_sentence($r)) ?></span></div>
    <?php endforeach; ?>
    <?php if ($shown === 0): ?><div class="text-muted" id="ticket-timeline-empty">Nothing yet.</div><?php endif; ?>
</div></div>
