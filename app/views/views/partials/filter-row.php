<?php /** One row of the filter builder: the key's label and its control (built by the form). Data: key, label, control (html), hint? */ ?>
<div class="row g-2 align-items-start mb-2 border-bottom pb-2" id="view-filter-<?= e($key) ?>">
    <div class="col-12 col-md-3"><div class="fs-12 fw-semibold"><?= e($label) ?></div><?php if (!empty($hint)): ?><div class="fs-11 text-muted"><?= e($hint) ?></div><?php endif; ?><code class="fs-11 text-muted"><?= e($key) ?></code></div>
    <div class="col-12 col-md-9"><?= $control ?></div>
</div>
