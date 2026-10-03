<?php /** One field definition as a ledger row. Data: f, typeLabels, here */ $id = (int) $f['field_id']; ?>
<tr id="field-row-<?= $id ?>" class="<?= $f['archived_at'] !== null ? 'text-muted' : '' ?>">
    <td><?= hx_link(with_back('/settings/fields/' . $id . '/edit', $here), e($f['label']), 'text-dark fw-semibold', 'id="field-row-' . $id . '-label"') ?><?= $f['archived_at'] !== null ? ' <span class="badge bg-soft-secondary text-secondary">archived</span>' : '' ?><div class="fs-11 text-muted"><code><?= e($f['key']) ?></code></div></td>
    <td><?= e(FIELD_TYPES[$f['field_type']]) ?><?= in_array($f['field_type'], ['select', 'multi_select'], true) ? ' <span class="text-muted">· ' . count($f['options']) . ' option' . (count($f['options']) === 1 ? '' : 's') . '</span>' : '' ?></td>
    <td class="d-none d-md-table-cell fs-12" id="field-row-<?= $id ?>-types"><?= $f['applies_to'] !== 'ticket' ? '' : ($f['type_ids'] === [] ? 'every type' : 'for ' . e(implode(', ', array_map(static fn (int $t): string => $typeLabels[$t] ?? ('#' . $t), $f['type_ids'])))) ?></td>
    <td class="d-none d-md-table-cell text-center"><?= $f['required_on_solve'] ? '<i class="feather-check text-success" title="Required on solve"></i>' : '' ?></td>
    <td class="d-none d-md-table-cell text-center"><?= $f['requester_visible'] ? '<i class="feather-eye" title="Requesters see it"></i>' : '<i class="feather-eye-off text-muted" title="Support only"></i>' ?></td>
</tr>
