<?php /** The thank-you page: the number and the address as typed, nothing else. Data: number (string|null), email */ ?>
<div class="card" id="request-done"><div class="card-body text-center py-4">
    <span class="avatar-text avatar-lg bg-soft-success text-success rounded-circle mb-3"><i class="feather-check fs-3"></i></span>
    <h5 class="mb-2">We have your request<?= $number !== null ? ' — <span id="request-done-number">' . e($number) . '</span>' : '' ?>.</h5>
    <p class="fs-12 text-muted mb-0">An email with a link to follow it is on its way to <strong id="request-done-email"><?= e($email) ?></strong>.</p>
</div></div>
