<?php
declare(strict_types=1);
/** Action `ticket_remove_tag` (log `ticket.untag`: after.tags[]): work; tag (a slug). */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$t = ticket_from_request($pdo);
require_work($t);
$tag = (string) req_val('tag');
if ($tag === '') {
    hd_refuse_fields(['tag' => 'Say which tag.']);
}
$tags = hd_guard($pdo, static function () use ($pdo, $t, $tag): array {
    $pdo->beginTransaction();
    $tags = set_ticket_tags($pdo, $t['ticket_id'], [], [$tag]);
    ticket_log($pdo, $t, 'ticket.untag', ['after' => ['tags' => $tags, 'removed' => [slugify_tag($tag)]]]);
    $pdo->commit();
    return $tags;
});
ticket_done($t, 'Removed the tag ' . slugify_tag($tag) . ' from ' . $t['number'], ['tags' => $tags], 'saved');
