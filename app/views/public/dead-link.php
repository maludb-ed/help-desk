<?php /** One page for a malformed, unknown, rotated or expired link. Data: address (string|null) */ ?>
<div class="card" id="dead-link"><div class="card-body text-center py-4">
    <span class="avatar-text avatar-lg bg-soft-secondary text-secondary rounded-circle mb-3"><i class="feather-link-2 fs-3"></i></span>
    <h5 class="mb-2">This link no longer works.</h5>
    <p class="fs-12 text-muted mb-0">Reply to any email from us about your request<?= $address !== null ? ', or write to <a href="mailto:' . e($address) . '">' . e($address) . '</a>' : '' ?>.</p>
</div></div>
