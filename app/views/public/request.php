<?php /** The public request form (screen `request-form`). Data: categories, values, errors, max (bytes) */ $v = $values; $err = static fn (string $k): string => isset($errors[$k]) ? '<div class="text-danger fs-12 mt-1" id="request-error-' . $k . '">' . e($errors[$k]) . '</div>' : ''; ?>
<div class="card" id="request-form-card"><div class="card-body">
    <h5 class="mb-1">Ask for help</h5>
    <p class="fs-12 text-muted">Tell us who you are and what is going on. We will email you a link to follow it.</p>
    <?php if ($errors !== []): ?><div class="alert alert-danger fs-12" id="request-errors">Please check the form.</div><?php endif; ?>
    <form method="post" action="/request" enctype="multipart/form-data" id="request-form">
        <?= csrf_field() ?>
        <div class="d-none" aria-hidden="true"><label for="request-field-website">Website</label><input type="text" name="website" id="request-field-website" tabindex="-1" autocomplete="off" value=""></div>
        <label class="form-label fs-12 text-muted" for="request-field-name">Your name</label>
        <input type="text" name="name" id="request-field-name" class="form-control btn-touch mb-1" maxlength="120" required value="<?= e($v['name'] ?? '') ?>"><?= $err('name') ?>
        <label class="form-label fs-12 text-muted mt-2" for="request-field-email">Your email</label>
        <input type="email" name="email" id="request-field-email" class="form-control btn-touch mb-1" maxlength="200" required value="<?= e($v['email'] ?? '') ?>"><?= $err('email') ?>
        <?php if ($categories !== []): ?>
        <label class="form-label fs-12 text-muted mt-2" for="request-field-category">What is it about</label>
        <select name="category" id="request-field-category" class="form-select btn-touch mb-1"><option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['category_id'] ?>" <?= (string) ($v['category'] ?? '') === (string) $c['category_id'] ? 'selected' : '' ?>><?= e($c['path'] ?? $c['name']) ?></option><?php endforeach; ?></select><?= $err('category') ?>
        <?php endif; ?>
        <label class="form-label fs-12 text-muted mt-2" for="request-field-subject">Subject</label>
        <input type="text" name="subject" id="request-field-subject" class="form-control btn-touch mb-1" maxlength="300" required value="<?= e($v['subject'] ?? '') ?>"><?= $err('subject') ?>
        <label class="form-label fs-12 text-muted mt-2" for="request-field-body">What is going on</label>
        <textarea name="body" id="request-field-body" class="form-control mb-1" rows="6" required maxlength="20000"><?= e($v['body'] ?? '') ?></textarea><?= $err('body') ?>
        <label class="form-label fs-12 text-muted mt-2" for="request-field-files">Attachments (up to three)</label>
        <input type="file" name="files[]" id="request-field-files" class="form-control btn-touch mb-1" multiple><?= $err('files') ?>
        <div class="fs-11 text-muted mb-3">Up to <?= $max >= 1048576 ? intdiv($max, 1048576) . ' MB' : intdiv($max, 1024) . ' KB' ?> each; images, PDFs, text, office documents, zip.</div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="request-submit-btn">Send it</button>
    </form>
</div></div>
