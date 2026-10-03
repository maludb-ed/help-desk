<?php /** The four priorities × three metrics as minutes. Data: targets, editable (bool), prefix (field name prefix, e.g. targets) */ $editable = $editable ?? false; $prefix = $prefix ?? 'targets'; ?>
<div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="<?= e($prefix) ?>-grid">
    <thead class="thead-light"><tr><th></th><?php foreach (SLA_METRICS as $k => $label): ?><th class="text-center"><?= e($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach (PRIORITIES as $p => $plabel): ?>
        <tr><th class="fw-semibold"><?= e($plabel) ?></th>
        <?php foreach (SLA_METRICS as $m => $mlabel): $v = $targets[$p][$m] ?? null; ?>
            <td class="text-center"><?php if ($editable): ?><input type="number" name="<?= e($prefix) ?>[<?= e($p) ?>][<?= e($m) ?>]" id="<?= e($prefix) ?>-<?= e($p) ?>-<?= e($m) ?>" class="form-control form-control-sm text-center" min="1" max="525600" value="<?= $v === null ? '' : (int) $v ?>" placeholder="—" aria-label="<?= e($plabel . ' ' . $mlabel) ?> minutes"><?php else: ?><span id="<?= e($prefix) ?>-<?= e($p) ?>-<?= e($m) ?>"><?= $v === null ? '<span class="text-muted">—</span>' : e(minutes_words((int) $v)) ?></span><?php endif; ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
