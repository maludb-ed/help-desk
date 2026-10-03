<?php
declare(strict_types=1);
/** Action `ticket_add_tags` (log `ticket.tag`: after.tags[]): work; tags[] (a new slug creates the tag). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$t = ticket_from_request($pdo);
require_work($t);
$add = request_list('tags') ?? request_list('tag') ?? [];
if ($add === []) {
    hd_refuse_fields(['tags' => 'Say which tags.']);
}
$tags = hd_guard($pdo, static function () use ($pdo, $t, $add): array {
    $pdo->beginTransaction();
    $tags = set_ticket_tags($pdo, $t['ticket_id'], $add, []);
    ticket_log($pdo, $t, 'ticket.tag', ['after' => ['tags' => $tags, 'added' => array_map('slugify_tag', $add)]]);
    $pdo->commit();
    return $tags;
});
ticket_done($t, 'Tagged ' . $t['number'] . ': ' . implode(', ', $tags), ['tags' => $tags], 'saved');
