<?php /** A member requester rates their own solved ticket (the ticket page). Data: t (facts), rating (row|null), cs (settings) */ $id = (int) $t['ticket_id']; $done = $rating !== null && $rating['responded_at'] !== null; ?>
<div class="card mb-3" id="ticket-rating"><div class="card-body">
    <h6 class="fw-bold mb-2" id="ticket-rating-question"><?= e($cs['question']) ?></h6>
    <?php if ($done): ?><div class="fs-12 text-muted" id="ticket-rating-done">Thank you — you rated it <?= e(score_words((int) $rating['score'], $cs['scale'])) ?>.</div>
    <?php else: ?>
    <form method="post" action="/ratings/save.php" hx-post="/ratings/save.php" hx-target="#flash" id="ticket-rating-form"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>">
        <?php if ($cs['scale'] === 'good_bad'): ?>
            <div class="d-flex gap-2 mb-2" id="ticket-rating-scale"><label class="btn btn-outline-success btn-touch flex-grow-1 chip-check position-relative mb-0" for="ticket-rating-good"><input type="radio" name="score" value="5" id="ticket-rating-good" required><i class="feather-thumbs-up me-1"></i>Good</label><label class="btn btn-outline-danger btn-touch flex-grow-1 chip-check position-relative mb-0" for="ticket-rating-bad"><input type="radio" name="score" value="1" id="ticket-rating-bad"><i class="feather-thumbs-down me-1"></i>Bad</label></div>
        <?php else: ?>
            <div class="d-flex gap-1 mb-2" id="ticket-rating-scale"><?php for ($i = 1; $i <= 5; $i++): ?><label class="btn btn-outline-primary btn-touch flex-grow-1 chip-check position-relative mb-0" for="ticket-rating-<?= $i ?>"><input type="radio" name="score" value="<?= $i ?>" id="ticket-rating-<?= $i ?>" <?= $i === 1 ? 'required' : '' ?>><?= $i ?></label><?php endfor; ?></div>
        <?php endif; ?>
        <select name="bad_reason" id="ticket-rating-reason" class="form-select btn-touch mb-2"><option value="">If it was bad, why?</option><?php foreach ($cs['bad_reasons'] as $r): ?><option value="<?= e($r) ?>"><?= e($r) ?></option><?php endforeach; ?></select>
        <input type="text" name="comment" id="ticket-rating-comment" class="form-control btn-touch mb-2" maxlength="2000" placeholder="Anything to add?">
        <button type="submit" class="btn btn-primary btn-touch w-100" id="ticket-rating-btn">Send my rating</button>
    </form>
    <?php endif; ?>
</div></div>
