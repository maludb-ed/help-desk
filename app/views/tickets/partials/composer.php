<?php /** The composer: Reply (work, or the ticket's own requester) and Note (note+). Data: t, facts, moves, work, own, note, pending, members, here, articles (slice 9: the linked articles, for work) */ $id = (int) $facts['ticket_id']; $articles = $articles ?? []; $extRequester = ($facts['requester_kind'] ?? '') === 'external'; $canReply = $work || $own; $closed = $facts['status_category'] === 'closed'; ?>
<div class="card" id="ticket-composer">
    <?php if ($closed): ?><div class="card-body text-muted fs-12" id="ticket-composer-closed">This ticket is closed. <?= $own ? 'A new request mentions ' . e($facts['number']) . '.' : 'A follow-up is a new ticket.' ?></div>
    <?php else: ?>
    <?php $suggested = array_values(array_filter($articles, static fn (array $l): bool => $l['kind'] === 'suggested')); if ($work && $suggested !== []): ?>
    <div class="card-body border-bottom py-2" id="ticket-suggested-articles">
        <div class="fs-12 fw-semibold mb-1"><i class="feather-book-open me-1"></i>Suggested articles</div>
        <?php foreach ($suggested as $l): $aid = (int) $l['article_id']; $cannot = $extRequester && $l['visibility'] === 'internal'; ?>
        <div class="d-flex align-items-center gap-2 flex-wrap py-1" id="ticket-suggested-article-<?= $aid ?>">
            <a href="/kb/<?= e($l['slug']) ?>" class="fw-semibold fs-12 flex-grow-1"><?= e($l['title']) ?><?= $l['visibility'] === 'internal' ? ' <span class="badge bg-soft-secondary text-secondary">Internal</span>' : '' ?></a>
            <form method="post" action="/kb/admin/send.php" hx-post="/kb/admin/send.php" hx-target="#flash" class="mb-0"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="article" value="<?= $aid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-primary btn-sm btn-touch" id="ticket-suggested-article-<?= $aid ?>-send" <?= $cannot || $closed ? 'disabled' : '' ?> title="<?= $cannot ? 'Internal — cannot be sent to an outside requester' : 'Send as a reply' ?>">Send</button></form>
            <form method="post" action="/kb/admin/link.php" hx-post="/kb/admin/link.php" hx-target="#flash" class="mb-0"><?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="article" value="<?= $aid ?>"><input type="hidden" name="remove" value="1"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="ticket-suggested-article-<?= $aid ?>-dismiss">Dismiss</button></form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($pending !== []): ?><div class="card-body border-bottom py-2 fs-12" id="ticket-composer-pending"><i class="feather-paperclip me-1"></i>Attached to your next message: <?php foreach ($pending as $a): ?><span class="badge bg-soft-secondary text-secondary"><?= e($a['filename']) ?></span> <?php endforeach; ?></div><?php endif; ?>
    <?php if ($canReply): ?>
    <details class="card-body border-bottom" id="ticket-composer-reply" open>
        <summary class="fw-semibold btn-touch d-flex align-items-center"><i class="feather-corner-up-left me-2"></i>Reply<?= $own && !$work ? '' : ' <span class="fs-12 text-muted ms-2">goes to the requester</span>' ?></summary>
        <form method="post" action="/tickets/reply.php" hx-post="/tickets/reply.php" hx-target="#flash" class="mt-2" id="ticket-reply-form">
            <?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <?php foreach ($pending as $a): ?><input type="hidden" name="attachments[]" value="<?= (int) $a['attachment_id'] ?>"><?php endforeach; ?>
            <textarea name="body" id="ticket-reply-form-field-body" class="form-control mb-2 composer-body" rows="4" required maxlength="20000" placeholder="Write to the requester…"></textarea>
            <input type="hidden" name="caret" id="ticket-reply-caret" value="">
            <div id="ticket-reply-canned-effects" class="mb-2"></div>
            <?php if ($work): ?>
            <div class="row g-2 align-items-end mb-2">
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted mb-0" for="ticket-reply-form-field-then">Then set status</label>
                    <select name="then_status" id="ticket-reply-form-field-then" class="form-select btn-touch"><option value="">Leave it</option><?php foreach ($moves as $m): if (!$m['may']) { continue; } ?><option value="<?= e($m['key']) ?>"><?= e($m['label']) ?><?= in_array('resolution_code', $m['requires'], true) ? ' (needs a code)' : '' ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted mb-0" for="ticket-reply-form-field-code">Resolution code (when solving)</label>
                    <select name="resolution_code" id="ticket-reply-form-field-code" class="form-select btn-touch"><option value="">—</option><?php foreach (RESOLUTION_CODES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-2">
                <?php if ($work): ?><button type="button" class="btn btn-light btn-touch" id="ticket-reply-canned-btn" hx-get="/canned/?picker=1&amp;ticket=<?= $id ?>" hx-target="#canned-picker" hx-swap="innerHTML"><i class="feather-file-text me-1"></i>Canned ▾</button>
                <button type="button" class="btn btn-light btn-touch" id="ticket-reply-article-btn" hx-get="/kb/?picker=1&amp;ticket=<?= $id ?>" hx-target="#article-picker" hx-swap="innerHTML" title="Suggest or send an article"><i class="feather-book-open me-1"></i>Article ▾</button><?php endif; ?>
                <button type="submit" class="btn btn-primary btn-touch flex-grow-1" id="ticket-reply-form-send-btn"><i class="feather-send me-1"></i>Send reply</button>
            </div>
        </form>
        <div id="canned-picker"></div>
        <div id="article-picker"></div>
        <script>(function () { var ta = document.getElementById('ticket-reply-form-field-body'), c = document.getElementById('ticket-reply-caret'); if (!ta || !c) return; ['keyup', 'click', 'select', 'blur'].forEach(function (ev) { ta.addEventListener(ev, function () { c.value = ta.selectionStart; }); }); })();</script>
    </details>
    <?php endif; ?>
    <?php if ($note): ?>
    <details class="card-body border-bottom" id="ticket-composer-note" <?= $canReply ? '' : 'open' ?>>
        <summary class="fw-semibold btn-touch d-flex align-items-center"><i class="feather-lock me-2"></i>Note <span class="fs-12 text-muted ms-2">never leaves the queue</span></summary>
        <form method="post" action="/tickets/note.php" hx-post="/tickets/note.php" hx-target="#flash" class="mt-2" id="ticket-note-form">
            <?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <?php foreach ($pending as $a): ?><input type="hidden" name="attachments[]" value="<?= (int) $a['attachment_id'] ?>"><?php endforeach; ?>
            <textarea name="body" id="ticket-note-form-field-body" class="form-control mb-2 composer-body" rows="3" required maxlength="20000" placeholder="A note for the queue… @ to mention"></textarea>
            <label class="form-label fs-12 text-muted mb-0" for="ticket-note-form-field-mentions">Mention (tells them)</label>
            <input type="hidden" name="mentions[]" value="">
            <select name="mentions[]" id="ticket-note-form-field-mentions" class="form-select btn-touch mb-2" multiple size="3"><?php foreach ($members as $m): ?><option value="<?= (int) $m['member_id'] ?>"><?= e($m['display_name']) ?><?= $m['member_kind'] === 'agent' ? ' (agent)' : '' ?></option><?php endforeach; ?></select>
            <button type="submit" class="btn btn-warning btn-touch w-100" id="ticket-note-form-send-btn"><i class="feather-edit-3 me-1"></i>Add note</button>
        </form>
    </details>
    <?php endif; ?>
    <?php if ($canReply): ?>
    <div class="card-body py-2">
        <form method="post" action="/tickets/attachments/save.php" enctype="multipart/form-data" class="d-flex gap-2 align-items-center" id="ticket-attach-form">
            <?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <input type="file" name="file" id="ticket-attach-form-field-file" class="form-control form-control-sm" required>
            <button type="submit" class="btn btn-light btn-sm btn-touch" id="ticket-attach-form-btn"><i class="feather-paperclip"></i></button>
        </form>
        <div class="fs-11 text-muted mt-1">Up to <?= attachment_max_bytes() >= 1048576 ? intdiv(attachment_max_bytes(), 1048576) . ' MB' : intdiv(attachment_max_bytes(), 1024) . ' KB' ?> each; images, PDFs, text, office documents, zip.</div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
