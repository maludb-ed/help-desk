<?php
declare(strict_types=1);

/**
 * The mail bodies (slice 7): a template per kind in app/views/emails/ renders plain text around what the queue row says — the number and subject, what happened, the link (the member's
 * ticket page, the external's own secure link — already in the queued text), and the marker above our text. Never a note, a third party's address, an agent's internals, SLA, another token.
 */
require_once dirname(__DIR__) . '/mail/parse.php';

/** [subject, text, html] for a queued row. $ticket: number, subject; $ctx: text (the queued body), link, who, kind words. */
function notify_body(string $kind, array $ticket, array $ctx, string $audience): array
{
    $template = in_array($kind, ['created', 'replied', 'noted', 'assigned', 'new_in_queue', 'followed_changed', 'pending_elapsed', 'rated', 'escalated', 'survey'], true) ? $kind : (in_array($kind, ['sla_warning', 'sla_breached'], true) ? 'sla' : ($kind === 'mentioned' ? 'noted' : ($kind === 'cc_added' ? 'created' : 'followed_changed')));
    $data = ['ticket' => $ticket, 'ctx' => $ctx, 'audience' => $audience, 'kind' => $kind, 'marker' => MAIL_MARKER];
    $text = trim(view('emails/' . $template . '.php', $data));
    $subject = ($ticket['number'] !== '' ? '[' . $ticket['number'] . '] ' : '') . ($ctx['subject'] !== '' ? $ctx['subject'] : $ticket['subject']);
    $html = '<div style="font-family:sans-serif;font-size:14px;line-height:1.5;color:#222">' . nl2br(e($text)) . '</div>';
    $html = preg_replace_callback('#(https?://[^\s<]+)#', static fn (array $m): string => '<a href="' . $m[1] . '">' . $m[1] . '</a>', $html) ?? $html;
    return [$subject, $text, $html];
}
