<?php /** Links and the merge. Data: t, facts, work, here */ $id = (int) $facts['ticket_id']; $hidden = csrf_field() . '<input type="hidden" name="ticket" value="' . $id . '"><input type="hidden" name="return_to" value="' . e($here) . '">'; ?>
<div class="card mb-3" id="ticket-links"><div class="card-header"><h5 class="card-title mb-0">Links</h5><?php if ($work && has_right('tickets.manage') && $facts['merged_into_id'] === null && $facts['status_category'] !== 'closed'): ?><?= hx_link(with_back('/tickets/' . $id . '/merge', $here), 'Merge into…', 'fs-12', 'id="ticket-merge-link"') ?><?php endif; ?></div><div class="card-body fs-12">
    <?php if ($t['merged_into_id'] !== null): ?><div class="alert alert-secondary py-2" id="ticket-merged-into">This ticket was merged into <?= hx_link('/tickets/' . (int) $t['merged_into_id'], 'another ticket', 'alert-link') ?>.</div><?php endif; ?>
    <?php if ($t['links'] === []): ?><div class="text-muted" id="ticket-links-empty">No links.</div><?php endif; ?>
    <?php foreach ($t['links'] as $l): $words = $l['direction'] === 'to' ? (LINK_KINDS[$l['kind']] ?? $l['kind']) : ['parent_child' => 'Child of', 'duplicate_of' => 'Duplicated by', 'blocks' => 'Blocked by', 'follow_up_of' => 'Followed up by', 'related' => 'Related to', 'merged_into' => 'Merged from'][$l['kind']] ?? $l['kind']; ?>
        <div class="d-flex justify-content-between py-1 border-bottom" id="ticket-link-<?= (int) $l['link_id'] ?>"><span><span class="text-muted"><?= e($words) ?></span> <?= hx_link(with_back('/tickets/' . (int) $l['ticket_id'], $here), e($l['number']) . ' ' . e($l['subject'])) ?></span>
            <?php if ($work && $l['kind'] !== 'merged_into'): ?><form method="post" action="/tickets/unlink.php" hx-post="/tickets/unlink.php" hx-target="#flash"><?= $hidden ?><input type="hidden" name="other_ticket" value="<?= (int) $l['ticket_id'] ?>"><input type="hidden" name="kind" value="<?= e($l['kind']) ?>"><button type="submit" class="btn btn-link btn-sm p-0 text-danger" id="ticket-link-<?= (int) $l['link_id'] ?>-remove-btn" aria-label="Remove"><i class="feather-x"></i></button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($work): ?>
    <form method="post" action="/tickets/link.php" hx-post="/tickets/link.php" hx-target="#flash" class="row g-1 mt-2" id="ticket-link-form"><?= $hidden ?>
        <div class="col-6"><select name="kind" id="ticket-link-form-field-kind" class="form-select form-select-sm"><?php foreach (LINK_KINDS as $k => $l): if ($k === 'merged_into') { continue; } ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-4"><input type="text" name="other_ticket" id="ticket-link-form-field-other" class="form-control form-control-sm" placeholder="HD-1042" required></div>
        <div class="col-2"><button type="submit" class="btn btn-light btn-sm btn-touch w-100" id="ticket-link-form-add-btn"><i class="feather-link"></i></button></div>
    </form>
    <?php endif; ?>
</div></div>
