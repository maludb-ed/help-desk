<?php
declare(strict_types=1);
/** Action `section_archive` (confirm; log `section.archive`): kb.edit. The articles keep their rows under No section; a child section becomes top-level. */
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
hd_handler_begin();
require_right('kb.edit');
$pdo = db();
$me = (int) current_member_id();
$s = find_section($pdo, request_integer('section') ?? request_integer('id') ?? 0) ?? refuse(404, 'Section not found.');
if ($s['archived_at'] !== null) {
    refuse(422, 'That section is already archived.');
}
hd_guard($pdo, static function () use ($pdo, $me, $s): void {
    $pdo->beginTransaction();
    $n = (int) one_value($pdo, 'SELECT count(*) FROM articles WHERE section_id = :s', ['s' => $s['section_id']]);
    archive_section($pdo, $s['section_id'], $me);
    log_activity($pdo, 'section.archive', 'kb_section', $s['section_id'], ['before' => ['name' => $s['name']], 'after' => ['archived' => true, 'articles_moved' => $n]]);
    $pdo->commit();
});
hd_done('Archived the section ' . $s['name'], $s['section_id'], hd_land('/kb/admin/', 'section_archived'), 'articleChanged');
