<?php
/**
 * The record picker in Help Desk (docs/record-picker.md): the endpoint (html/pick/index.php, app/pickers.php), the forms that carry a
 * picker, and the saves through the hidden inputs. Run through tests/picker/run.sh (scratch database, app on :8392, fake kernel on :8393).
 * The cast is the fixture's: the owner (1, admin), Priya (26, requester — no picker right), Marco (27, support), Lena (28, lead),
 * Colin (29, collaborator — sees queues, works none), Ext (31), Frontline (40, an agent). Everything it makes is named "Picker …".
 */
require dirname(__DIR__) . '/phase2/lib.php';

const P = 'http://127.0.0.1:8392';
const JSONH = ['Accept: application/json'];
function px(string $jar, string $path): array { return req('GET', P . $path, ['jar' => $jar]); }
function pxcode(string $jar, string $path): int { return px($jar, $path)['code']; }
function as_(int $m): string { static $j = []; if (!isset($j[$m])) { [$jar, $r] = sign_on_p($m); $j[$m] = $jar; } return $j[$m]; }
function sign_on_p(int $m): array { $jar = jar(); return [$jar, req('GET', P . handoff($m), ['jar' => $jar])]; }
function tok(string $jar): string { static $t = []; return $t[$jar] ??= csrf_of(req('GET', P . '/', ['jar' => $jar])['body']); }
/** A form POST as the browser sends it through htmx; JSON back for the status. [code, decoded, raw] */
function post(string $jar, string $path, array $form): array
{
    $r = req('POST', P . $path, ['jar' => $jar, 'headers' => JSONH, 'form' => $form + ['csrf_token' => tok($jar)]]);
    return [$r['code'], json_decode($r['body'], true) ?? [], $r];
}
function rows(string $html): int { return preg_match_all('/class="list-group-item list-group-item-action record-picker-row[ "]/', $html); }
function labels(string $html): array { preg_match_all('/data-label="([^"]*)"/', $html, $m); return array_map('html_entity_decode', $m[1]); }

$owner = as_(1); $priya = as_(26); $marco = as_(27); $lena = as_(28); $colin = as_(29); $ext = as_(31);

// ---- the world: 30 organizations (one with a domain and a tier), 30 more people, an IT queue with Marco named and Lena leading
pdo()->exec("DELETE FROM organizations WHERE name LIKE 'Picker Org %'");
for ($n = 1; $n <= 30; $n++) { pdo()->prepare("INSERT INTO organizations (name, support_tier, members_see_each_other) VALUES (:n, :t, true)")->execute(['n' => sprintf('Picker Org %02d', $n), 't' => $n === 5 ? 'Gold' : null]); }
$org5 = (int) one("SELECT id FROM organizations WHERE name = 'Picker Org 05'");
q("INSERT INTO organization_domains (domain, organization_id) VALUES ('picker05.example.test', :o)", ['o' => $org5]);
$org1 = (int) one("SELECT id FROM organizations WHERE name = 'Picker Org 01'");
pdo()->exec("DELETE FROM members WHERE display_name LIKE 'Picker Person %'");
for ($n = 1; $n <= 30; $n++) { pdo()->prepare("INSERT INTO members (id, member_kind, display_name, email, business_role, status, capability, roles, job_title) VALUES (:id, 'human', :n, :e, 'user', 'active', 'write', '{user}', 'Technician')")->execute(['id' => 100 + $n, 'n' => sprintf('Picker Person %02d', $n), 'e' => "pp$n@example.invalid"]); }
[$c, $b] = post($owner, '/settings/queues/save.php', ['name' => 'Picker IT help', 'department' => 5, 'include_department' => 'no', 'sort_order' => 2]);
$qid = (int) ($b['record_id'] ?? 0);
ok($qid > 0, "a queue to pick for ($c, queue $qid)");
post($owner, '/settings/queues/member.php', ['queue' => $qid, 'member' => 27]);
post($owner, '/settings/queues/member.php', ['queue' => $qid, 'member' => 28, 'is_lead' => 'yes']);

