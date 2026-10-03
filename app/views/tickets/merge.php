<?php /** Merge this ticket into another (screen `ticket-merge`). Data: facts, suggestions, into (facts or null), q, found, here */ $id = (int) $facts['ticket_id']; ?>
<?= view('shared/header.php', ['id' => 'ticket-merge', 'title' => 'Merge ' . $facts['number'], 'crumbs' => [['Home', '/'], ['Inbox', '/inbox'], [$facts['number'], '/tickets/' . $id], ['Merge', null]], 'back' => back_link() ?? ['/tickets/' . $id, 'the ticket']]) ?>
<div class="main-content" id="ticket-merge-content">
    <?php if ($into === null): ?>
        <form method="get" action="/tickets/<?= $id ?>/merge" hx-get="/tickets/<?= $id ?>/merge" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="ticket-merge-search"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="ticket-merge-field-q">Merge <?= e($facts['number']) ?> into which ticket? A number or words</label>
            <div class="input-group"><input type="search" name="q" id="ticket-merge-field-q" class="form-control btn-touch" value="<?= e($q) ?>" maxlength="80"><button type="submit" class="btn btn-light btn-touch" id="ticket-merge-search-btn"><i class="feather-search"></i></button></div>
        </div></form>
        <?php $list = $q !== '' ? $found : $suggestions; ?>
        <h6 class="text-muted text-uppercase fs-11 mb-2"><?= $q !== '' ? 'Found' : 'Similar tickets' ?></h6>
        <?php if ($list === []): ?><div class="card"><div class="card-body text-muted" id="ticket-merge-none">Nothing <?= $q !== '' ? 'matches' : 'similar' ?>.</div></div><?php endif; ?>
        <div class="list-group" id="ticket-merge-candidates">
            <?php foreach ($list as $c): if ($c['ticket_id'] === $id) { continue; } ?>
                <?= hx_link('/tickets/' . $id . '/merge?into=' . (int) $c['ticket_id'], '<span class="fw-semibold">' . e($c['number']) . '</span> ' . e($c['subject']) . ' <span class="badge bg-' . e($c['status_color']) . '">' . e($c['status_label']) . '</span><div class="fs-12 text-muted">' . e($c['requester_name']) . ' · ' . e($c['queue_name']) . '</div>', 'list-group-item list-group-item-action py-2', 'id="ticket-merge-candidate-' . (int) $c['ticket_id'] . '"') ?>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="card" id="ticket-merge-confirm"><div class="card-body">
            <p><strong><?= e($facts['number']) ?></strong> "<?= e($facts['subject']) ?>" merges into <strong><?= e($into['number']) ?></strong> "<?= e($into['subject']) ?>".</p>
            <ul class="fs-12"><li>Its messages, attachments, tags, CCs and time move to <?= e($into['number']) ?>.</li><li><?= e($facts['number']) ?> is solved as a duplicate and points at <?= e($into['number']) ?>; its secure link keeps working and shows the merged conversation.</li><li>Both tickets log the merge. This cannot be undone.</li></ul>
            <form method="post" action="/tickets/merge.php" hx-post="/tickets/merge.php" hx-target="#flash" id="ticket-merge-form">
                <?= csrf_field() ?><input type="hidden" name="ticket" value="<?= $id ?>"><input type="hidden" name="merge_into" value="<?= (int) $into['ticket_id'] ?>">
                <textarea name="note" class="form-control mb-2" rows="2" maxlength="1000" placeholder="A note for the queue (optional)" id="ticket-merge-form-field-note"></textarea>
                <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="ticket-merge-save-btn">Merge</button>
                <?= hx_link('/tickets/' . $id, 'Cancel', 'btn btn-light btn-touch w-100', 'id="ticket-merge-cancel-link"') ?>
            </form>
        </div></div>
    <?php endif; ?>
</div>
