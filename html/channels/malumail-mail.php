<?php
declare(strict_types=1);
/**
 * POST /channels/malumail/mail — MaluMail's new-mail webhook for a queue's mailbox (public, allow-listed). Nothing is acted on until the signature holds: X-MaluMail-Signature, HMAC-SHA256
 * with the secret the mailbox's env key names, at most five minutes old. The notice carries headers only; the message is read through the mailbox MCP. 204 once verified whatever became of
 * the message; 403 otherwise with an error_log line and no activity row (an unauthenticated caller writes nothing); 405 for anything but POST. Every row written is source webhook.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
$answer = static function (int $status): never { http_response_code($status); exit; };
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $answer(405);
}
$raw = (string) file_get_contents('php://input', false, null, 0, 65536);
$notice = json_decode($raw, true);
$pdo = db();
$mailbox = is_array($notice) && is_string($notice['address'] ?? null) ? find_mailbox_by_address($pdo, strtolower($notice['address'])) : null;
$secret = $mailbox !== null && ($mailbox['webhook_secret_ref'] ?? '') !== '' ? (string) env((string) $mailbox['webhook_secret_ref'], '') : '';
if ($mailbox === null || $secret === '' || !malumail_signature_valid($secret, (string) ($_SERVER['HTTP_X_MALUMAIL_SIGNATURE'] ?? ''), $raw)) {
    error_log('malumail webhook: refused (no mailbox for the address, no webhook secret, or a bad signature)');
    $answer(403);
}
$GLOBALS['__public_door'] = 'webhook';
if (($notice['type'] ?? '') === 'message.new' && ($notice['folder'] ?? '') === 'INBOX' && (int) ($notice['uid'] ?? 0) > 0) {
    try {
        mail_inbound_uid($pdo, $mailbox, (int) $notice['uid']);
        foreach ($GLOBALS['__dispatch_after'] ?? [] as [$ticketId, $agentId]) {
            dispatch_to_agent($pdo, $ticketId, $agentId, 'assigned', null);
        }
        $GLOBALS['__dispatch_after'] = [];
        mail_fire_triggers($pdo);
    } catch (Throwable $e) {
        error_log('malumail webhook: ' . $e->getMessage());             // the poll picks it up
    }
}
$answer(204);
