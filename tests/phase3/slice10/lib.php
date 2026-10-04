<?php
/** Helpers for the slice 10 proofs (docs/build-specs/reports-settings.md, "Proof"): slice 3's library and world plus a DATED fixture — tickets created, answered, solved, reopened, rated and timed across 60 days in IT help and Facilities. */
require dirname(__DIR__) . '/slice3/lib.php';
require_once dirname(__DIR__, 3) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__, 3) . '/app/features/reports/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/reports/present.php';
require_once dirname(__DIR__, 3) . '/app/features/reports/csv.php';

const OPEN_SET = "('new','open','pending','on_hold')";

/** The dated fixture, built once per scratch database: ['tickets' => [key => id], 'dash' => the '-SMOKE Dash Co' organization, 'empty' => the empty queue]. */
function dated(): array
{
    static $F = null;
    if ($F !== null) { return $F; }
    $file = need('HD_DEV_STATE') . '/fixture.json';
    if (is_file($file)) { return $F = json_decode((string) file_get_contents($file), true); }
    $W = world();
    $owner = as_member(1);
    $S = [];
    foreach (q('SELECT id, key FROM ticket_statuses') as $r) { $S[$r['key']] = (int) $r['id']; }
    $jo = $W['jo'];
    $dash = (int) one("INSERT INTO organizations (name, support_tier) VALUES ('-SMOKE Dash Co', 'Silver') RETURNING id");
    q("INSERT INTO organization_domains (domain, organization_id) VALUES ('dash.invalid', :o)", ['o' => $dash]);
    $dashReq = (int) one("SELECT hd_requester_for_email('dee@dash.invalid', 'SMOKE Dee Dash')");
    q('UPDATE requesters SET organization_id = :o WHERE id = :r', ['o' => $dash, 'r' => $dashReq]);
    [$c, $b] = act($owner, '/settings/queues/save.php', ['name' => 'SMOKE Empty', 'sort_order' => 9]);
    $empty = (int) ($b['record_id'] ?? 0);
    $ago = static fn (float $days): string => (new DateTimeImmutable('-' . (int) round($days * 1440) . ' minutes'))->format('c');
    // key => [queue, requester, status, priority, channel, assignee, created, first_response, solved, closed, reopen_count, first_reopened, last_activity, last_requester_reply, last_agent_reply]
    $spec = [
        'it1' => [$W['it'], $jo, 'solved', 'urgent', 'web', 27, 45, 44.98, 44, null, 0, null, 44, null, 44.98],
        'it2' => [$W['it'], $jo, 'closed', 'high', 'email', 28, 40, 39.9, 39, 35, 0, null, 35, null, 39.9],
        'it3' => [$W['it'], $jo, 'solved', 'normal', 'form', 27, 35, 34.5, 30, null, 1, 33, 30, null, 34.5],
        'it4' => [$W['it'], $jo, 'solved', 'low', 'agent', 40, 20, 19.99, 19.5, null, 0, null, 19.5, null, 19.99],
        'it5' => [$W['it'], $jo, 'open', 'normal', 'web', 27, 12, 11.9, null, null, 0, null, 2, 2, 3],
        'it6' => [$W['it'], $jo, 'open', 'high', 'email', 28, 10, null, null, null, 0, null, 10, null, null],
        'it7' => [$W['it'], $jo, 'new', 'normal', 'web', null, 400 / 1440, null, null, null, 0, null, 400 / 1440, null, null],
        'it8' => [$W['it'], $jo, 'pending', 'normal', 'web', 27, 5, 4.9, null, null, 0, null, 4, null, 4.9],
        'it9' => [$W['it'], $jo, 'open', 'low', 'web', 27, 2, 1.9, null, null, 0, null, 1.5, null, 1.9],
        'it10' => [$W['it'], $dashReq, 'new', 'normal', 'form', null, 1, null, null, null, 0, null, 1, null, null],
        'fac1' => [$W['fac'], $jo, 'solved', 'normal', 'email', 29, 30, 29.8, 28, null, 0, null, 28, null, 29.8],
        'fac2' => [$W['fac'], $jo, 'open', 'high', 'web', null, 8, 7.9, null, null, 0, null, 1, 1, 7.9],
        'fac3' => [$W['fac'], $jo, 'on_hold', 'low', 'web', 29, 3, 2.9, null, null, 0, null, 2.5, null, 2.9],
    ];
    $T = [];
    foreach ($spec as $k => $s) {
        [$queue, $req, $status, $prio, $chan, $assignee, $created, $fr, $solved, $closed, $reopens, $firstReopen, $lastAct, $lastReq, $lastAgent] = $s;
        $T[$k] = (int) one('INSERT INTO tickets (subject, requester_id, queue_id, status_id, priority, channel, assignee_member_id, created_at, first_response_at, solved_at, closed_at, reopen_count, first_reopened_at, last_activity_at, last_requester_reply_at, last_agent_reply_at)
                            VALUES (:s, :r, :q, :st, :p, :c, :a, :created, :fr, :solved, :closed, :re, :fre, :la, :lr, :lag) RETURNING id',
            ['s' => 'SMOKE dated ' . $k, 'r' => $req, 'q' => $queue, 'st' => $S[$status], 'p' => $prio, 'c' => $chan, 'a' => $assignee, 'created' => $ago($created), 'fr' => $fr === null ? null : $ago($fr), 'solved' => $solved === null ? null : $ago($solved),
             'closed' => $closed === null ? null : $ago($closed), 're' => $reopens, 'fre' => $firstReopen === null ? null : $ago($firstReopen), 'la' => $ago($lastAct), 'lr' => $lastReq === null ? null : $ago($lastReq), 'lag' => $lastAgent === null ? null : $ago($lastAgent)]);
    }
    // the SLA clocks the insert trigger started at created_at (the Standard policy follows business hours — the fixture makes its due times plain minutes, so the numbers are deterministic):
    // the first response met when answered, the resolution met when solved (met after due = a breach met late), open past due = breached
    q("UPDATE sla_targets g SET due_at = g.started_at + make_interval(mins => g.target_minutes) FROM tickets t WHERE t.id = g.ticket_id AND t.subject LIKE 'SMOKE dated %'");
    q("UPDATE sla_targets g SET met_at = t.first_response_at FROM tickets t WHERE t.id = g.ticket_id AND g.metric = 'first_response' AND t.subject LIKE 'SMOKE dated %' AND t.first_response_at IS NOT NULL");
    q("UPDATE sla_targets g SET met_at = t.solved_at FROM tickets t WHERE t.id = g.ticket_id AND g.metric = 'resolution' AND t.subject LIKE 'SMOKE dated %' AND t.solved_at IS NOT NULL");
    q("UPDATE sla_targets g SET breached_at = g.due_at FROM tickets t WHERE t.id = g.ticket_id AND t.subject LIKE 'SMOKE dated %' AND g.met_at IS NULL AND now() > g.due_at");
    // surveys: it1 good, it2 bad (slow), it3 sent and unanswered, fac1 good
    foreach ([['it1', 44, 43.9, 5, null], ['it2', 35, 34.5, 1, 'slow'], ['it3', 30, null, null, null], ['fac1', 28, 27.5, 5, null]] as [$k, $sent, $resp, $score, $reason]) {
        q('INSERT INTO satisfaction_ratings (ticket_id, requester_id, token_hash, expires_at, survey_sent_at, responded_at, score, bad_reason) VALUES (:t, :r, :h, now() + interval \'30 days\', :sent, :resp, :score, :reason)',
          ['t' => $T[$k], 'r' => $jo, 'h' => hash('sha256', $k . random_bytes(8)), 'sent' => $ago($sent), 'resp' => $resp === null ? null : $ago($resp), 'score' => $score, 'reason' => $reason]);
    }
    // time logged: Marco 90 minutes on it1 (long ago) and 30 on it5 (recent); Lena 45 on it6
    q("INSERT INTO time_entries (ticket_id, member_id, minutes, billable, worked_on) VALUES (:a, 27, 90, true, (now() - interval '44 days')::date), (:b, 27, 30, true, (now() - interval '2 days')::date), (:c, 28, 45, false, (now() - interval '9 days')::date)", ['a' => $T['it1'], 'b' => $T['it5'], 'c' => $T['it6']]);
    // presence: Marco online with capacity 2 (3 open → over), Lena away; the agent has no row
    q("INSERT INTO queue_presence (member_id, availability, capacity) VALUES (27, 'online', 2), (28, 'away', NULL) ON CONFLICT (member_id) DO UPDATE SET availability = EXCLUDED.availability, capacity = EXCLUDED.capacity");
    $F = ['tickets' => $T, 'dash' => $dash, 'empty' => $empty, 'it' => $W['it'], 'fac' => $W['fac']];
    file_put_contents($file, json_encode($F));
    return $F;
}

