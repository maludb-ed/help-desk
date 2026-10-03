<?php
declare(strict_types=1);
/** Actions `mailbox_create` / `mailbox_update` (by the presence of `mailbox`; log `mailbox.create` / `mailbox.update`: the changed fields, never a secret; an agent pauses — other): settings.manage. The address valid and unique; the queue live; an env key not yet set is a warning in `did`, not a refusal. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$id = request_integer('mailbox') ?? request_integer('mailbox_id');
$cur = $id === null ? null : (find_mailbox($pdo, $id) ?? refuse(404, 'Mailbox not found.'));
$errors = [];
$address = strtolower(trim(req_has('address') ? (string) req_val('address') : (string) ($cur['address'] ?? '')));
if ($address === '' || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $address) || mb_strlen($address) > 200) {
    $errors['address'] = 'Give the mailbox\'s address.';
} else {
    $dup = $pdo->prepare('SELECT id FROM mailboxes WHERE address = :a AND id <> :id');
    $dup->execute(['a' => $address, 'id' => $id ?? 0]);
    if ($dup->fetchColumn() !== false) {
        $errors['address'] = 'That address is a mailbox already.';
    }
}
$name = trim(req_has('display_name') ? (string) req_val('display_name') : (string) ($cur['display_name'] ?? ''));
if ($name === '' || mb_strlen($name) > 120) {
    $errors['display_name'] = 'Give the From name (up to 120 characters).';
}
$queueId = hd_ref($pdo, req_has('queue') ? 'queue' : 'queue_id', $cur['queue_id'] ?? null, 'SELECT 1 FROM queues WHERE id = :id AND archived_at IS NULL', 'queue', $errors);
if ($queueId === null) {
    $errors['queue'] = 'Choose the queue the mail lands in.';
}
$ref = trim(req_has('webhook_secret_ref') ? (string) req_val('webhook_secret_ref') : (string) ($cur['webhook_secret_ref'] ?? ''));
if ($ref !== '' && !preg_match('/^[A-Z][A-Z0-9_]{0,79}$/', $ref)) {
    $errors['webhook_secret_ref'] = 'The key is an environment name like MAILBOX_SUPPORT_WEBHOOK_SECRET.';
}
$signature = mb_substr(req_has('signature') ? (string) req_val('signature') : (string) ($cur['signature'] ?? ''), 0, 1000);
$poll = hd_yes('poll_enabled', $cur['poll_enabled'] ?? true);
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['address' => $address, 'display_name' => $name, 'queue_id' => $queueId, 'webhook_secret_ref' => $ref, 'signature' => $signature, 'poll_enabled' => $poll];
$newId = hd_guard($pdo, static function () use ($pdo, $id, $cur, $f, $me): int {
    $pdo->beginTransaction();
    $newId = save_mailbox($pdo, $id, $f, $me);
    $after = mailbox_log_state(find_mailbox($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'mailbox.create', 'mailbox', $newId, ['after' => $after]);
    } else {
        $d = hd_diff(mailbox_log_state($cur), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'mailbox.update', 'mailbox', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
$warn = $ref !== '' && (string) env($ref, '') === '' ? ' — the key ' . $ref . ' is not set in the environment yet' : '';
hd_done(($id === null ? 'Added the mailbox ' : 'Saved the mailbox ') . $address . $warn, $newId, hd_land(return_path('/settings/mailboxes/' . $newId . '/edit'), 'saved'), 'mailboxChanged', ['mailbox_id' => $newId, 'secret_set' => $warn === '' && $ref !== '']);
