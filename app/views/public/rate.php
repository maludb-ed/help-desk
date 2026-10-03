<?php /** The rating block on the secure page (and the survey link's page). Data: T, token, rating (row), ratingToken (?string), notice */ $cs = $T['csat']; $done = $rating['responded_at'] !== null; ?>
<div class="card mb-3" id="public-rate"><div class="card-body">
    <h6 class="fw-bold mb-2" id="public-rate-question"><?= e($cs['question']) ?></h6>
    <?php if ($done): ?><div class="text-muted fs-12" id="public-rate-done">Thank you — already received.</div>
    <?php else: ?>
    <form method="post" action="/t/<?= e($token) ?>/rate" id="public-rate-form">
        <?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><?php if ($ratingToken !== null): ?><input type="hidden" name="r" value="<?= e($ratingToken) ?>"><?php endif; ?>
        <?php if ($cs['scale'] === 'good_bad'): ?>
            <div class="d-flex gap-2 mb-3" id="public-rate-scale">
                <label class="btn btn-outline-success btn-touch flex-grow-1 chip-check position-relative mb-0" for="public-rate-good"><input type="radio" name="score" value="5" id="public-rate-good" required><i class="feather-thumbs-up me-1"></i>Good</label>
                <label class="btn btn-outline-danger btn-touch flex-grow-1 chip-check position-relative mb-0" for="public-rate-bad"><input type="radio" name="score" value="1" id="public-rate-bad"><i class="feather-thumbs-down me-1"></i>Bad</label>
            </div>
        <?php else: ?>
            <div class="d-flex gap-1 mb-3" id="public-rate-scale"><?php for ($i = 1; $i <= 5; $i++): ?><label class="btn btn-outline-primary btn-touch flex-grow-1 chip-check position-relative mb-0" for="public-rate-<?= $i ?>"><input type="radio" name="score" value="<?= $i ?>" id="public-rate-<?= $i ?>" <?= $i === 1 ? 'required' : '' ?>><?= $i ?></label><?php endfor; ?></div>
        <?php endif; ?>
        <label class="form-label fs-12 text-muted" for="public-rate-reason">If it was bad, why?</label>
        <select name="bad_reason" id="public-rate-reason" class="form-select btn-touch mb-2"><option value="">—</option><?php foreach ($cs['bad_reasons'] as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?></select>
        <label class="form-label fs-12 text-muted" for="public-rate-comment">Anything to add?</label>
        <textarea name="comment" id="public-rate-comment" class="form-control mb-3" rows="2" maxlength="2000"></textarea>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="public-rate-btn">Send my rating</button>
    </form>
    <?php endif; ?>
</div></div>
