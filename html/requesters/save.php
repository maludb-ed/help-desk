<?php
declare(strict_types=1);
/**
 * Actions `requester_create` / `requester_update` (by the presence of `requester`; log `requester.create` / `requester.update` — never the notes' text): tickets.view_queue.
 * A create goes through hd_requester_for_email(): a known address answers 422 "That address belongs to <name> — open them instead." A member's name and email are untouched.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/requesters/queries.php';
hd_handler_begin();
require_right('tickets.view_queue');
$pdo = db();
$id = request_integer('requester') ?? request_integer('requester_id');
$cur = null;
if ($id !== null) {
    $cur = requester_state($pdo, $id) ?? refuse(404, 'Requester not found.');
    if ($cur['merged_into_id'] !== null || find_requester($pdo, $id) === null) {
        refuse(404, 'Requester not found.');
    }
}
$errors = [];
$name = req_has('display_name') ? (string) req_val('display_name') : (string) ($cur['display_name'] ?? '');
if ($cur === null || $cur['kind'] === 'external') {
    if ($name === '' || mb_strlen($name) > 120) {
        $errors['display_name'] = 'Give the person a name of up to 120 characters.';
    }
}
$email = strtolower((string) req_val('email'));
if ($cur === null && !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
    $errors['email'] = 'An email address is required.';
}
$phone = req_has('phone') ? ((string) req_val('phone') === '' ? null : mb_substr((string) req_val('phone'), 0, 40)) : ($cur['phone'] ?? null);
$orgField = req_has('organization') ? 'organization' : 'organization_id';
$org = hd_ref($pdo, $orgField, $cur['organization_id'] ?? null, 'SELECT 1 FROM organizations WHERE id = :id AND active', 'organization', $errors);
$locale = req_has('locale') ? ((string) req_val('locale') === '' ? null : mb_substr((string) req_val('locale'), 0, 10)) : ($cur['locale'] ?? null);
$tz = req_has('timezone') ? ((string) req_val('timezone') === '' ? null : (string) req_val('timezone')) : ($cur['timezone'] ?? null);
if ($tz !== null && !in_array($tz, DateTimeZone::listIdentifiers(), true)) {
    $errors['timezone'] = 'The time zone is an IANA name like America/New_York.';
}
$notesGiven = req_has('notes');
$notes = $notesGiven ? ((string) req_val('notes') === '' ? null : mb_substr((string) req_val('notes'), 0, 4000)) : null;
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $name, $email, $phone, $org, $locale, $tz, $notes, $notesGiven): int {
    $pdo->beginTransaction();
    if ($cur === null) {
        $newId = create_requester($pdo, $name, $email, ['phone' => $phone, 'organization_id' => $org, 'locale' => $locale, 'timezone' => $tz, 'notes' => $notes]);
        $s = requester_state($pdo, $newId);
        log_activity($pdo, 'requester.create', 'requester', $newId, ['after' => ['kind' => $s['kind'], 'display_name' => $s['display_name'], 'email' => $s['email'], 'organization_id' => $s['organization_id'], 'has_notes' => $s['has_notes']]]);
    } else {
        $newId = $id;
        $keepNotes = $notesGiven ? $notes : (string) $pdo->query('SELECT notes FROM requesters WHERE id = ' . $id)->fetchColumn();
        update_requester($pdo, $id, ['display_name' => $name, 'phone' => $phone, 'organization_id' => $org, 'locale' => $locale, 'timezone' => $tz, 'notes' => $keepNotes === '' ? null : $keepNotes]);
        $after = requester_state($pdo, $id);
        $d = hd_diff(array_intersect_key($cur, array_flip(['display_name', 'phone', 'organization_id', 'locale', 'timezone', 'has_notes'])), array_intersect_key($after, array_flip(['display_name', 'phone', 'organization_id', 'locale', 'timezone', 'has_notes'])));
        if ($d['after'] !== []) {
            log_activity($pdo, 'requester.update', 'requester', $id, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Added ' : 'Saved ') . ($cur !== null && $cur['kind'] !== 'external' ? $cur['display_name'] : $name), $newId, hd_land(return_path('/requesters/' . $newId), 'saved'), 'requesterChanged', ['requester_id' => $newId]);
