<?php
declare(strict_types=1);
/**
 * Action `canned_apply` (log `canned.apply`: canned_id, sent, side_effects applied and skipped; an agent's send=yes pauses at the kernel — external_send): work.
 * preview=1 renders a body from the request against a sample ticket (the form's live preview; nothing saved, no log). send=no renders the response for a ticket the caller may see and answers
 * the text (JSON {ok, text, side_effects}; HTMX the composer's textarea with the text at the caret plus the side-effect chips) — a read, never a pause. send=yes posts a public reply through
 * add_message(), applies the side-effects in order (a move the table forbids is skipped and named in `did`), bumps usage_count.
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$actor = canned_actor_facts($pdo, $me);
if (hd_yes('preview', false)) {
    require_right('tickets.work');
    $ticketId = request_integer('ticket');
    $ticket = $ticketId !== null && can_see_ticket($ticketId) ? (static function () use ($pdo, $ticketId): array { $st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE t.ticket_id = :t'); $st->execute(['t' => $ticketId]); $r = $st->fetch(); return $r ? present_ticket_brief(ticket_cast($r)) : canned_sample_ticket(); })() : canned_sample_ticket();
    $body = (string) req_val('body');
    $rawSe = is_array($_POST['side_effects'] ?? null) ? $_POST['side_effects'] : [];
    $se = [];
    canned_side_effects_validate($pdo, $rawSe, $se);
    $queue = $ticketId !== null && isset($ticket['queue']['queue_id']) ? canned_queue_facts($pdo, (int) $ticket['queue']['queue_id']) : ['name' => $ticket['queue']['name'] ?? '', 'email_address' => 'help@example.invalid'];
    $text = canned_render(['body_text' => $body], $ticket, $actor, $queue);
    emit_action_status(true, ['did' => 'Previewed', 'text' => $text, 'side_effects' => side_effect_words($se)]);
    if (wants_json()) {
        respond_saved(['did' => 'Previewed', 'text' => $text, 'side_effects' => side_effect_words($se), 'unknown_placeholders' => canned_unknown_placeholders($body)]);
    }
    echo view('canned/partials/preview.php', ['text' => $text, 'words' => side_effect_words($se), 'unknown' => canned_unknown_placeholders($body)]);
    exit;
}
$t = ticket_from_request($pdo);
$canned = find_canned_one($pdo, request_integer('canned') ?? request_integer('canned_id') ?? refuse(422, 'Say which canned response.')) ?? refuse(404, 'Canned response not found.');
$send = hd_yes('send', false);
$st = $pdo->prepare('SELECT t.* FROM mcp_tickets t WHERE t.ticket_id = :t');
$st->execute(['t' => $t['ticket_id']]);
$row = ticket_cast($st->fetch());
$text = canned_render($canned, present_ticket_brief($row), $actor, canned_queue_facts($pdo, $t['queue_id']));
$words = side_effect_words($canned['side_effects']);
if (!$send) {                                                                            // a read dressed as an action: the text, never a pause, no location
    if (!can_work_ticket($t['ticket_id'])) {
        refuse(403, 'You are not in this ticket\'s queue.');
    }
    emit_action_status(true, ['did' => 'Rendered ' . $canned['title'] . ' for ' . $t['number'], 'text' => $text, 'side_effects' => $words, 'canned_id' => $canned['canned_id']]);
    if (wants_json()) {
        respond_saved(['did' => 'Rendered ' . $canned['title'] . ' for ' . $t['number'], 'text' => $text, 'side_effects' => $words, 'canned_id' => $canned['canned_id']]);
    }
    $current = (string) req_val('body');
    $caret = req_val('caret');
    $pos = $caret !== null && $caret !== '' && filter_var($caret, FILTER_VALIDATE_INT) !== false ? max(0, min(mb_strlen($current), (int) $caret)) : mb_strlen($current);
    $merged = mb_substr($current, 0, $pos) . ($pos > 0 && !str_ends_with(mb_substr($current, 0, $pos), "\n") ? "\n" : '') . $text . mb_substr($current, $pos);
    echo '<textarea name="body" id="ticket-reply-form-field-body" class="form-control mb-2 composer-body" rows="6" required maxlength="20000" placeholder="Write to the requester…">' . e($merged) . '</textarea>';
    echo '<div id="ticket-reply-canned-effects" hx-swap-oob="true" class="mb-2">';
    if ($words !== []) {
        echo '<input type="hidden" name="canned_id" value="' . (int) $canned['canned_id'] . '"><input type="hidden" name="apply_side_effects" value="no">';
        echo '<label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0 flex-wrap" for="ticket-reply-form-field-apply-effects"><input type="checkbox" class="form-check-input mt-0" name="apply_side_effects" value="yes" id="ticket-reply-form-field-apply-effects" checked><span class="fs-12">Also:</span>';
        foreach ($words as $w) {
            echo '<span class="badge bg-soft-primary text-primary">' . e($w) . '</span>';
        }
        echo '</label>';
    } else {
        echo '<input type="hidden" name="canned_id" value="' . (int) $canned['canned_id'] . '">';
    }
    echo '</div>';
    echo '<div id="canned-picker" hx-swap-oob="true"></div>';
    exit;
}
require_work($t);
if ($t['status_category'] === 'closed') {
    refuse(422, $t['number'] . ' is closed — a follow-up is a new ticket.');
}
$applyEffects = hd_yes('apply_side_effects', true);
$r = hd_guard($pdo, static function () use ($pdo, $me, $t, $canned, $text, $applyEffects): array {
    $pdo->beginTransaction();
    $mid = add_message($pdo, $t['ticket_id'], 'public_reply', $text, ['author_member_id' => $me, 'channel' => is_action_authed() && current_agent_run_id() !== null ? 'agent' : 'web']);
    $n = 0;
    foreach (reply_recipients($pdo, $t) as $rc) {
        if (isset($rc['member_id']) && $rc['member_id'] === $me) { continue; }
        $n += notify($pdo, $rc, 'replied', $t['ticket_id'], 'Re: ' . $t['number'] . ' ' . $t['subject'], $text . "\n\n" . requester_link_words($t, null), $mid, 'replied:' . $mid) > 0 ? 1 : 0;
    }
    ticket_log($pdo, $t, 'ticket.reply', ['after' => ['message_id' => $mid, 'kind' => 'public_reply', 'excerpt' => excerpt($text), 'attachments' => 0, 'recipients' => $n, 'canned_id' => $canned['canned_id']]]);
    $effects = $applyEffects ? canned_side_effects($pdo, $canned, $t['ticket_id'], $me) : ['applied' => [], 'skipped' => []];
    bump_canned_usage($pdo, $canned['canned_id']);
    ticket_log($pdo, $t, 'canned.apply', ['after' => ['canned_id' => $canned['canned_id'], 'sent' => true, 'message_id' => $mid, 'side_effects' => $effects]]);
    $pdo->commit();
    return ['message_id' => $mid, 'recipients' => $n, 'effects' => $effects];
});
$did = 'Sent ' . $canned['title'] . ' on ' . $t['number'] . ($r['effects']['applied'] !== [] ? ' (' . implode(', ', $r['effects']['applied']) . ')' : '') . ($r['effects']['skipped'] !== [] ? ' — skipped ' . implode('; ', array_map(static fn (array $s): string => $s['effect'] . ': ' . $s['why'], $r['effects']['skipped'])) : '');
hd_done($did, $t['ticket_id'], hd_land(return_path('/tickets/' . $t['ticket_id']), 'sent', 'message-' . $r['message_id']), 'ticketChanged', ['ticket_id' => $t['ticket_id'], 'number' => $t['number'], 'message_id' => $r['message_id'], 'recipients' => $r['recipients'], 'side_effects' => $r['effects']]);
