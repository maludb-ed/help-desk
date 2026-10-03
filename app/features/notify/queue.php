<?php
declare(strict_types=1);

/**
 * The ONE queueing function (slice 7): notify_member() writes an in-app notification always and one outbox row per channel the person has on for that kind; notify_requester() an email
 * row for an external, or notify_member() for a member requester. Nothing for an inactive member, a requester with no address, an agent (dispatched instead), or an evaluation run.
 * dedupe_key = {dedupe}:{who}:{channel} — unique, so a second pass never repeats.
 */
require_once __DIR__ . '/kinds.php';

/** Is this request an evaluation run's (nothing leaves)? */
function notify_is_eval(): bool
{
    return !empty($GLOBALS['__run_facts']['is_eval']) || !empty($GLOBALS['__eval_run']);
}

function notify_member(PDO $pdo, int $memberId, string $kind, ?int $ticketId, string $subject, string $body, ?string $dedupe = null, ?int $messageId = null): int
{
    if ($memberId < 1 || notify_is_eval()) {
        return 0;
    }
    $m = $pdo->prepare("SELECT member_kind, status, capability FROM members WHERE id = :m");
    $m->execute(['m' => $memberId]);
    $member = $m->fetch();
    if ($member === false || $member['status'] !== 'active' || $member['capability'] === null) {
        return 0;
    }
    $prefs = find_prefs($pdo, $memberId);
    $pdo->prepare('INSERT INTO notifications (member_id, kind, ticket_id, title, body) VALUES (:m, :k, :t, :s, :b)')->execute(['m' => $memberId, 'k' => $kind, 't' => $ticketId, 's' => mb_substr($subject, 0, 300), 'b' => excerpt($body, 200)]);
    $n = 1;
    if ($member['member_kind'] === 'agent') {
        return $n;                                                     // an agent is dispatched or reads its queue; no mail, no text
    }
    $ins = $pdo->prepare('INSERT INTO notification_outbox (channel, member_id, kind, ticket_id, message_ref_id, dedupe_key, subject, body) VALUES (:c, :r, :k, :t, :m, :d, :s, :b) ON CONFLICT (dedupe_key) WHERE dedupe_key IS NOT NULL DO NOTHING');
    if ($prefs['email_enabled'] && (in_array($kind, $prefs['kinds'], true) || $kind === 'created' || $kind === 'survey')) {
        $ins->execute(['c' => 'email', 'r' => $memberId, 'k' => $kind, 't' => $ticketId, 'm' => $messageId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':email', 's' => $subject, 'b' => $body]);
        $n += $ins->rowCount();
    }
    if ($prefs['text_enabled'] && in_array($kind, $prefs['text_kinds'], true)) {
        $ins->execute(['c' => 'text', 'r' => $memberId, 'k' => $kind, 't' => $ticketId, 'm' => $messageId, 'd' => $dedupe === null ? null : $dedupe . ':m' . $memberId . ':text', 's' => $subject, 'b' => $body]);
        $n += $ins->rowCount();
    }
    return $n;
}

function notify_requester(PDO $pdo, int $requesterId, string $kind, int $ticketId, string $subject, string $body, ?string $dedupe = null, ?int $messageId = null): int
{
    if (notify_is_eval()) {
        return 0;
    }
    $r = $pdo->prepare('SELECT member_id, primary_email FROM requesters WHERE id = :r');
    $r->execute(['r' => $requesterId]);
    $row = $r->fetch();
    if ($row === false) {
        return 0;
    }
    if ($row['member_id'] !== null) {
        return notify_member($pdo, (int) $row['member_id'], $kind, $ticketId, $subject, $body, $dedupe, $messageId);
    }
    if ($row['primary_email'] === null) {
        return 0;
    }
    $ins = $pdo->prepare("INSERT INTO notification_outbox (channel, requester_id, kind, ticket_id, message_ref_id, dedupe_key, subject, body) VALUES ('email', :r, :k, :t, :m, :d, :s, :b) ON CONFLICT (dedupe_key) WHERE dedupe_key IS NOT NULL DO NOTHING");
    $ins->execute(['r' => $requesterId, 'k' => $kind, 't' => $ticketId, 'm' => $messageId, 'd' => $dedupe === null ? null : $dedupe . ':r' . $requesterId . ':email', 's' => $subject, 'b' => $body]);
    return $ins->rowCount();
}
