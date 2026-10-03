<?php /** One public entry: the author's first name (You for the requester), the time, the body, the attachments through the token. Data: m, token, tz */ ?>
<div class="card mb-2 <?= $m['mine'] ? 'border-primary' : '' ?>" id="public-message-<?= (int) $m['message_id'] ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between gap-2 fs-12"><span class="fw-semibold"><?= e($m['author']) ?></span><span class="text-muted"><?= e(format_ts($m['at'], $tz)) ?></span></div>
    <div class="message-body mt-1"><?= e($m['body']) ?></div>
    <?php if ($m['attachments'] !== []): ?><div class="d-flex flex-wrap gap-1 mt-2"><?php foreach ($m['attachments'] as $a): ?><a class="badge bg-soft-secondary text-secondary text-decoration-none" href="/t/<?= e($token) ?>/attachment?id=<?= (int) $a['attachment_id'] ?>" id="public-attachment-<?= (int) $a['attachment_id'] ?>"><i class="feather-paperclip"></i> <?= e($a['filename']) ?></a><?php endforeach; ?></div><?php endif; ?>
</div></div>
