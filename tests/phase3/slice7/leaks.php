<?php
/** Proof — nothing sensitive leaves (spec "Proof", 6): every queued body and everything the stubs received, grepped for the note's text, another requester's address, a surname on a requester mail, any token but the recipient's own link. */
require __DIR__ . '/lib.php';
$W = world();
ensure_mailbox();
$bodies = array_map(fn ($r) => ['who' => $r['requester_id'] !== null ? 'r' . $r['requester_id'] : 'm' . $r['member_id'], 'ticket' => (int) $r['ticket_id'], 'text' => $r['subject'] . "\n" . $r['body']], q('SELECT * FROM notification_outbox'));
$sent = array_map(fn ($m) => ['who' => 'to:' . $m['to'], 'ticket' => null, 'text' => $m['subject'] . "\n" . $m['text'] . "\n" . $m['html']], malumail_sent());
$all = array_merge($bodies, $sent);
ok(count($all) > 10, count($all) . ' bodies to check');
$notes = q("SELECT body_text FROM messages WHERE kind = 'internal_note'");
$leak = [];
foreach ($all as $b) {
    foreach ($notes as $n) { if ($n['body_text'] !== '' && str_contains($b['text'], $n['body_text'])) { $leak[] = 'note in ' . $b['who']; } }
    if (str_contains($b['text'], 'SECRETNOTE')) { $leak[] = 'SECRETNOTE in ' . $b['who']; }
}
ok($leak === [], 'no note\'s text in any body' . ($leak ? ' — ' . implode(', ', array_unique($leak)) : ''));
// a requester's mail names no other requester's address and no surname of staff
$leak = [];
foreach ($all as $b) {
    if (!str_starts_with($b['who'], 'r') && !str_starts_with($b['who'], 'to:')) { continue; }
    foreach (['boss@customer.invalid', 'stranger@elsewhere.invalid', 'colleague@customer.invalid'] as $addr) {
        if (str_contains($b['text'], $addr) && !str_contains($b['who'], $addr)) { $leak[] = $addr . ' in ' . $b['who']; }
    }
    if (preg_match('/SMOKE (Marco|Lena|Colin)\b/', $b['text'])) { $leak[] = 'a surname in ' . $b['who']; }
}
ok($leak === [], 'a requester\'s mail carries no other requester\'s address and no staff surname' . ($leak ? ' — ' . implode(', ', array_unique($leak)) : ''));
// every secure token in a requester's body is a live link of THAT ticket
$leak = [];
foreach ($bodies as $b) {
    if (!str_starts_with($b['who'], 'r')) { continue; }
    preg_match_all('#/t/([a-f0-9]{48})#', $b['text'], $m);
    foreach ($m[1] as $tok) {
        $owner = one('SELECT ticket_id FROM ticket_links_secure WHERE token_hash = :h', ['h' => hash('sha256', $tok)]);
        if ((int) $owner !== $b['ticket']) { $leak[] = 'a token of ticket ' . $owner . ' in a mail about ' . $b['ticket']; }
    }
}
ok($leak === [], 'every token in a requester\'s mail is that ticket\'s own link' . ($leak ? ' — ' . implode(', ', $leak) : ''));
$leak = [];
foreach ($bodies as $b) { if (str_starts_with($b['who'], 'm') && preg_match('#/t/[a-f0-9]{48}#', $b['text'])) { $leak[] = $b['who']; } }
ok($leak === [], 'a member\'s mail never carries a secure link' . ($leak ? ' — ' . implode(', ', array_unique($leak)) : ''));
$log = q("SELECT action, after, before FROM activity_log WHERE action IN ('notification.send', 'mail.inbound', 'mail.outbound', 'mail.inbound_retry', 'mailbox.create', 'mailbox.update', 'mailbox.archive')");
$leak = [];
foreach ($log as $r) {
    $payload = preg_replace('/<[^>]*@[^>]*>/', '', (string) $r['after'] . (string) $r['before']);                       // a Message-ID is no address
    if (str_starts_with($r['action'], 'mailbox.')) { $payload = str_replace(['support@example.invalid', 'second@example.invalid', 'json@example.invalid'], '', $payload); }   // the mailbox's own address is ours
    if (preg_match('/[a-z0-9._-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $payload) || str_contains($payload, 'whsec_')) { $leak[] = $r['action'] . ': ' . mb_substr($payload, 0, 80); }
}
ok($leak === [], 'no address and no secret in the mail and notice log rows' . ($leak ? ' — ' . implode(' | ', array_slice($leak, 0, 3)) : ''));
finish();
