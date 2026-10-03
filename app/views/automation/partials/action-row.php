<?php /** One action row: do · with. Data: i, a (do, with as text) */ ?>
<div class="row g-1 mb-2 align-items-center rule-action-row" id="rule-action-<?= (int) $i ?>">
    <div class="col-12 col-md-4"><select name="actions_rows[<?= (int) $i ?>][do]" class="form-select btn-touch" id="rule-action-<?= (int) $i ?>-do" aria-label="Do"><option value="">— no action —</option><?php foreach (rule_vocabulary()['actions'] as $k => $l): ?><option value="<?= e($k) ?>" <?= ($a['do'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-12 col-md-8"><input type="text" name="actions_rows[<?= (int) $i ?>][with]" class="form-control btn-touch" id="rule-action-<?= (int) $i ?>-with" value="<?= e((string) ($a['with'] ?? '')) ?>" placeholder="e.g. priority=urgent · queue_id=3 · assignee=round_robin · solved resolution_code=resolved · assignee sla_warning · tags a,b · note text"></div>
</div>
