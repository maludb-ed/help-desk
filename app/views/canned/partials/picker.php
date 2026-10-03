<?php /** The composer's canned picker (Pattern A, under the composer, never a modal). Data: rows, ticket (id), q */ ?>
<div class="card mt-2" id="canned-picker-card">
    <div class="card-body p-2">
        <div class="d-flex gap-1 mb-2">
            <input type="search" name="q" id="canned-picker-q" class="form-control btn-touch" placeholder="Find a canned response…" value="<?= e($q) ?>" maxlength="80" aria-label="Find a canned response"
                   hx-get="/canned/?picker=1&amp;ticket=<?= (int) $ticket ?>" hx-trigger="keyup changed delay:300ms" hx-target="#canned-picker" hx-swap="innerHTML">
            <button type="button" class="btn btn-light btn-touch" id="canned-picker-close" aria-label="Close" onclick="document.getElementById('canned-picker').innerHTML = ''"><i class="feather-x"></i></button>
        </div>
        <?php if ($rows === []): ?><div class="text-muted fs-12 px-1" id="canned-picker-empty">No canned responses<?= $q !== '' ? ' match' : ' yet — write one from a reply you keep typing' ?>.</div><?php endif; ?>
        <div class="list-group list-group-flush" id="canned-picker-list">
        <?php foreach ($rows as $c): $cid = (int) $c['canned_id']; ?>
            <button type="button" class="list-group-item list-group-item-action btn-touch text-start" id="canned-pick-<?= $cid ?>"
                    hx-post="/canned/apply.php" hx-vals='{"ticket": <?= (int) $ticket ?>, "canned": <?= $cid ?>, "send": "no"}' hx-include="#ticket-reply-form-field-body, #ticket-reply-caret" hx-target="#ticket-reply-form-field-body" hx-swap="outerHTML">
                <div class="d-flex justify-content-between gap-2"><span class="fw-semibold fs-12"><?= e($c['title']) ?></span><?php if ($c['folder']): ?><span class="badge bg-soft-secondary text-secondary"><?= e($c['folder']) ?></span><?php endif; ?></div>
                <div class="fs-11 text-muted text-truncate"><?= e(canned_first_line($c['body_text'])) ?></div>
                <?php if (($w = side_effect_words($c['side_effects'])) !== []): ?><div class="d-flex flex-wrap gap-1 mt-1"><?php foreach ($w as $chip): ?><span class="badge bg-soft-primary text-primary"><?= e($chip) ?></span><?php endforeach; ?></div><?php endif; ?>
            </button>
        <?php endforeach; ?>
        </div>
    </div>
</div>
