<?php /** Add or change a rule (screens `rule-add`, `rule-edit`). Data: cur (rule|null), kind, voc, lists, preview (int|null), here */ $id = $cur['rule_id'] ?? null; $screen = $cur === null ? 'rule-add' : 'rule-edit';
$conds = []; foreach (['all', 'any'] as $k) { foreach ((array) ($cur['conditions'][$k] ?? []) as $c) { $conds[] = $c + ['_group' => $k]; } }
$acts = array_map(static function (array $a): array { $k = (string) array_key_first($a); $w = $a[$k]; return ['do' => $k, 'with' => rule_action_with_text($k, $w)]; }, $cur['actions'] ?? []);
while (count($conds) < 3) { $conds[] = []; } while (count($acts) < 3) { $acts[] = []; } ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New ' . strtolower(RULE_KINDS[$kind]) . ' rule' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Automation', '/settings/automation/'], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/automation/', 'Automation']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/automation/save.php" hx-post="/settings/automation/save.php" hx-target="#flash" id="rule-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="rule" value="<?= (int) $id ?>"><?php endif; ?><input type="hidden" name="kind" value="<?= e($kind) ?>">
        <div class="card mb-3"><div class="card-body">
            <div class="d-flex flex-wrap gap-1 mb-3" id="rule-form-kind"><?php foreach (RULE_KINDS as $k => $l): ?><?php if ($cur === null): ?><?= hx_link('/settings/automation/new?kind=' . $k, e($l), 'btn btn-touch ' . ($kind === $k ? 'btn-primary' : 'btn-light'), 'id="rule-form-kind-' . $k . '"') ?><?php else: ?><span class="btn btn-touch <?= $kind === $k ? 'btn-primary' : 'btn-light disabled' ?>"><?= e($l) ?></span><?php endif; ?><?php endforeach; ?></div>
            <label class="form-label fs-12 text-muted" for="rule-form-field-name">Name</label>
            <input type="text" name="name" id="rule-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Urgent tickets from Gold customers go to IT">
            <?php if ($kind === 'trigger'): ?><label class="form-label fs-12 text-muted" for="rule-form-field-event">When</label>
            <select name="event" id="rule-form-field-event" class="form-select btn-touch mb-3"><?php foreach (RULE_EVENTS as $k => $l): ?><option value="<?= e($k) ?>" <?= ($cur['event'] ?? 'ticket.create') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?php endif; ?>
            <div class="row g-2 mb-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="rule-form-field-sort">Order</label><input type="number" name="sort_order" id="rule-form-field-sort" class="form-control btn-touch" min="0" max="1000" value="<?= (int) ($cur['sort_order'] ?? 100) ?>"></div>
                <div class="col-6 d-flex align-items-end"><input type="hidden" name="active" value="no"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0 w-100" for="rule-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="rule-form-field-active" <?= ($cur['active'] ?? true) ? 'checked' : '' ?>>On</label></div></div>
        </div></div>
        <div class="card mb-3" id="rule-form-conditions"><div class="card-header"><h5 class="mb-0">Conditions (all must hold)</h5><div class="fs-11 text-muted">Leave a row empty to drop it. A value may be a list (a, b) or setting:auto_close_days.</div></div><div class="card-body">
            <?php foreach ($conds as $i => $c): ?><?= view('automation/partials/condition-row.php', ['i' => $i, 'c' => $c, 'voc' => $voc, 'lists' => $lists]) ?><?php endforeach; ?>
            <?php foreach (['statuses' => 'status', 'queues' => 'queue_id', 'categories' => 'category_id', 'types' => 'type_id', 'organizations' => 'organization_id', 'tags' => 'tags'] as $list => $field): ?><datalist id="rule-values-<?= e($field) ?>"><?php foreach ($lists[$list] as $o): ?><option value="<?= e((string) $o[0]) ?>"><?= e((string) $o[1]) ?></option><?php endforeach; ?></datalist><?php endforeach; ?>
            <?php if ($preview !== null): ?><div class="fs-12 text-muted" id="rule-form-preview">Would match <strong><?= (int) $preview ?></strong> open ticket<?= $preview === 1 ? '' : 's' ?> now.</div><?php endif; ?>
        </div></div>
        <div class="card mb-3" id="rule-form-actions"><div class="card-header"><h5 class="mb-0">Then (in order)</h5></div><div class="card-body">
            <?php foreach ($acts as $i => $a): ?><?= view('automation/partials/action-row.php', ['i' => $i, 'a' => $a]) ?><?php endforeach; ?>
        </div></div>
        <details class="card mb-3" id="rule-form-json"><summary class="card-header btn-touch d-flex align-items-center"><h5 class="mb-0">JSON</h5><span class="fs-11 text-muted ms-2">the raw documents — these win over the rows when given</span></summary><div class="card-body">
            <label class="form-label fs-12 text-muted" for="rule-form-field-conditions">conditions</label>
            <textarea name="conditions" id="rule-form-field-conditions" class="form-control font-monospace fs-12 mb-2" rows="4" placeholder='{"all": [{"field": "priority", "op": "eq", "value": "urgent"}]}'></textarea>
            <label class="form-label fs-12 text-muted" for="rule-form-field-actions">actions</label>
            <textarea name="actions" id="rule-form-field-actions" class="form-control font-monospace fs-12" rows="4" placeholder='[{"set": {"priority": "urgent"}}, {"notify": {"who": "queue_leads", "kind": "escalated"}}]'></textarea>
            <?php if ($cur !== null): ?><div class="fs-11 text-muted mt-2">Now: <code><?= e(json_encode($cur['conditions'], JSON_UNESCAPED_SLASHES)) ?></code> → <code><?= e(json_encode($cur['actions'], JSON_UNESCAPED_SLASHES)) ?></code></div><?php endif; ?>
        </div></details>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="rule-form-save-btn">Save</button>
        <?= hx_link('/settings/automation/', 'Cancel', 'btn btn-light btn-touch w-100 mb-3', 'id="rule-form-cancel-link"') ?>
    </form>
    <?php if ($cur !== null): ?>
        <form method="post" action="/settings/automation/delete.php" hx-post="/settings/automation/delete.php" hx-target="#flash" hx-confirm="Delete the rule <?= e($cur['name']) ?>? Its runs go with it."><?= csrf_field() ?><input type="hidden" name="rule" value="<?= (int) $id ?>">
            <button type="submit" class="btn btn-light btn-touch w-100" id="rule-form-delete-btn">Delete</button></form>
    <?php endif; ?>
</div>