echo "== the endpoint\n";
ok(in_array(req('GET', P . '/pick/?source=person')['code'], [302, 303], true), 'anonymous: sent to sign in');
$r = px($marco, '/pick/?source=nope'); ok($r['code'] === 404 && str_contains($r['body'], 'That list does not exist') && !str_contains($r['body'], '<html'), 'an unknown source is 404 with a fragment, not a page');
ok(pxcode($marco, '/pick/?source=../etc') === 404, 'a source key is cleaned before the lookup');
foreach (['person', 'assignee', 'member', 'organization'] as $s) { $r = px($priya, "/pick/?source=$s"); ok($r['code'] === 403 && str_contains($r['body'], 'permission') && !str_contains($r['body'], '<html'), "a requester may not choose from $s (403, a fragment)"); }
ok(pxcode($ext, '/pick/?source=organization') === 403, 'an external requester may not list organizations');
ok(pxcode($colin, '/pick/?source=person') === 200 && pxcode($colin, '/pick/?source=organization') === 200, 'a collaborator (sees queues) may choose a person and an organization');
ok(pxcode($colin, '/pick/?source=assignee') === 403 && pxcode($colin, '/pick/?source=member') === 403, '... but not an assignee (works nothing) or a queue member');
ok(pxcode($marco, '/pick/?source=assignee') === 200 && pxcode($marco, '/pick/?source=member') === 403, 'support staff may choose an assignee but not name queue members');
ok(pxcode($lena, '/pick/?source=member') === 200 && pxcode($owner, '/pick/?source=member') === 200, 'a lead and the owner may name queue members');
$tpl = px($marco, '/pick/?source=person')['body'];
$total = (int) one("SELECT count(*) FROM members WHERE status = 'active' AND capability IS NOT NULL AND member_kind = 'human'");
ok(rows($tpl) === 25 && str_contains($tpl, "25 of $total shown") && str_contains($tpl, 'page=2'), "people: the first page is 25 rows and Show more says 25 of $total");
$names = labels($tpl); $sorted = $names; usort($sorted, 'strcasecmp'); ok($names === $sorted, 'in name order');
$p2 = px($marco, '/pick/?source=person&page=2')['body'];
ok(rows($p2) === $total - 25 && !str_contains($p2, 'record-picker-list') && !str_contains($p2, 'record-picker-more'), 'page 2 is the rest, rows only');
ok(!in_array('SMOKE Frontline', array_merge($names, labels($p2)), true), 'the person source lists no agent');
$ass = px($marco, '/pick/?source=assignee&q=front')['body'];
ok(str_contains($ass, 'data-label="SMOKE Frontline"') && str_contains($ass, '>Agent<'), 'the assignee source lists the agent, badged');
$me = px($marco, '/pick/?source=person&q=marco')['body'];
ok(str_contains($me, 'record-picker-detail">you<') || str_contains($me, '· you<'), 'your own row says "you"');
ok(str_contains(px($marco, '/pick/?source=person&selected=101&q=picker+person+01')['body'], 'record-picker-row active" id="record-picker-row-101" role="option" aria-selected="true"'), 'the chosen row is marked active and selected');
ok(rows(px($marco, '/pick/?source=person&q=person%2007')['body']) === 1 && rows(px($marco, '/pick/?source=person&q=technician')['body']) === 25, 'search narrows by name; the detail line (job title) is searched too');
ok(str_contains(px($marco, '/pick/?source=person&q=%3Cb%3Enothing')['body'], 'No person matches “&lt;b&gt;nothing”'), 'no match says so with the words escaped');
ok(pxcode($marco, '/pick/?source=person&q=' . str_repeat('a', 120)) === 200, 'a search longer than 80 characters is cut, not refused');
ok(rows(px($marco, '/pick/?source=person&q=pp1%40')['body']) === 0, 'an email is not searchable (the view hides it): no leak of addresses');
echo "-- dependent and undeclared params\n";
$inq = labels(px($marco, "/pick/?source=assignee&queue=$qid")['body']); sort($inq);
ok($inq === ['SMOKE Lena', 'SMOKE Marco'], 'assignees of a queue: only those in it (' . implode(', ', $inq) . ')');
ok(count(labels(px($marco, '/pick/?source=assignee')['body'])) === 25, 'without a queue: everyone assignable, 25 a page');
ok(count(labels(px($marco, '/pick/?source=assignee&queue=abc')['body'])) === 25, 'a param that is not a number is ignored');
ok(count(labels(px($marco, '/pick/?source=person&queue=' . $qid . '&active=0')['body'])) === 25, 'a param the source does not declare changes nothing');
$mem = labels(px($owner, "/pick/?source=member&not_named_in=$qid&q=smoke")['body']);
ok(!in_array('SMOKE Marco', $mem, true) && !in_array('SMOKE Lena', $mem, true) && in_array('SMOKE Colin', $mem, true) && in_array('SMOKE Frontline', $mem, true), 'queue members to add: the named are left out, an agent is listed');
echo "-- organizations\n";
$orgs = px($marco, '/pick/?source=organization')['body'];
$ototal = (int) one("SELECT count(*) FROM organizations WHERE active");
ok(rows($orgs) === 25 && str_contains($orgs, "25 of $ototal shown"), "organizations: a first page of 25 with Show more ($ototal)");
$o5 = px($marco, '/pick/?source=organization&q=picker05')['body'];
ok(rows($o5) === 1 && str_contains($o5, 'Picker Org 05') && str_contains($o5, 'Gold · picker05.example.test'), 'searched by domain; the detail line is the tier and the domains');
ok(rows(px($marco, '/pick/?source=organization&q=org%2012')['body']) === 1, 'searched by name');
ok(rows(px($marco, '/pick/?source=organization&page=2')['body']) === $ototal - 25, 'page 2 of organizations is the rest');
$row = (int) one("SELECT id FROM organizations WHERE name = 'Picker Org 03'");
ok(str_contains(px($marco, "/pick/?source=organization&selected=$row")['body'], "record-picker-row active\" id=\"record-picker-row-$row\""), 'the chosen organization is marked');