/** A report's JSON as Lena (or another jar): [code, data]. */
function report_json(string $report, array $q = [], ?string $jar = null): array
{
    return screen($jar ?? as_member(28), '/reports/' . $report . ($q === [] ? '' : '?' . http_build_query($q)));
}
/** A cell's integer. */
function cell_int(string $cell): int { return (int) str_replace(',', '', $cell); }
/** The period's bounds as the report applies them (the business zone), for direct SQL: [start, end). */
function bounds(array $period): array
{
    $tz = new DateTimeZone($period['timezone'] ?? 'UTC');
    return [(new DateTimeImmutable($period['from'], $tz))->setTimezone(new DateTimeZone('UTC'))->format('c'), (new DateTimeImmutable($period['to'], $tz))->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('c')];
}
function sixty(): array { return ['from' => (new DateTimeImmutable('-59 days'))->format('Y-m-d'), 'to' => (new DateTimeImmutable())->format('Y-m-d')]; }
/** A screen's JSON under a person token (an agent or a person with no session): [code, data]. */
function screen_token(int $member, string $path): array
{
    $r = req('GET', $path, ['headers' => array_merge(JSONH, ['X-Action-Token: ' . person_token($member)])]);
    return [(int) $r['code'], json_decode($r['body'], true)['data'] ?? []];
}
