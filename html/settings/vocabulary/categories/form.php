<?php
declare(strict_types=1);
/** /settings/vocabulary/categories/new?parent= and /settings/vocabulary/categories/{id}/edit (screens `category-add`, `category-edit`; settings.manage). */
require_once dirname(__DIR__, 4) . '/app/bootstrap.php';
require_once dirname(__DIR__, 4) . '/app/features/statuses/queries.php';
require_once dirname(__DIR__, 4) . '/app/features/vocabulary/queries.php';
require_once dirname(__DIR__, 4) . '/app/features/vocabulary/present.php';
require_once dirname(__DIR__, 4) . '/app/features/queues/queries.php';
require_once dirname(__DIR__, 4) . '/app/features/sla/queries.php';
require_right('settings.manage');
require_human();
$pdo = db();
$id = request_integer('id') ?? request_integer('category');
$cur = $id === null ? null : (find_category($pdo, $id) ?? refuse(404, 'Category not found.'));
$screen = $cur === null ? 'category-add' : 'category-edit';
$data = ['cur' => $cur, 'parent' => request_integer('parent'), 'tree' => find_category_tree($pdo), 'descendants' => $id === null ? [] : category_descendant_ids($pdo, $id),
         'queues' => find_queues($pdo), 'types' => find_types($pdo), 'policies' => find_sla_policies($pdo), 'here' => here_url()];
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['category' => $cur === null ? null : present_category($cur + ['ticket_count' => null]), 'parent' => $data['parent'], 'tree' => array_map('present_category', $data['tree']),
        'queues' => array_map(static fn (array $q): array => ['queue_id' => $q['queue_id'], 'name' => $q['name']], $data['queues']), 'types' => array_map('present_type', $data['types']),
        'policies' => array_map(static fn (array $p): array => ['policy_id' => $p['policy_id'], 'name' => $p['name']], $data['policies']), 'priorities' => array_keys(TICKET_PRIORITIES)]);
}
render_screen($cur === null ? 'Add a category' : 'Change ' . $cur['name'], view('vocabulary/category-form.php', $data), ['activeNav' => 'vocabulary', 'screen' => $screen, 'entity' => 'category', 'recordId' => $id === null ? '' : (string) $id]);
