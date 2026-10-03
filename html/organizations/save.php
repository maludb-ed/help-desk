<?php
declare(strict_types=1);
/**
 * Actions `organization_create` / `organization_update` (by the presence of `organization`; log `organization.create` / `organization.update`, before/after of the changed
 * fields): tickets.view_queue. domains[] (or one per line) are validated and unique across companies — "example.com already belongs to <name>." A field left out stays.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/organizations/queries.php';
hd_handler_begin();
require_right('tickets.view_queue');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('organization') ?? request_integer('organization_id');
$cur = null;
if ($id !== null) {
    $cur = organization_state($pdo, $id) ?? refuse(404, 'Organization not found.');
    if (!$cur['active'] || find_organization($pdo, $id) === null) {
        refuse(404, 'Organization not found.');
    }
}
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the company a name of up to 120 characters.';
}
$tier = req_has('support_tier') ? ((string) req_val('support_tier') === '' ? null : mb_substr((string) req_val('support_tier'), 0, 40)) : ($cur['support_tier'] ?? null);
$notes = req_has('notes') ? ((string) req_val('notes') === '' ? null : mb_substr((string) req_val('notes'), 0, 4000)) : ($cur['notes'] ?? null);
$tags = request_list('tags');
$tags = $tags === null ? ($cur['tags'] ?? []) : array_values(array_unique(array_map(static fn (string $t): string => mb_substr(strtolower($t), 0, 40), $tags)));
$policyField = req_has('policy') ? 'policy' : 'sla_policy_id';
$policy = hd_ref($pdo, $policyField, $cur['sla_policy_id'] ?? null, 'SELECT 1 FROM sla_policies WHERE id = :id AND active', 'policy', $errors);
$see = $cur['members_see_each_other'] ?? null;
if (req_has('members_see_each_other')) {
    $see = (string) req_val('members_see_each_other') === '' ? null : hd_yes('members_see_each_other');
}
$domains = $cur['domains'] ?? [];
if (req_has('domains')) {
    $raw = $_POST['domains'];
    $raw = is_array($raw) ? $raw : preg_split('/[\s,]+/', (string) $raw);
    $domains = [];
    foreach ($raw as $d) {
        if (trim((string) $d) === '') {
            continue;
        }
        $clean = clean_domain((string) $d);
        if ($clean === null) {
            $errors['domains'] = trim((string) $d) . ' is not an email domain (like example.com).';
            continue;
        }
        $domains[] = $clean;
    }
    $domains = array_values(array_unique($domains));
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['name' => $name, 'support_tier' => $tier, 'notes' => $notes, 'tags' => $tags, 'sla_policy_id' => $policy, 'members_see_each_other' => $see];
$newId = hd_guard($pdo, static function () use ($pdo, $me, $id, $cur, $f, $domains): int {
    $pdo->beginTransaction();
    $newId = save_organization($pdo, $id, $f, $domains, $me);
    $after = organization_state($pdo, $newId);
    unset($after['active']);
    $loggable = static fn (array $s): array => ['name' => $s['name'], 'support_tier' => $s['support_tier'], 'tags' => $s['tags'], 'sla_policy_id' => $s['sla_policy_id'], 'members_see_each_other' => $s['members_see_each_other'], 'domains' => $s['domains'], 'has_notes' => $s['notes'] !== null];
    if ($cur === null) {
        log_activity($pdo, 'organization.create', 'organization', $newId, ['after' => $loggable($after)]);
    } else {
        $d = hd_diff($loggable($cur), $loggable($after));
        if ($d['after'] !== []) {
            log_activity($pdo, 'organization.update', 'organization', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
$domainsOnly = $cur !== null && req_has('domains') && !req_has('name');
hd_done(($id === null ? 'Added ' : 'Saved ') . $f['name'], $newId, hd_land(return_path('/organizations/' . $newId), $domainsOnly ? 'domains' : 'saved'), 'organizationChanged', ['organization_id' => $newId]);
