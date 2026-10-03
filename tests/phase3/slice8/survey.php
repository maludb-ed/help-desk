<?php
/** Proof — the survey step (spec "Proof", 7): a solved ticket past the delay in a surveyed queue → one rating row with a hash, the raw token only in the outbox body; a second pass sends none; a requester with no address → none. */
require __DIR__ . '/lib.php';
$W = world();
$marco = as_member(27);
act(as_member(1), '/settings/csat.php', ['enabled' => 'yes', 'send_delay_minutes' => 60]);
$t = (int) act($marco, '/tickets/save.php', ['subject' => 'SMOKE survey due', 'body' => 'x', 'queue' => $W['it'], 'requester' => $W['jo']])[1]['record_id'];
act($marco, '/tickets/transition.php', ['ticket' => $t, 'status' => 'open']);
act($marco, '/tickets/transition.php', ['ticket' => $t, 'status' => 'solved', 'resolution_code' => 'resolved']);
$r = worker(['survey'], at('+30 minutes'));
ok(rating_row($t) === null, 'solved 30 minutes ago with a 60-minute delay: not yet');
$since = last_activity_id();
$r = worker(['survey'], at('+90 minutes'));
$row = rating_row($t);
ok($r['survey'] >= 1 && $row !== null && strlen($row['token_hash']) === 64 && $row['score'] === null, 'past the delay: one rating row with a hash');
$ob = outbox($t, 'survey');
ok(count($ob) === 1 && preg_match('#/t/[a-f0-9]{48}/rate\?r=([a-f0-9]{48})#', $ob[0]['body'], $m) === 1 && hash('sha256', $m[1]) === $row['token_hash'], 'the raw token is in the survey mail only, hashing to the row');
ok(!str_contains(json_encode(activity('survey.send', $since)), $m[1]) && json_decode(activity('survey.send', $since)[0]['after'], true)['via'] === 'auto', 'survey.send logged via auto, without the token');
$r = worker(['survey'], at('+120 minutes'));
ok($r['survey'] === 0 && count(outbox($t, 'survey')) === 1, 'a second pass sends none');
$noaddr = (int) one("INSERT INTO requesters (kind, display_name) VALUES ('external', 'SMOKE Nobody') RETURNING id");
$t2 = (int) act($marco, '/tickets/save.php', ['subject' => 'SMOKE no address', 'body' => 'x', 'queue' => $W['it'], 'requester' => $noaddr])[1]['record_id'];
act($marco, '/tickets/transition.php', ['ticket' => $t2, 'status' => 'open']);
act($marco, '/tickets/transition.php', ['ticket' => $t2, 'status' => 'solved', 'resolution_code' => 'resolved']);
worker(['survey'], at('+3 hours'));
ok(rating_row($t2) === null, 'a requester with no address: none');
function rating_row(int $ticket): ?array { $r = q('SELECT * FROM satisfaction_ratings WHERE ticket_id = :t', ['t' => $ticket]); return $r[0] ?? null; }
finish();
