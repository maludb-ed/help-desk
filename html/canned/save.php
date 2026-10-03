<?php
declare(strict_types=1);
/**
 * Actions `canned_create` / `canned_update` (by the presence of `canned`; log `canned.create` / `canned.update`: scope, queue_id, folder, side-effect keys — never the body): personal → tickets.work;
 * queue → the queue held and tickets.manage or its lead; global → tickets.manage. An unknown placeholder → 422 "{{foo}} is not a placeholder."; side-effects validated against the vocabulary.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('tickets.work');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('canned') ?? request_integer('canned_id');
$cur = $id === null ? null : (find_canned_one($pdo, $id) ?? refuse(404, 'Canned response not found.'));
if ($cur !== null && !canned_may_change($cur)) {
    refuse(403, 'That canned response is not yours to change.');
}
$errors = [];
$title = req_has('title') ? (string) req_val('title') : (string) ($cur['title'] ?? '');
if ($title === '' || mb_strlen($title) > 120) {
    $errors['title'] = 'Give it a title of up to 120 characters.';
}
$folder = req_has('folder') ? mb_substr((string) req_val('folder'), 0, 60) : (string) ($cur['folder'] ?? '');
$body = req_has('body') ? (string) req_val('body') : (req_has('body_text') ? (string) req_val('body_text') : (string) ($cur['body_text'] ?? ''));
if ($body === '' || mb_strlen($body) > 20000) {
    $errors['body'] = 'Write the text (up to 20,000 characters).';
}
$unknown = canned_unknown_placeholders($body);
if ($unknown !== []) {
    $errors['body'] = '{{' . $unknown[0] . '}} is not a placeholder.';
}
$scope = req_has('scope') ? (string) req_val('scope') : (string) ($cur['scope'] ?? 'personal');
if (!isset(CANNED_SCOPES[$scope])) {
    $errors['scope'] = 'The scope is personal, queue or global.';
    $scope = 'personal';
}
$queueId = null;
if ($scope === 'global' && !has_right('tickets.manage')) {
    refuse(403, 'Sharing with everyone needs the Lead role.');
}
if ($scope === 'queue') {
    $queueId = req_has('queue') ? (int) req_val('queue') : ($cur['queue_id'] ?? null);
    $st = $pdo->prepare('SELECT hd_is_queue_lead(:q)');
    $st->execute(['q' => $queueId ?? 0]);
    $held = array_map('intval', $pdo->query('SELECT * FROM hd_held_queue_ids()')->fetchAll(PDO::FETCH_COLUMN));
    if ($queueId === null || !in_array($queueId, $held, true)) {
        $errors['queue'] = 'Choose a queue you hold.';
    } elseif (!has_right('tickets.manage') && !(bool) $st->fetchColumn()) {
        refuse(403, 'Sharing with a queue needs its lead.');
    }
}
$rawSe = $_POST['side_effects'] ?? null;
if (is_string($rawSe)) {
    $rawSe = json_decode($rawSe, true);
    if (!is_array($rawSe)) {
        $errors['side_effects'] = 'side_effects is a JSON object: status, priority, assign, add_tags.';
        $rawSe = [];
    }
}
$se = $cur['side_effects'] ?? [];
if (is_array($rawSe)) {
    $errors += canned_side_effects_validate($pdo, $rawSe, $se);
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['title' => $title, 'folder' => $folder, 'body_text' => $body, 'scope' => $scope, 'queue_id' => $queueId, 'side_effects' => $se, 'owner_member_id' => $cur['owner_member_id'] ?? $me];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f, $me): int {
    $pdo->beginTransaction();
    $newId = save_canned($pdo, $id, $f, $me);
    $after = canned_log_state(canned_cast(['side_effects' => $f['side_effects']] + $f + ['canned_id' => $newId]));
    if ($cur === null) {
        log_activity($pdo, 'canned.create', 'canned_response', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(canned_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'canned.update', 'canned_response', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
hd_done(($id === null ? 'Saved the canned response ' : 'Changed the canned response ') . $title, $newId, hd_land(return_path('/canned/' . $newId . '/edit'), 'saved'), 'cannedChanged', ['canned_id' => $newId]);
