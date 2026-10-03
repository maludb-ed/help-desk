<?php
declare(strict_types=1);
/**
 * Action `field_save` (by the presence of `field`; log `field.save`): settings.manage. label, key (derived; immutable), field_type, options (one per line or options[]; required for the
 * select kinds), applies_to (fixed after create — a changed value is ignored), types[] (ticket fields only), required_on_solve, requester_visible, sort_order.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/fields/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('field') ?? request_integer('field_id');
$cur = $id === null ? null : (find_field($pdo, $id) ?? refuse(404, 'Field not found.'));
if ($cur !== null && $cur['archived_at'] !== null) {
    refuse(422, 'That field is archived.');
}
$errors = [];
$label = req_has('label') ? (string) req_val('label') : (string) ($cur['label'] ?? '');
if ($label === '' || mb_strlen($label) > 60) {
    $errors['label'] = 'Give the field a label of up to 60 characters.';
}
$appliesTo = $cur['applies_to'] ?? (string) req_val('applies_to');
if (!isset(FIELD_APPLIES[$appliesTo])) {
    $errors['applies_to'] = 'A field applies to ticket, organization or requester.';
}
$key = $cur['key'] ?? null;
if ($cur === null) {
    $key = strtolower((string) req_val('key'));
    if ($key === '') {
        $key = strtolower(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)) ?? '', '_'));
        if ($key === '' || !ctype_alpha($key[0])) {
            $key = 'f_' . $key;
        }
        $key = substr(rtrim($key, '_'), 0, 40);
    }
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
        $errors['key'] = 'The key is letters, digits and underscores, starting with a letter (up to 40).';
    }
}
$type = req_has('field_type') ? (string) req_val('field_type') : (string) ($cur['field_type'] ?? 'text');
if (!isset(FIELD_TYPES[$type])) {
    $errors['field_type'] = 'The type is text, number, date, select, multi_select or checkbox.';
}
$options = $cur['options'] ?? [];
if (req_has('options')) {
    $raw = $_POST['options'];
    $raw = is_array($raw) ? $raw : preg_split('/\R|,/', (string) $raw);
    $options = array_values(array_unique(array_filter(array_map(static fn ($o): string => mb_substr(trim((string) $o), 0, 80), $raw), static fn (string $o): bool => $o !== '')));
}
if (in_array($type, ['select', 'multi_select'], true) && $options === []) {
    $errors['options'] = 'A select field needs its options, one per line.';
}
if (!in_array($type, ['select', 'multi_select'], true)) {
    $options = [];
}
$typeIds = $cur['type_ids'] ?? [];
if ($appliesTo === 'ticket' && ($list = request_list('types')) !== null) {
    $typeIds = [];
    foreach ($list as $t) {
        if (filter_var($t, FILTER_VALIDATE_INT) === false) {
            $errors['types'] = 'Choose the types from the list.';
            break;
        }
        $st = $pdo->prepare('SELECT 1 FROM ticket_types WHERE id = :id');
        $st->execute(['id' => (int) $t]);
        if ($st->fetchColumn() === false) {
            $errors['types'] = 'One of the types is not here.';
            break;
        }
        $typeIds[] = (int) $t;
    }
    $typeIds = array_values(array_unique($typeIds));
}
if ($appliesTo !== 'ticket') {
    $typeIds = [];
}
$required = $appliesTo === 'ticket' ? hd_yes('required_on_solve', $cur['required_on_solve'] ?? false) : false;
$visible = hd_yes('requester_visible', $cur['requester_visible'] ?? false);
$order = hd_int('sort_order', $cur['sort_order'] ?? 100, 0, 1000, 'The order', $errors);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['key' => $key, 'label' => $label, 'field_type' => $type, 'options' => $options, 'applies_to' => $appliesTo, 'type_ids' => $typeIds, 'required_on_solve' => $required, 'requester_visible' => $visible, 'sort_order' => $order];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_field($pdo, $id, $f);
    $after = field_log_state(find_field($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'field.save', 'custom_field', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(field_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'field.save', 'custom_field', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the field ' : 'Saved the field ') . $label, $newId, hd_land(return_path('/settings/fields/'), 'saved', 'field-row-' . $newId), 'fieldChanged', ['field_id' => $newId]);
