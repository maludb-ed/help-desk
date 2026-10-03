<?php /** The rendered text and the side-effect chips (the form's live preview, Pattern A). Data: text, words (chips), unknown (placeholders) */ ?>
<div id="canned-preview">
    <?php if ($unknown !== []): ?><div class="alert alert-warning fs-12 py-2 mb-2" id="canned-preview-unknown"><?= e(implode(', ', array_map(static fn (string $k): string => '{{' . $k . '}}', $unknown))) ?> <?= count($unknown) === 1 ? 'is not a placeholder.' : 'are not placeholders.' ?></div><?php endif; ?>
    <div class="message-body fs-12 border rounded p-2 bg-light" id="canned-preview-text"><?= e($text) ?></div>
    <?php if ($words !== []): ?><div class="d-flex flex-wrap gap-1 mt-2" id="canned-preview-effects"><?php foreach ($words as $w): ?><span class="badge bg-soft-primary text-primary"><?= e($w) ?></span><?php endforeach; ?></div><?php endif; ?>
</div>