echo "== the forms\n";
function field(string $html, string $id, string $name): bool { return (bool) preg_match('/<input type="hidden" name="' . preg_quote($name, '/') . '" id="' . preg_quote($id, '/') . '" value="[^"]*" data-picker-value/', $html); }
$f = px($marco, '/tickets/new')['body'];
ok(substr_count($f, 'id="record-picker"') === 1 && substr_count($f, 'record-picker.js') === 1, 'the shell carries the one modal and the script once');
ok(substr_count($f, 'hx-vals="js:{...recordPickerValues()}"') === 1, 'the modal search box carries the htmx request');
ok(field($f, 'ticket-form-field-on-behalf-of', 'on_behalf_of') && !preg_match('/<select[^>]*name="on_behalf_of"/', $f), 'new request: on_behalf_of is a picker under its old name and id, no select left');
ok(str_contains($f, 'data-picker-source="person"') && str_contains($f, 'record-picker-label text-muted">Choose a person…<'), '... the person source, the placeholder shows');
$f = px($marco, '/tickets/new?on_behalf_of=26')['body'];
ok(str_contains($f, 'record-picker-label">SMOKE Priya<') && str_contains($f, 'value="26" data-picker-value'), '?on_behalf_of=26 pre-chooses Priya by label');
ok(!str_contains(px($priya, '/tickets/new')['body'], 'on_behalf_of'), 'a requester never sees the field');
$f = px($marco, '/requesters/new')['body'];
ok(field($f, 'requester-form-field-organization', 'organization') && !preg_match('/<select[^>]*name="organization"/', $f) && str_contains($f, 'for="requester-form-field-organization-open"'), 'new requester: the organization picker, the label points at the opener');
ok(str_contains(px($marco, "/requesters/new?organization=$org5")['body'], 'record-picker-label">Picker Org 05<'), '?organization= pre-chooses by label');
ok(str_contains(px($marco, '/requesters/')['body'], 'name="organization" id="requesters-filter-organization"') && str_contains(px($marco, '/requesters/')['body'], 'data-picker-source="organization"'), 'the requesters filter: an organization picker (Clear = any company)');
ok(str_contains(px($marco, '/views/new')['body'], 'data-picker-source="organization"') && field(px($marco, '/views/new')['body'], 'view-filter-organization_id-select', 'filters[organization_id]'), 'a saved view: the organization filter is a picker');

// a ticket for the assign panel
[$c, $b] = post($marco, '/tickets/save.php', ['for' => 'me', 'subject' => 'Picker proof ticket', 'body' => 'a request', 'queue' => $qid, 'priority' => 'normal']);
$tid = (int) ($b['record_id'] ?? 0);
ok($tid > 0, "Marco files a ticket in the queue ($c, #$tid)");
$v = px($marco, "/tickets/$tid")['body'];
ok(field($v, 'ticket-assign-form-field-assignee', 'assignee') && !preg_match('/<select[^>]*name="assignee"/', $v), 'the ticket page: the assignee is a picker; the queue stays a select');
ok(str_contains($v, 'data-picker-include="#ticket-assign-form-field-queue"') && preg_match('/<select name="queue" id="ticket-assign-form-field-queue"/', $v), '... it includes the queue field (a dependent source)');
ok(str_contains($v, 'record-picker-label text-muted">Nobody<') && str_contains($v, 'record-picker-clear d-none" id="ticket-assign-form-field-assignee-clear"'), '... unassigned shows Nobody, Clear is hidden');
ok(str_contains($v, 'id="ticket-assign-form-save-btn"'), 'the save button is still in the form');

