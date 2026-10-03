<?php
declare(strict_types=1);
/** GET /tickets/attachments/get.php?id= — the file, through the gate (the view: a note's attachment needs the queue). nosniff; inline for images and PDFs, a download otherwise. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_login();
$pdo = db();
$id = request_integer('id') ?? refuse(404, 'Attachment not found.');
$a = attachment_path($pdo, $id) ?? refuse(404, 'Attachment not found.');
if (!is_file($a['path'])) {
    refuse(404, 'Attachment not found.');
}
$inline = str_starts_with($a['mime'], 'image/') || $a['mime'] === 'application/pdf';
header('Content-Type: ' . ($inline ? $a['mime'] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . $a['size']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $a['filename']) . '"');
header('Cache-Control: private, no-store');
readfile($a['path']);
exit;
