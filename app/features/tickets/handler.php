<?php
declare(strict_types=1);

/**
 * The prelude of every ticket handler and screen (slice 3, THE EXEMPLAR): the bootstrap, the slice's files and the gates a ticket write starts with.
 * A ticket the caller may not see does not exist to them — 404 "Ticket not found.", the same sentence as a missing one.
 */
require_once dirname(__DIR__, 2) . '/bootstrap.php';
require_once dirname(__DIR__) . '/statuses/queries.php';
require_once dirname(__DIR__) . '/vocabulary/queries.php';
require_once dirname(__DIR__) . '/fields/validate.php';
require_once dirname(__DIR__) . '/queues/queries.php';
require_once dirname(__DIR__) . '/sla/queries.php';
require_once dirname(__DIR__) . '/requesters/queries.php';
require_once dirname(__DIR__) . '/requesters/present.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/transition.php';
require_once __DIR__ . '/dispatch.php';
require_once dirname(__DIR__) . '/messages/queries.php';
require_once dirname(__DIR__) . '/messages/present.php';
require_once dirname(__DIR__) . '/views/board.php';
require_once dirname(__DIR__) . '/views/present.php';
require_once dirname(__DIR__) . '/canned/queries.php';
require_once dirname(__DIR__) . '/canned/present.php';
require_once dirname(__DIR__) . '/canned/render.php';
require_once dirname(__DIR__) . '/bulk/handler.php';
require_once dirname(__DIR__) . '/ratings/queries.php';
require_once dirname(__DIR__) . '/ratings/present.php';
require_once dirname(__DIR__) . '/public/secure.php';
require_once dirname(__DIR__, 2) . '/mail.php';
require_once dirname(__DIR__) . '/settings/queries.php';
require_once dirname(__DIR__) . '/notify/queue.php';
require_once dirname(__DIR__) . '/notify/send.php';
require_once dirname(__DIR__) . '/mail/inbound.php';
require_once dirname(__DIR__) . '/mail/present.php';

/** The ticket a request names (`ticket`, `ticket_id`, `id`), visible to the caller — else 404 "Ticket not found." */
function ticket_from_request(PDO $pdo, string ...$fields): array
{
    $id = null;
    foreach ($fields === [] ? ['ticket', 'ticket_id', 'id'] : $fields as $f) {
        $id ??= request_integer($f);
    }
    if ($id === null) {
        refuse(422, 'Say which ticket.');
    }
    return ticket_load($pdo, $id);
}

function ticket_load(PDO $pdo, int $id): array
{
    require_can_see_ticket($id);
    return ticket_facts($pdo, $id) ?? refuse(404, 'Ticket not found.');
}

/** The caller is the ticket's own requester (by member id) or a cc'd member. */
function is_own_ticket(PDO $pdo, array $t): bool
{
    $me = (int) current_member_id();
    if ($t['requester_member_id'] === $me) {
        return true;
    }
    $st = $pdo->prepare("SELECT 1 FROM ticket_followers WHERE ticket_id = :t AND kind = 'cc' AND member_id = :m");
    $st->execute(['t' => $t['ticket_id'], 'm' => $me]);
    return $st->fetchColumn() !== false;
}

/** May work it — else 403 in the queue's sentence. */
function require_work(array $t): void
{
    if (!can_work_ticket($t['ticket_id'])) {
        refuse(403, 'You are not in this ticket\'s queue.');
    }
}

/** May note on it: tickets.note in its queue (a collaborator), or work. */
function can_note_ticket(PDO $pdo, array $t): bool
{
    if (can_work_ticket($t['ticket_id'])) {
        return true;
    }
    $st = $pdo->prepare("SELECT 1 FROM hd_queue_ids_with_right('tickets.note') q WHERE q = :q");
    $st->execute(['q' => $t['queue_id']]);
    return $st->fetchColumn() !== false;
}

/** The handler's end for a ticket: lands on the ticket, fires ticketChanged. */
function ticket_done(array $t, string $did, array $extra = [], ?string $notice = null): never
{
    hd_done($did, $t['ticket_id'], hd_land(return_path('/tickets/' . $t['ticket_id']), $notice), 'ticketChanged', ['ticket_id' => $t['ticket_id'], 'number' => $t['number']] + $extra);
}

/** Log a ticket event with ticket_id and queue_id on the row. */
function ticket_log(PDO $pdo, array $t, string $action, array $opts = []): void
{
    log_activity($pdo, $action, 'ticket', $t['ticket_id'], ['ticket_id' => $t['ticket_id'], 'queue_id' => $opts['queue_id'] ?? $t['queue_id']] + $opts);
}

/** The custom values a form or an agent sent: `custom` as JSON (an agent) or custom[key] (the form). */
function request_custom(): ?array
{
    if (!array_key_exists('custom', $_POST)) {
        return null;
    }
    $raw = $_POST['custom'];
    if (!is_array($raw)) {
        $raw = json_decode((string) $raw, true);
        if (!is_array($raw)) {
            hd_refuse_fields(['custom' => 'Custom values are JSON: {"key": value, …}.']);
        }
    }
    return $raw;
}

/** A timestamp field (datetime-local or ISO), in the caller's zone, as ISO — null when blank; refuses when unreadable. */
function request_when(string $name, array &$errors, string $label): ?string
{
    $v = (string) req_val($name);
    if ($v === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($v, new DateTimeZone(member_timezone())))->format('c');
    } catch (Exception) {
        $errors[$name] = $label . ' is a date and time.';
        return null;
    }
}

/** One scalar from SQL. */
function one_value(PDO $pdo, string $sql, array $args = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->fetchColumn();
}

/** Run SQL as another member (the visibility functions read app.member_id) and come back; inside the caller's transaction. */
function as_member_sql(PDO $pdo, int $memberId, callable $fn): mixed
{
    $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', (string) $memberId]);      // session-wide: a transaction-local one would vanish outside a transaction
    try {
        return $fn();
    } finally {
        $pdo->prepare('SELECT set_config(?, ?, false)')->execute(['app.member_id', (string) (int) current_member_id()]);
    }
}

/** The members who can see a ticket's INTERNALS (a note's mention), among a list: ids. */
function members_who_see(PDO $pdo, int $ticketId, array $memberIds): array
{
    $out = [];
    foreach (array_values(array_unique(array_map('intval', $memberIds))) as $m) {
        if ($m > 0 && (bool) as_member_sql($pdo, $m, static fn (): mixed => one_value($pdo, 'SELECT hd_can_see_internals(:t)', ['t' => $ticketId]))) {
            $out[] = $m;
        }
    }
    return $out;
}

/** The requester-side recipients of a public reply: the requester row and the CCs. [['requester_id' => …] | ['member_id' => …]] */
function reply_recipients(PDO $pdo, array $t): array
{
    $out = [['requester_id' => $t['requester_id']]];
    $st = $pdo->prepare("SELECT member_id, requester_id FROM ticket_followers WHERE ticket_id = :t AND kind = 'cc'");
    $st->execute(['t' => $t['ticket_id']]);
    foreach ($st->fetchAll() as $f) {
        $out[] = $f['member_id'] !== null ? ['member_id' => (int) $f['member_id']] : ['requester_id' => (int) $f['requester_id']];
    }
    return $out;
}
