<?php
declare(strict_types=1);
/** GET /kb/attachment.php?id= — an article's file, through the article's own visibility check (a public article's file needs no session). nosniff; inline for images and PDFs. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
$pdo = db();
$id = request_integer('id') ?? refuse(404, 'Attachment not found.');
$st = $pdo->prepare('SELECT article_id, filename, mime_type, byte_size, storage_path FROM attachments WHERE id = :id AND article_id IS NOT NULL');
$st->execute(['id' => $id]);
$f = $st->fetch();
if ($f === false || find_article($pdo, (int) $f['article_id'], kb_visible_filter(current_member())) === null || !is_file(APP_ROOT . '/storage/' . $f['storage_path'])) {
    refuse(404, 'Attachment not found.');
}
$inline = str_starts_with($f['mime_type'], 'image/') || $f['mime_type'] === 'application/pdf';
header('Content-Type: ' . ($inline ? $f['mime_type'] : 'application/octet-stream'));
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . (int) $f['byte_size']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $f['filename']) . '"');
header('Cache-Control: private, no-store');
readfile(APP_ROOT . '/storage/' . $f['storage_path']);
exit;
