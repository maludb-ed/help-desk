<?php /** The secure ticket page (screen `secure-ticket`). Data: T, token, may, rating (row|null), ratingToken, notice ([kind, text]|null), closedReply (bool) */ $tz = $T['timezone']; ?>
<?php if ($notice !== null): ?><div class="alert alert-<?= e($notice[0]) ?> fs-12" id="public-notice"><?= e($notice[1]) ?></div><?php endif; ?>
<div class="card mb-3" id="public-head"><div class="card-body">
    <div class="fs-12 text-muted" id="public-number"><?= e($T['number']) ?><?= $T['organization_name'] ? ' · ' . e($T['organization_name']) : '' ?></div>
    <h5 class="mb-1" id="public-subject"><?= e($T['subject']) ?></h5>
    <span class="badge bg-<?= in_array($T['status_category'], ['solved', 'closed'], true) ? 'success' : ($T['status_category'] === 'pending' ? 'warning' : 'primary') ?>" id="public-status"><?= e($T['status_words']) ?></span>
</div></div>
<?php if ($may['confirm']): ?>
<div class="card mb-3" id="public-confirm"><div class="card-body">
    <div class="fw-semibold mb-2">Did this resolve it?</div>
    <div class="d-flex gap-2">
        <form method="post" action="/t/<?= e($token) ?>/confirm" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>"><button type="submit" class="btn btn-success btn-touch w-100" id="public-confirm-btn"><i class="feather-check me-1"></i>Yes, resolved</button></form>
        <a href="#public-reply" class="btn btn-light btn-touch flex-grow-1" id="public-still-btn">No, still a problem</a>
    </div>
</div></div>
<?php endif; ?>
<?php if ($may['reopen']): ?>
<div class="card mb-3" id="public-reopen"><div class="card-body">
    <div class="fw-semibold mb-2">Not solved after all?</div>
    <form method="post" action="/t/<?= e($token) ?>/reopen" id="public-reopen-form"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <textarea name="reason" id="public-reopen-reason" class="form-control mb-2" rows="2" maxlength="20000" placeholder="What is still wrong?"></textarea>
        <button type="submit" class="btn btn-warning btn-touch w-100" id="public-reopen-btn"><i class="feather-rotate-ccw me-1"></i>Reopen it</button></form>
</div></div>
<?php elseif ($T['status_category'] === 'solved' && !$T['within_window']): ?>
<div class="card mb-3" id="public-reopen-late"><div class="card-body fs-12">This was closed on <?= e(format_ts($T['solved_at'], $tz, 'M j, Y')) ?>. <a href="/request" id="public-new-request">Start a new request</a>.</div></div>
<?php endif; ?>
<?php if ($may['rate'] && $rating !== null): ?><?= view('public/rate.php', ['T' => $T, 'token' => $token, 'rating' => $rating, 'ratingToken' => $ratingToken, 'notice' => null]) ?><?php endif; ?>
<div id="public-conversation">
    <?php foreach ($T['messages'] as $m): ?><?= view('public/partials/public-message.php', ['m' => $m, 'token' => $token, 'tz' => $tz]) ?><?php endforeach; ?>
    <?php if ($T['messages'] === []): ?><div class="card mb-2"><div class="card-body text-muted fs-12">Nothing written yet.</div></div><?php endif; ?>
</div>
<?php if ($may['reply']): ?>
<div class="card mt-3" id="public-reply"><div class="card-body">
    <div class="fw-semibold mb-2">Add to it</div>
    <form method="post" action="/t/<?= e($token) ?>/reply" enctype="multipart/form-data" id="public-reply-form"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
        <textarea name="body" id="public-reply-body" class="form-control mb-2 composer-body" rows="4" required maxlength="20000" placeholder="Write to us…"></textarea>
        <input type="file" name="files[]" id="public-reply-files" class="form-control btn-touch mb-2" multiple>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="public-reply-btn"><i class="feather-send me-1"></i>Send</button></form>
</div></div>
<?php else: ?>
<div class="card mt-3" id="public-closed"><div class="card-body fs-12">This request is closed. <a href="/request" id="public-new-request-closed">Start a new request</a>.</div></div>
<?php endif; ?>
