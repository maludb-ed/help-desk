<?php
/**
 * Helpers for the slice 3 proofs (docs/build-specs/tickets-core.md, "Proof") — slice 2's library (slice 1's + records_ro) plus the WORLD: two queues linked to the
 * fixture's departments, a customer company with a domain, an external requester, and the tickets the proofs reason about. The cast: the owner (1, admin),
 * Priya (26, requester, Facilities), Marco (27, support, IT), Lena (28, lead), Colin (29, collaborator, Facilities), Ext (31, an invited external of Customer Co), Frontline (40, agent, IT).
 */
require dirname(__DIR__) . '/slice2/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/shell/nav.php';

/** The world, built once per scratch database (idempotent through the state file). */
function world(): array
{
    static $W = null;
    if ($W !== null) { return $W; }
    $file = need('HD_DEV_STATE') . '/world.json';
    if (is_file($file)) { return $W = json_decode((string) file_get_contents($file), true); }
    foreach ([1, 26, 27, 28, 29, 31] as $m) { as_member($m); }                 // everyone signed on once: their mirror rows and requester rows exist
    $owner = as_member(1);
    [$c, $b] = act($owner, '/settings/queues/save.php', ['name' => 'SMOKE IT help', 'department' => 5, 'include_department' => 'yes', 'sort_order' => 2]);
    $it = (int) $b['record_id'];
    [$c, $b] = act($owner, '/settings/queues/save.php', ['name' => 'SMOKE Facilities', 'department' => 6, 'include_department' => 'yes', 'sort_order' => 3]);
    $fac = (int) $b['record_id'];
    act($owner, '/settings/queues/member.php', ['queue' => $it, 'member' => 28, 'is_lead' => 'yes']);       // Lena leads IT help (named)
    $org = (int) one("INSERT INTO organizations (name, support_tier, members_see_each_other) VALUES ('SMOKE Customer Co', 'Gold', true) RETURNING id");
    q("INSERT INTO organization_domains (domain, organization_id) VALUES ('customer.invalid', :o)", ['o' => $org]);
    $jo = (int) one("SELECT hd_requester_for_email('jo@customer.invalid', 'SMOKE Jo')");
    q("UPDATE requesters SET phone = '+1 555 0199', notes = 'SMOKE jo is important' WHERE id = :r", ['r' => $jo]);
    $ext = requester_of_member(31);
    q('UPDATE requesters SET organization_id = :o WHERE id = :r', ['o' => $org, 'r' => $ext]);        // the invited external belongs to Customer Co
    $priya = requester_of_member(26);
    $W = ['it' => $it, 'fac' => $fac, 'org' => $org, 'jo' => $jo, 'ext' => $ext, 'priya' => $priya];
    $W['t_jo'] = mkticket($jo, $it, 'SMOKE Printer on fire');                   // Jo's, in IT help
    $W['t_jo2'] = mkticket($jo, $it, 'SMOKE Printer smoking again', 'open');
    $W['t_fac'] = mkticket($jo, $fac, 'SMOKE Chair broken');                    // Jo's, in Facilities
    $W['t_p1'] = mkticket($priya, $W['t_jo'] > 0 ? general_queue() : 1, 'SMOKE Priya needs a mouse');
    $W['t_p2'] = mkticket($priya, general_queue(), 'SMOKE Priya needs a desk', 'open');
    q("INSERT INTO messages (ticket_id, kind, author_requester_id, body_text, channel) VALUES (:t, 'public_reply', :r, 'SMOKE it is on fire, help', 'portal')", ['t' => $W['t_jo'], 'r' => $jo]);
    q("INSERT INTO messages (ticket_id, kind, author_member_id, body_text) VALUES (:t, 'internal_note', 27, 'SMOKE internal: check the fuse first')", ['t' => $W['t_jo']]);
    q("INSERT INTO messages (ticket_id, kind, author_member_id, body_text) VALUES (:t, 'internal_note', 29, 'SMOKE facilities note: order a new chair')", ['t' => $W['t_fac']]);
    q("INSERT INTO ticket_followers (ticket_id, kind, member_id) VALUES (:t, 'follower', 29)", ['t' => $W['t_jo2']]);     // Colin follows an IT ticket
    file_put_contents($file, json_encode($W));
    return $W;
}

function ticket_status(int $id): string { return (string) one('SELECT s.key FROM tickets t JOIN ticket_statuses s ON s.id = t.status_id WHERE t.id = :t', ['t' => $id]); }
function ticket_row(int $id): array { return q('SELECT * FROM tickets WHERE id = :t', ['t' => $id])[0]; }
function outbox(int $ticket, ?string $kind = null): array { return q('SELECT * FROM notification_outbox WHERE ticket_id = :t' . ($kind ? ' AND kind = :k' : '') . ' ORDER BY id', ['t' => $ticket] + ($kind ? ['k' => $kind] : [])); }
function dispatches(int $ticket): array { return q('SELECT * FROM agent_dispatches WHERE ticket_id = :t ORDER BY id', ['t' => $ticket]); }
function targets(int $ticket): array { $out = []; foreach (q('SELECT metric, met_at, paused_at, due_at FROM sla_targets WHERE ticket_id = :t ORDER BY id', ['t' => $ticket]) as $r) { $out[$r['metric']] = $r; } return $out; }
/** A multipart upload as a signed-on person: [status, body]. */
function upload(string $jar, string $path, array $form, string $filePath, string $name = 'file'): array
{
    static $tok = [];
    $tok[$jar] ??= csrf_of(req('GET', '/', ['jar' => $jar])['body']);
    $ch = curl_init(BASE . $path);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_POSTFIELDS => $form + ['csrf_token' => $tok[$jar], $name => new CURLFile($filePath)]]);
    $r = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($r, true) ?? []];
}
/** A page's text and JSON must never leak: the fixture's external addresses and any secure token. */
function leaks(string $body): array
{
    $found = [];
    foreach (['jo@customer.invalid', 'ext@customer.invalid', 'new@customer.invalid', 'boss@customer.invalid'] as $e) { if (str_contains($body, $e)) { $found[] = $e; } }
    if (preg_match('/\/t\/[a-f0-9]{48}/', $body)) { $found[] = 'a secure token'; }
    return $found;
}
