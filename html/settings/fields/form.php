<?php
declare(strict_types=1);
/** /settings/fields/new?applies_to= and /settings/fields/{id}/edit (screens `field-add`, `field-edit`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/fields/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/fields/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('field');
$cur = $id === null ? null : (find_field($pdo, $id) ?? refuse(404, 'Field not found.'));
$appliesTo = isset(FIELD_APPLIES[request_string('applies_to')]) ? request_string('applies_to') : 'ticket';
$screen = $cur === null ? 'field-add' : 'field-edit';
$types = $pdo->query('SELECT id AS type_id, label FROM ticket_types WHERE archived_at IS NULL ORDER BY sort_order')->fetchAll();
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['field' => $cur === null ? null : present_field($cur), 'applies_to' => $cur['applies_to'] ?? $appliesTo, 'field_types' => array_keys(FIELD_TYPES), 'types' => $types]);
}
render_screen($cur === null ? 'Add a field' : 'Change ' . $cur['label'], view('fields/form.php', ['cur' => $cur, 'appliesTo' => $appliesTo, 'types' => $types, 'here' => here_url()]), ['activeNav' => 'fields', 'screen' => $screen, 'entity' => 'custom_field', 'recordId' => $id === null ? '' : (string) $id]);
