<?php
declare(strict_types=1);
/** /settings/fields/ — the custom field definitions in three groups (screen `fields-list`; settings.manage). */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/fields/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/fields/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$groups = [];
foreach (find_fields($pdo, null, true) as $f) {
    $groups[$f['applies_to']][] = $f;
}
$typeLabels = $pdo->query('SELECT id, label FROM ticket_types')->fetchAll(PDO::FETCH_KEY_PAIR);
log_screen_view($pdo, 'fields-list');
if (wants_json()) {
    respond_screen(['fields' => array_map('present_field', array_merge(...array_values($groups ?: [[]])))]);
}
$notices = ['saved' => ['success', 'Saved the field.'], 'archived' => ['success', 'Archived the field.']];
render_screen('Custom fields', view('fields/list.php', ['groups' => $groups, 'typeLabels' => $typeLabels, 'here' => here_url(), 'notice' => hd_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'fields', 'screen' => 'fields-list', 'entity' => 'custom_field']);
