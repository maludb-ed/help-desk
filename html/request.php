<?php
declare(strict_types=1);
/**
 * /request — the public request form (screen `request-form`; nobody signed in; PHP's anonymous session for CSRF). GET: the form, or the "taken by email" page when the switch is off.
 * POST: the honeypot and the rate limits answer the same thank-you page (outcome honeypot / rate_limited; nothing created — no oracle); validation shows the form again; else one transaction
 * makes the ticket (channel form) and the created notice carrying the secure link. Logs ticket.create with actor_requester_id; the refusals log without an address.
 */
require_once dirname(__DIR__) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__) . '/app/features/public/form.php';
$pdo = db();
$GLOBALS['__public_door'] = 'portal';
$max = attachment_max_bytes();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!request_form_enabled($pdo)) {
        render_public('Requests', view('public/request-closed.php', ['address' => public_queue_address($pdo, null)]), 'request-closed');
    }
    log_activity($pdo, 'screen.view', null, null, ['screen' => 'request-form']);
    render_public('Ask for help', view('public/request.php', ['categories' => request_form_categories($pdo), 'values' => [], 'errors' => [], 'max' => $max]), 'request-form');
}
if (!request_form_enabled($pdo)) {
    http_response_code(404);
    render_public('Requests', view('public/request-closed.php', ['address' => public_queue_address($pdo, null)]), 'request-closed', 404);
}
require_post();
verify_csrf();
$ip = public_ip();
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
if (trim((string) ($_POST['website'] ?? '')) !== '') {
    record_form_submission($pdo, $email !== '' ? mb_substr($email, 0, 200) : 'honeypot@invalid', $ip, 'honeypot', null);
    log_activity($pdo, 'request_form.honeypot', null, null, ['after' => ['outcome' => 'honeypot']]);
    render_public('Thank you', view('public/request-done.php', ['number' => null, 'email' => $email]), 'request-done');
}
$errors = request_form_validate($pdo, $_POST, $clean);
if ($errors === [] && request_rate_limited($pdo, $clean['email'], $ip)) {
    record_form_submission($pdo, $clean['email'], $ip, 'rate_limited', null);
    log_activity($pdo, 'request_form.rate_limited', null, null, ['after' => ['outcome' => 'rate_limited']]);
    render_public('Thank you', view('public/request-done.php', ['number' => null, 'email' => $clean['email']]), 'request-done');
}
$files = public_files('files', 3);
if ($errors === []) {
    foreach ($files as $file) {
        if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > $max) {
            $errors['files'] = 'A file is at most ' . ($max >= 1048576 ? intdiv($max, 1048576) . ' MB' : intdiv($max, 1024) . ' KB') . '.';
        }
    }
}
if ($errors !== []) {
    render_public('Ask for help', view('public/request.php', ['categories' => request_form_categories($pdo), 'values' => $_POST, 'errors' => $errors, 'max' => $max]), 'request-form', 422);
}
try {
    $made = create_request($pdo, $clean, $files, $ip);
} catch (DomainException $e) {
    render_public('Ask for help', view('public/request.php', ['categories' => request_form_categories($pdo), 'values' => $_POST, 'errors' => ['files' => $e->getMessage()], 'max' => $max]), 'request-form', 422);
}
log_activity($pdo, 'ticket.create', 'ticket', $made['ticket_id'], ['ticket_id' => $made['ticket_id'], 'queue_id' => $made['queue_id'], 'actor_requester_id' => $made['requester_id'],
    'after' => ['number' => $made['number'], 'channel' => 'form', 'requester_id' => $made['requester_id'], 'queue_id' => $made['queue_id'], 'priority' => (string) one_value($pdo, 'SELECT priority FROM tickets WHERE id = :t', ['t' => $made['ticket_id']]), 'category_id' => $clean['category_id'], 'attachments' => count($files)]]);
automation_run_triggers($pdo, 'ticket.create', $made['ticket_id']);
if ($made['assignee_member_id'] !== null && $made['assignee_kind'] === 'agent') {
    dispatch_to_agent($pdo, $made['ticket_id'], $made['assignee_member_id'], 'assigned', null);
}
render_public('Thank you', view('public/request-done.php', ['number' => $made['number'], 'email' => $clean['email']]), 'request-done');
