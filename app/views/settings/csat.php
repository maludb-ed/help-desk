<?php /** The survey settings (screen `csat-settings`). Data: cs, queues, notice */ ?>
<?= view('shared/header.php', ['id' => 'csat-settings', 'title' => 'Satisfaction survey', 'crumbs' => [['Home', '/'], ['Settings', '/settings/general'], ['Satisfaction', null]]]) ?>
<div class="main-content" id="csat-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="post" action="/settings/csat.php" hx-post="/settings/csat.php" hx-target="#flash" id="csat-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body">
            <input type="hidden" name="enabled" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="csat-form-field-enabled"><input type="checkbox" class="form-check-input mt-0" name="enabled" value="yes" id="csat-form-field-enabled" <?= $cs['enabled'] ? 'checked' : '' ?>>Ask requesters how we did after a ticket is solved</label>
            <label class="form-label fs-12 text-muted">Scale</label>
            <div class="d-flex flex-wrap gap-2 mb-3" id="csat-form-field-scale"><?php foreach (CSAT_SCALES as $k => $l): ?><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="csat-form-field-scale-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="scale" value="<?= e($k) ?>" id="csat-form-field-scale-<?= e($k) ?>" <?= $cs['scale'] === $k ? 'checked' : '' ?>><?= e($l) ?></label><?php endforeach; ?></div>
            <label class="form-label fs-12 text-muted" for="csat-form-field-question">The question</label>
            <input type="text" name="question" id="csat-form-field-question" class="form-control btn-touch mb-3" maxlength="200" required value="<?= e($cs['question']) ?>">
            <div class="row g-2 mb-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="csat-form-field-delay">Send after (minutes)</label><input type="number" name="send_delay_minutes" id="csat-form-field-delay" class="form-control btn-touch" min="0" max="10080" value="<?= (int) $cs['send_delay_minutes'] ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="csat-form-field-window">May be answered for (days)</label><input type="number" name="response_window_days" id="csat-form-field-window" class="form-control btn-touch" min="1" max="90" value="<?= (int) $cs['response_window_days'] ?>"></div>
            </div>
            <div class="fs-12 text-muted mb-3" id="csat-form-sentence">The survey goes <?= (int) $cs['send_delay_minutes'] ?> minutes after a ticket is solved, and may be answered for <?= (int) $cs['response_window_days'] ?> days.</div>
            <label class="form-label fs-12 text-muted" for="csat-form-field-reasons">Reasons for a bad rating (one per line)</label>
            <textarea name="bad_reasons" id="csat-form-field-reasons" class="form-control mb-3" rows="4" maxlength="2000"><?= e(implode("\n", $cs['bad_reasons'])) ?></textarea>
            <label class="form-label fs-12 text-muted">Queues surveyed (none ticked = all)</label>
            <input type="hidden" name="queues[]" value="">
            <div class="d-flex flex-wrap gap-2" id="csat-form-field-queues"><?php foreach ($queues as $q): ?><label class="btn btn-light btn-touch chip-check position-relative mb-0" for="csat-form-field-queues-<?= (int) $q['queue_id'] ?>"><input type="checkbox" name="queues[]" value="<?= (int) $q['queue_id'] ?>" id="csat-form-field-queues-<?= (int) $q['queue_id'] ?>" <?= in_array((int) $q['queue_id'], $cs['queue_ids'], true) ? 'checked' : '' ?>><?= e($q['name']) ?></label><?php endforeach; ?></div>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="csat-form-save-btn">Save</button>
    </form>
    <script>(function () { var d = document.getElementById('csat-form-field-delay'), w = document.getElementById('csat-form-field-window'), s = document.getElementById('csat-form-sentence'); if (!d || !w || !s) return; function say() { s.textContent = 'The survey goes ' + d.value + ' minutes after a ticket is solved, and may be answered for ' + w.value + ' days.'; } d.addEventListener('input', say); w.addEventListener('input', say); })();</script>
</div>
