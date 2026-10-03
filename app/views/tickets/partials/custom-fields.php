<?php /** The custom fields of the chosen type (Pattern A region reloaded as the type changes). Data: defs, values */ ?>
<div id="custom-fields">
    <?php foreach ($defs as $f): ?><?= view('fields/partials/field-input.php', ['f' => $f, 'value' => $values[$f['key']] ?? null, 'prefix' => 'custom', 'idp' => 'ticket-form-custom']) ?><?php endforeach; ?>
</div>
