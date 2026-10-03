<?php
/*
 * Proof: the deploy vhost's URL rules (deploy/apache-helpdesk.conf). Meaningful under HD_APP=apache tests/phase2/run.sh,
 * where a real Apache serves the template rendered as the installer renders it; under php -S the same checks run against
 * tests/dev_router.php, which mirrors the rewrites. Nothing outside html/ is reachable, /api is blocked but for health, the public
 * doors are rewritten but answer 404 until their slices.
 */
require __DIR__ . '/lib.php';
[$j, ] = sign_on(27);
echo "URL rules\n";
foreach (['/sso' => 403, '/sso/logout' => 405] as $path => $want) {
    ok(req('GET', $path)['code'] === $want, "GET $path → $want (the receivers answer at their extension-less names)");
}
ok(page($j, '/settings/tokens/')['code'] === 200 && page($j, '/settings/tokens')['code'] === 200, '/settings/tokens/ and /settings/tokens both reach settings/tokens/index.php');
ok(page($j, '/my-tickets')['code'] === 200 && page($j, '/my-tickets/')['code'] === 200, '/my-tickets and /my-tickets/ both resolve to my-tickets.php');
ok(page($j, '/tickets/new')['code'] === 200, '/tickets/new reaches tickets/form.php (the placeholder)');
ok(page($j, '/kb/')['code'] === 200 && page($j, '/kb')['code'] === 200, '/kb/ and /kb reach kb/index.php');
ok(page($j, '/settings/csat')['code'] === 403 && page($j, '/settings/agents')['code'] === 403, '/settings/csat and /settings/agents resolve (403 for support staff, by the right)');
ok(req('GET', '/nosuchscreen', ['jar' => $j])['code'] === 404, 'an unknown path: 404');
ok(req('GET', '/request')['code'] === 200 && req('GET', '/t/' . str_repeat('ab', 24))['code'] === 404 && req('GET', '/t/' . str_repeat('ab', 24) . '/reply')['code'] === 405, '/request answers (slice 6); an unknown /t/{token} is the dead-link page (404); GET on its /reply is 405');
ok(in_array(req('GET', '/settings/automation/1/runs')['code'], [302, 303], true), '/settings/automation/1/runs reaches runs.php (slice 8; an anonymous GET is sent to sign in)');
ok(req('GET', '/kb/s/no-such-section', ['jar' => $j])['code'] === 404 && req('GET', '/kb/no-such-article', ['jar' => $j])['code'] === 404, '/kb/s/{slug} and /kb/{slug} are rewritten (slice 9); an unknown slug is 404');
ok(req('GET', '/reports/backlog', ['jar' => $j])['code'] === 404, '/reports/backlog is rewritten but its handler is not built: 404 (slice 10 brings it)');
ok(req('POST', '/channels/malumail/mail')['code'] === 403 && req('GET', '/channels/malumail/mail')['code'] === 405, 'POST /channels/malumail/mail unsigned: 403 (slice 7); GET: 405');
$h = req('GET', '/api/v1/health');
ok($h['code'] === 200 && json_decode($h['body'], true)['application'] === 'helpdesk', '/api/v1/health answers');
ok(req('GET', '/api/v1/health.php')['code'] === 200, '/api/v1/health.php answers too');
ok(req('GET', '/api/v1/other')['code'] === 404 && req('GET', '/api/')['code'] === 404, 'anything else under /api is 404');
$m = req('GET', '/manifest.webmanifest');
ok($m['code'] === 200 && preg_match('/^Content-Type: application\/(manifest\+)?json/mi', $m['headers']) === 1, '/manifest.webmanifest is served as JSON (' . (preg_match('/^Content-Type: (.+)$/mi', $m['headers'], $t) ? trim($t[1]) : '?') . ')');
$s = req('GET', '/assets/css/theme.min.css');
ok($s['code'] === 200 && preg_match('/^Content-Type: text\/css/mi', $s['headers']) === 1, 'static assets are served with their type');
foreach (['/config/.env', '/db/001_roles_and_identity.sql', '/app/bootstrap.php', '/bin/directory_sync.php', '/mcp/db.py', '/CLAUDE.md', '/maludb-os.json', '/.git/config', '/tests/phase2/lib.php', '/deploy/ROOT_STEPS.sh'] as $p) {
    $r = req('GET', $p);
    ok(in_array($r['code'], [403, 404], true) && !str_contains($r['body'], 'ACTION_TOKEN_KEY') && !str_contains($r['body'], 'CREATE TABLE'), "$p is not served ({$r['code']})");
}
finish();
