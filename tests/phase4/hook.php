<?php
/** Proof driver — the kernel's Actions MCP against a stubbed hook (tests/phase4/hook.py, run under the KERNEL's venv). Sets the fixtures the resolver needs and passes their ids in the environment. */
require __DIR__ . '/lib.php';
$F = dated(); $W = world(); $T = $F['tickets'];
q("INSERT INTO ticket_types (key, label, sort_order) VALUES ('incident', 'Incident', 10) ON CONFLICT (key) DO NOTHING");
$type = (int) one("SELECT id FROM ticket_types WHERE key = 'incident'");
q("INSERT INTO categories (name, requester_visible) VALUES ('SMOKE p4 hook category', true)");
$cat = (int) one("SELECT id FROM categories WHERE name = 'SMOKE p4 hook category' ORDER BY id DESC LIMIT 1");
q("INSERT INTO canned_responses (title, body_text, scope) VALUES ('SMOKE p4 hook reset', 'x', 'global')");
$canned = (int) one("SELECT id FROM canned_responses WHERE title = 'SMOKE p4 hook reset' ORDER BY id DESC LIMIT 1");
$solved = (int) one("SELECT id FROM ticket_statuses WHERE key = 'solved'");
$env = ['P4_TOKEN' => ptoken(28), 'P4_REC_PORT' => (string) REC, 'P4_TICKET' => (string) $T['it5'], 'P4_NUMBER' => num_of($T['it5']), 'P4_QUEUE' => (string) $F['it'], 'P4_JO' => (string) $W['jo'],
        'P4_TYPE' => (string) $type, 'P4_CATEGORY' => (string) $cat, 'P4_SOLVED' => (string) $solved, 'P4_CANNED' => (string) $canned, 'P4_RULE' => '2'];
$cmd = '';
foreach ($env as $k => $v) { $cmd .= $k . '=' . escapeshellarg($v) . ' '; }
passthru($cmd . '/var/www/mcp/venv/bin/python ' . escapeshellarg(__DIR__ . '/hook.py') . ' 2>&1', $code);
exit($code);
