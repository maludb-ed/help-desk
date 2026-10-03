<?php
/** Proof — parsing (spec "Proof", 3): the text above "On … wrote:", "-----Original Message-----" and our marker; the full text stays in inbound_email; an auto-reply → no ticket; a DSN for our Message-ID → bounce and the outbound row failed; a sender bounced twice → suppressed. */
require __DIR__ . '/lib.php';
$W = world();
ensure_mailbox();
echo "1. Above the line\n";
$cases = [
    ["Thanks a lot.\n\nOn Fri, Oct 3, 2026 at 9:00 AM Support wrote:\n> old\n> old", 'Thanks a lot.'],
    ["Thanks a lot.\n-----Original Message-----\nFrom: x\nSent: y", 'Thanks a lot.'],
    ["Thanks a lot.\n\n" . MAIL_MARKER . "\nour text", 'Thanks a lot.'],
    ["Line one\n> quoted\nLine two\n> quoted to the end\n> more", "Line one\nLine two"],
    ["Hi\nFrom: Someone\nSent: Monday\nTo: me", 'Hi'],
];
$bad = [];
foreach ($cases as $i => [$in, $want]) { if (mail_strip_quoted($in) !== $want) { $bad[] = $i . ': ' . json_encode(mail_strip_quoted($in)); } }
ok($bad === [], 'mail_strip_quoted: five shapes' . ($bad ? ' — wrong: ' . implode('; ', $bad) : ''));
put_mail(30, ['from' => 'Quoter <quoter@customer.invalid>', 'subject' => 'Quoted'], "Only this.\n\nOn Thu, Oct 2, 2026 Someone wrote:\n> everything below");
webhook(30);
$row = inbound_row(30);
$m = q('SELECT body_text FROM messages WHERE id = :m', ['m' => (int) $row['message_ref_id']])[0];
ok($m['body_text'] === 'Only this.' && str_contains((string) $row['body_text'], 'everything below'), 'the message holds the text above the line; inbound_email keeps the full text');
echo "2. Auto-replies and bounces\n";
$n = (int) one('SELECT count(*) FROM tickets');
put_mail(31, ['from' => 'Away <away@customer.invalid>', 'subject' => 'Out of office', 'auto_submitted' => 'auto-replied'], 'I am away.');
webhook(31);
ok(inbound_row(31)['status'] === 'auto_reply' && inbound_row(31)['ticket_id'] === null && (int) one('SELECT count(*) FROM tickets') === $n, 'Auto-Submitted: auto-replied → auto_reply, no ticket');
put_mail(32, ['from' => 'Bulk <news@customer.invalid>', 'subject' => 'Newsletter', 'precedence' => 'bulk'], 'Buy now.');
webhook(32);
ok(inbound_row(32)['status'] === 'auto_reply', 'Precedence: bulk → auto_reply');
// a DSN for one of our Message-IDs
$t = (int) act(as_member(27), '/tickets/save.php', ['subject' => 'SMOKE will bounce', 'body' => 'x', 'queue' => $W['it'], 'requester' => 'gone@customer.invalid'])[1]['record_id'];
act(as_member(27), '/tickets/reply.php', ['ticket' => $t, 'body' => 'Hello?']);
send_batch();
$out = q("SELECT provider_message_id FROM outbound_email WHERE ticket_id = :t AND status = 'sent' ORDER BY id DESC LIMIT 1", ['t' => $t])[0] ?? null;
ok($out !== null && $out['provider_message_id'] !== null, 'our mail went out with a Message-ID');
put_mail(33, ['from' => 'Mail Delivery System <MAILER-DAEMON@mail.invalid>', 'subject' => 'Undelivered Mail Returned to Sender', 'content_type' => 'multipart/report; report-type=delivery-status'], 'This is the mail system.', ['parts' => [['text' => "Reporting-MTA: dns; mail.invalid\nOriginal-Message-ID: " . $out['provider_message_id'] . "\nStatus: 5.1.1"]]]);
webhook(33);
ok(inbound_row(33)['status'] === 'bounce' && inbound_row(33)['ticket_id'] === null && (string) one("SELECT status FROM outbound_email WHERE provider_message_id = :id", ['id' => $out['provider_message_id']]) === 'failed' && (int) one('SELECT count(*) FROM tickets') === $n + 1, 'a DSN for our Message-ID → bounce, the outbound row failed, no ticket');
echo "3. A sender bounced twice\n";
q("INSERT INTO outbound_email (to_address, subject, status, error) VALUES ('twice@customer.invalid', 'x', 'failed', 'bounced'), ('twice@customer.invalid', 'y', 'failed', 'bounced')");
put_mail(34, ['from' => 'Twice <twice@customer.invalid>', 'subject' => 'Hello again'], 'Are you there?');
webhook(34);
ok(inbound_row(34)['status'] === 'suppressed' && inbound_row(34)['ticket_id'] === null, 'a sender whose mail bounced twice in 7 days: suppressed, no ticket');
put_mail(35, ['from' => MAILBOX_ADDRESS, 'subject' => 'Myself'], 'loop');
webhook(35);
ok(inbound_row(35)['status'] === 'ignored', 'our own address: ignored');
finish();