echo "== saves through the hidden inputs\n";
[$c, $b] = post($marco, '/tickets/assign.php', ['ticket' => $tid, 'assignee' => 28, 'queue' => $qid]);
ok($c === 200 && (int) one('SELECT assignee_member_id FROM tickets WHERE id = :t', ['t' => $tid]) === 28, "assign to Lena through the hidden value ($c)");
ok(str_contains(px($marco, "/tickets/$tid")['body'], 'record-picker-label">SMOKE Lena<') , 'the page shows her label');
[$c, $b] = post($marco, '/tickets/assign.php', ['ticket' => $tid, 'assignee' => '', 'queue' => $qid]);
ok($c === 200 && one('SELECT assignee_member_id FROM tickets WHERE id = :t', ['t' => $tid]) === null, "Clear posts an empty value: Nobody ($c)");
[$c, $b] = post($marco, '/tickets/assign.php', ['ticket' => $tid, 'assignee' => 999999, 'queue' => $qid]);
ok($c >= 400 && $c < 500 && (int) one('SELECT count(*) FROM tickets WHERE id = :t AND assignee_member_id IS NOT NULL', ['t' => $tid]) === 0, "an id that names no one is refused, and the form is not replaced ($c)");
[$c, $b] = post($marco, '/tickets/save.php', ['for' => 'member', 'on_behalf_of' => 26, 'subject' => 'Picker for Priya', 'body' => 'x', 'queue' => $qid, 'priority' => 'normal']);
ok($c === 200 && (int) one("SELECT count(*) FROM tickets t JOIN requesters r ON r.id = t.requester_id WHERE t.subject = 'Picker for Priya' AND r.member_id = 26") === 1, "a ticket on behalf of Priya through on_behalf_of ($c)");
[$c, $b] = post($marco, '/requesters/save.php', ['display_name' => 'Picker Jo', 'email' => 'picker-jo@example.test', 'organization' => $org5, 'kind' => 'external']);
$rid = (int) ($b['record_id'] ?? 0);
ok($rid > 0 && (int) one('SELECT organization_id FROM requesters WHERE id = :r', ['r' => $rid]) === $org5, "a requester with an organization through the hidden input ($c)");
ok(str_contains(px($marco, "/requesters/$rid/edit")['body'], 'record-picker-label">Picker Org 05<'), 'the edit form shows the organization label');
[$c, $b] = post($marco, '/requesters/save.php', ['requester' => $rid, 'display_name' => 'Picker Jo', 'organization' => '']);
ok($c === 200 && one('SELECT organization_id FROM requesters WHERE id = :r', ['r' => $rid]) === null, "Clear removes the organization ($c)");
[$c, $b] = post($marco, '/requesters/save.php', ['requester' => $rid, 'display_name' => 'Picker Jo', 'organization' => 999999]);
ok($c === 422 || ($c >= 400 && $c < 500), "an organization id that names nothing is refused ($c) — Help Desk answers into the flash and leaves the form as it is");

$v = px($owner, "/settings/queues/$qid")['body'];
ok(field($v, 'queue-member-form-field-member', 'member') && str_contains($v, 'data-picker-params="{&quot;not_named_in&quot;:' . $qid . '}"') && !str_contains($v, 'Choose a person or an agent…</option>'), 'the queue page: Add a member is a picker, the queue is a fixed param');
[$c, $b] = post($owner, '/settings/queues/member.php', ['queue' => $qid, 'member' => 29]);
ok(in_array($c, [200, 302], true) && (int) one('SELECT count(*) FROM queue_members WHERE queue_id = :q AND member_id = 29', ['q' => $qid]) === 1, "Colin added through the hidden input ($c)");
$v = px($owner, "/settings/queues/$qid/edit")['body'];
ok(field($v, 'queue-form-field-assignee', 'default_assignee') && !preg_match('/<select[^>]*name="default_assignee"/', $v), 'the queue form: the default assignee is a picker');
[$c, $b] = post($owner, '/settings/queues/save.php', ['queue' => $qid, 'name' => 'Picker IT help', 'department' => 5, 'include_department' => 'no', 'assignment_method' => 'default_assignee', 'default_assignee' => 27, 'sort_order' => 2]);
ok($c === 200 && (int) one('SELECT default_assignee_member_id FROM queues WHERE id = :q', ['q' => $qid]) === 27, "the default assignee saved ($c)");
ok(str_contains(px($owner, "/settings/queues/$qid/edit")['body'], 'record-picker-label">SMOKE Marco<'), '... and the form shows Marco');

echo "== what stays a select, by the rule\n";
ok(str_contains(px($marco, "/tickets/$tid")['body'], 'name="queue"'), 'queues stay selects (a short, per-department list)');
$b = px($owner, '/bulk/?tickets[]=' . $tid)['body'];
ok(str_contains($b, '<select name="assignee" id="bulk-form-field-assignee"'), 'the bulk form keeps its assignee select (Keep / Nobody / Me)');
ok(!preg_match('/PHP (Warning|Notice|Fatal|Deprecated)/i', (string) @file_get_contents(getenv('HD_LOG') ?: '/tmp/helpdesk-picker-state/app.log')), 'no PHP warning or notice in the server log');
finish();
