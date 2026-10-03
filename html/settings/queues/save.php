<?php
declare(strict_types=1);
/**
 * Actions `queue_create` / `queue_update` (by the presence of `queue`; log `queue.create` / `queue.update` with before/after of the changed fields):
 * settings.manage. A field left out stays as it was. The key is derived from the name when blank and never changes after; General's is fixed.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/queues/queries.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('queue') ?? request_integer('queue_id');
$cur = null;
if ($id !== null) {
    $cur = queue_state($pdo, $id) ?? refuse(404, 'Queue not found.');
    if ($cur['archived_at'] !== null) {
        refuse(422, 'That queue is archived.');
    }
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 80) {
    $errors['name'] = 'Give the queue a name of up to 80 characters.';
}
$key = $cur['key'] ?? null;
if ($cur === null) {
    $key = (string) req_val('key');
    $key = $key === '' ? derive_queue_key($name) : strtolower($key);
    if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
        $errors['key'] = 'The key is letters, digits and underscores, starting with a letter (up to 40).';
    }
}
$description = req_has('description') ? ((string) req_val('description') === '' ? null : mb_substr((string) req_val('description'), 0, 500)) : ($cur['description'] ?? null);
$department = hd_ref($pdo, 'department', $cur['department_id'] ?? null, 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
if (req_has('department_id') && !req_has('department')) {
    $department = hd_ref($pdo, 'department_id', $cur['department_id'] ?? null, 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
}
$include = hd_yes('include_department', $cur['include_department'] ?? true);
$method = req_has('assignment_method') ? (string) req_val('assignment_method') : (string) ($cur['assignment_method'] ?? 'manual');
if (!isset(ASSIGNMENT_METHODS[$method])) {
    $errors['assignment_method'] = 'The assignment method is manual, round_robin, least_loaded or default_assignee.';
}
$assigneeField = req_has('default_assignee') ? 'default_assignee' : (req_has('default_assignee_member_id') ? 'default_assignee_member_id' : 'default_assignee');
$assignee = hd_ref($pdo, $assigneeField, $cur['default_assignee_member_id'] ?? null, "SELECT 1 FROM members WHERE id = :id AND status = 'active' AND capability IS NOT NULL", 'default assignee', $errors);
if ($method === 'default_assignee' && $assignee === null && !isset($errors[$assigneeField])) {
    $errors[$assigneeField] = 'Name a default assignee — a person or an agent — for that method.';
}
$scheduleField = req_has('schedule') ? 'schedule' : 'business_hours_id';
$schedule = hd_ref($pdo, $scheduleField, $cur['business_hours_id'] ?? null, 'SELECT 1 FROM business_hours WHERE id = :id', 'schedule', $errors);
$policyField = req_has('policy') ? 'policy' : 'sla_policy_id';
$policy = hd_ref($pdo, $policyField, $cur['sla_policy_id'] ?? null, 'SELECT 1 FROM sla_policies WHERE id = :id AND active', 'policy', $errors);
$email = req_has('email_address') ? strtolower((string) req_val('email_address')) : ($cur['email_address'] ?? null);
if ($email === '') {
    $email = null;
}
if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email_address'] = 'The email address is not valid.';
}
$order = hd_int('sort_order', $cur['sort_order'] ?? 100, 0, 1000, 'The order', $errors);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['key' => $key, 'name' => $name, 'description' => $description, 'department_id' => $department, 'include_department' => $include, 'assignment_method' => $method,
      'default_assignee_member_id' => $assignee, 'business_hours_id' => $schedule, 'sla_policy_id' => $policy, 'email_address' => $email, 'sort_order' => $order];
$newId = hd_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_queue($pdo, $id, $f, $me);
    $after = queue_log_state(queue_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'queue.create', 'queue', $newId, ['queue_id' => $newId, 'after' => $after]);
    } else {
        $d = hd_diff(queue_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'queue.update', 'queue', $newId, ['queue_id' => $newId, 'before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added the queue ' : 'Saved the queue ') . $f['name'], $newId, hd_land(return_path('/settings/queues/' . $newId), 'saved'), 'queueChanged', ['queue_id' => $newId]);
