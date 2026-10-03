<?php
declare(strict_types=1);
/** GET /t/{token}/attachment?id= — a file on a public message of the token's ticket, streamed (nosniff). The token is the authority; another ticket's file by id is 404. No log (the page view logged). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/public/secure.php';
$pdo = db();
$tid = secure_ticket_open($pdo, (string) ($_GET['token'] ?? '')) ?? secure_dead_link($pdo);
$id = request_integer('id');
$a = $id === null ? null : secure_attachment($pdo, $tid, $id);
if ($a === null || !is_file($a['path'])) {
    secure_dead_link($pdo);
}
$inline = str_starts_with($a['mime'], 'image/') || $a['mime'] === 'application/pdf';
header('Content-Type: ' . ($inline ? $a['mime'] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . $a['size']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $a['filename']) . '"');
header('Cache-Control: private, no-store');
readfile($a['path']);
exit;
