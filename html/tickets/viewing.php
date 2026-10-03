<?php
declare(strict_types=1);
/** POST /tickets/viewing.php — "I have this ticket open" (composing when the composer holds text). 204; no log; not an action. */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
require_post();
require_login();
verify_csrf();
$pdo = db();
$t = ticket_from_request($pdo);
touch_viewer($pdo, $t['ticket_id'], (int) current_member_id(), hd_yes('composing', false));
http_response_code(204);
exit;
