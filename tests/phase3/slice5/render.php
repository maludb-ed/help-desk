<?php
/** Proof — rendering (spec "Proof", 2): every placeholder renders from a fixture ticket; an unknown one → 422 naming it; {{agent.signature}} is the queue mailbox's signature, else the display name; the preview saves nothing. */
require __DIR__ . '/lib.php';
$W = world();
$marco = as_member(27); $lena = as_member(28);
$t = $W['t_jo'];
$all = implode("\n", array_map(fn ($k) => $k . '=' . '{{' . $k . '}}', array_keys(canned_placeholders_list())));
function canned_placeholders_list(): array { return ['ticket.number' => 1, 'ticket.subject' => 1, 'ticket.queue' => 1, 'ticket.status' => 1, 'requester.name' => 1, 'requester.first_name' => 1, 'organization.name' => 1, 'agent.name' => 1, 'agent.first_name' => 1, 'agent.signature' => 1, 'queue.name' => 1, 'queue.email' => 1, 'today' => 1]; }
q("UPDATE queues SET email_address = 'it@example.invalid' WHERE id = :q", ['q' => $W['it']]);
$cid = mkcanned($marco, ['title' => 'SMOKE all placeholders', 'body' => $all, 'scope' => 'personal']);
ok($cid > 0, 'a response with every placeholder saves');
[$c, $b] = act($marco, '/canned/apply.php', ['ticket' => $t, 'canned' => $cid, 'send' => 'no']);
$text = (string) ($b['text'] ?? '');
$num = ticket_row($t)['number'];
$want = ['ticket.number=' . $num, 'ticket.subject=SMOKE Printer on fire', 'ticket.queue=SMOKE IT help', 'ticket.status=', 'requester.name=SMOKE Jo', 'requester.first_name=SMOKE', 'organization.name=SMOKE Customer Co', 'agent.name=SMOKE Marco', 'agent.first_name=SMOKE', 'agent.signature=SMOKE Marco', 'queue.name=SMOKE IT help', 'queue.email=it@example.invalid', 'today=' . date('F j')];
$missing = array_values(array_filter($want, fn ($w) => !str_contains($text, $w)));
ok($c === 200 && $missing === [], 'every placeholder renders from the fixture ticket' . ($missing ? ' — missing: ' . implode(' | ', $missing) . "\n" . $text : ''));
ok(!str_contains($text, '{{'), 'nothing left unrendered');
ok((int) one('SELECT count(*) FROM messages WHERE ticket_id = :t', ['t' => $t]) === (int) one('SELECT count(*) FROM messages WHERE ticket_id = :t', ['t' => $t]) && ($b['location'] ?? null) === null && ($b['ok'] ?? false) === true, 'send=no: ok, no location');
q("INSERT INTO mailboxes (address, display_name, queue_id, signature) VALUES ('it-desk@example.invalid', 'IT desk', :q, 'The IT desk — we are here 9 to 5')", ['q' => $W['it']]);
[$c, $b] = act($marco, '/canned/apply.php', ['ticket' => $t, 'canned' => $cid, 'send' => 'no']);
ok(str_contains((string) $b['text'], 'agent.signature=The IT desk — we are here 9 to 5'), 'with a mailbox signature on the queue, {{agent.signature}} is it');
[$c, $b] = act($marco, '/canned/save.php', ['title' => 'SMOKE bad placeholder', 'body' => 'Hello {{foo}} and {{requester.name}}', 'scope' => 'personal']);
ok($c === 422 && msg($b) === '{{foo}} is not a placeholder.' && (fields($b)['body'] ?? '') === '{{foo}} is not a placeholder.', 'an unknown placeholder: 422 "{{foo}} is not a placeholder."');
hx($marco, '/canned/apply.php', ['preview' => '1', 'body' => 'warm'], '#canned-preview');                          // the helper's own token fetch logs a screen view
$before = (int) one('SELECT count(*) FROM canned_responses') . '/' . (int) one('SELECT count(*) FROM messages') . '/' . (int) one('SELECT count(*) FROM activity_log');
[$c, $b] = act($marco, '/canned/apply.php', ['preview' => '1', 'body' => 'Dear {{requester.first_name}}, re {{ticket.number}} — {{agent.name}}', 'side_effects' => ['status' => 'pending', 'add_tags' => 'a, b']]);
ok($c === 200 && ($b['text'] ?? '') === 'Dear Jo, re HD-1042 — SMOKE Marco' && ($b['side_effects'] ?? []) === ['→ Pending', '+a', '+b'], 'the form\'s preview renders against the sample ticket with the chips (' . ($b['text'] ?? '') . ')');
[$c, $body, $h] = hx($marco, '/canned/apply.php', ['preview' => '1', 'body' => 'Hi {{nope}}'], '#canned-preview');
ok($c === 200 && str_contains($body, 'id="canned-preview-text"') && str_contains($body, '{{nope}}') && str_contains($body, 'is not a placeholder'), 'the HTMX preview partial warns about an unknown placeholder');
$after = (int) one('SELECT count(*) FROM canned_responses') . '/' . (int) one('SELECT count(*) FROM messages') . '/' . (int) one('SELECT count(*) FROM activity_log');
ok($before === $after, 'the preview saves nothing and logs nothing (' . $after . ')');
[$c, $b] = act($marco, '/canned/apply.php', ['ticket' => $W['t_fac'], 'canned' => $cid, 'send' => 'no']);
ok($c === 404, 'rendering for a ticket Marco cannot see: 404');
[$c, $b] = act($lena, '/canned/apply.php', ['ticket' => $t, 'canned' => $cid, 'send' => 'no']);
ok($c === 404, 'Lena rendering Marco\'s private response: 404');
$r = req('GET', '/canned/' . $cid . '/edit', ['jar' => $marco]);
ok($r['code'] === 200 && str_contains($r['body'], 'id="canned-form-placeholder-requester-first_name"') && str_contains($r['body'], 'id="canned-preview"'), 'the form lists the placeholders and has a preview pane');
finish();
