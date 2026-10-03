<?php
declare(strict_types=1);

/** The public request form (slice 6): the switch, the rate limits (per address and per IP, from app_settings), the ledger, and the create through slice 3's create_ticket(). */
require_once __DIR__ . '/queries.php';

function request_form_enabled(PDO $pdo): bool
{
    return public_settings($pdo)['request_form_enabled'];
}

/** More than the limit from one address or one IP in the last hour? (every outcome counts — a refusal is a submission too). */
function request_rate_limited(PDO $pdo, string $email, ?string $ip): bool
{
    $s = public_settings($pdo);
    $st = $pdo->prepare("SELECT count(*) FROM request_form_submissions WHERE email = :e AND created_at > now() - interval '1 hour'");
    $st->execute(['e' => $email]);
    if ((int) $st->fetchColumn() >= $s['form_limit_per_email']) {
        return true;
    }
    if ($ip !== null) {
        $st = $pdo->prepare("SELECT count(*) FROM request_form_submissions WHERE ip_address = CAST(:ip AS inet) AND created_at > now() - interval '1 hour'");
        $st->execute(['ip' => $ip]);
        if ((int) $st->fetchColumn() >= $s['form_limit_per_ip']) {
            return true;
        }
    }
    return false;
}

function record_form_submission(PDO $pdo, string $email, ?string $ip, string $outcome, ?int $ticketId): void
{
    $pdo->prepare('INSERT INTO request_form_submissions (email, ip_address, ticket_id, outcome) VALUES (:e, CAST(:ip AS inet), :t, :o)')->execute(['e' => $email, 'ip' => $ip, 't' => $ticketId, 'o' => $outcome]);
}

/** The requester-visible categories a visitor may choose: [{category_id, name, path}]. */
function request_form_categories(PDO $pdo): array
{
    return $pdo->query('SELECT id AS category_id, name, name AS path FROM categories WHERE archived_at IS NULL AND requester_visible ORDER BY sort_order, lower(name), id')->fetchAll();   // the base table: nobody is signed in
}

/** Validate the form: errors by field; $out the clean values. */
function request_form_validate(PDO $pdo, array $in, ?array &$out = null): array
{
    $errors = [];
    $out = ['name' => trim((string) ($in['name'] ?? '')), 'email' => strtolower(trim((string) ($in['email'] ?? ''))), 'subject' => trim((string) ($in['subject'] ?? '')), 'body' => trim((string) ($in['body'] ?? '')), 'category_id' => null];
    if ($out['name'] === '' || mb_strlen($out['name']) > 120) {
        $errors['name'] = 'Tell us your name (up to 120 characters).';
    }
    if ($out['email'] === '' || mb_strlen($out['email']) > 200 || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $out['email'])) {
        $errors['email'] = 'Give an email address we can write to.';
    }
    if ($out['subject'] === '' || mb_strlen($out['subject']) > 300) {
        $errors['subject'] = 'Say what it is about, in a line (up to 300 characters).';
    }
    if ($out['body'] === '' || mb_strlen($out['body']) > 20000) {
        $errors['body'] = 'Describe it (up to 20,000 characters).';
    }
    $cat = trim((string) ($in['category'] ?? ''));
    if ($cat !== '') {
        $ok = array_filter(request_form_categories($pdo), static fn (array $c): bool => (string) $c['category_id'] === $cat);
        if ($ok === []) {
            $errors['category'] = 'Choose one of the categories offered.';
        } else {
            $out['category_id'] = (int) $cat;
        }
    }
    return $errors;
}

/**
 * Create the request in one transaction: the requester by email (a member's address lands on their row; a domain claims it), the ticket (channel form, routed by the category
 * then the default queue, created_by NULL), the first message as the requester's public reply, the attachments, the secure link, the created notice carrying it, the ledger row.
 * Answers [ticket_id, number, requester_id, raw_link]. The caller logs.
 */
function create_request(PDO $pdo, array $f, array $files, ?string $ip): array
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT hd_requester_for_email(:e, :n)');
        $st->execute(['e' => $f['email'], 'n' => $f['name']]);
        $rid = (int) $st->fetchColumn();
        $cat = null;
        if ($f['category_id'] !== null) {
            $c = $pdo->prepare('SELECT default_queue_id, default_type_id, default_priority FROM categories WHERE id = :c');
            $c->execute(['c' => $f['category_id']]);
            $cat = $c->fetch() ?: null;
        }
        $queueId = isset($cat['default_queue_id']) ? (int) $cat['default_queue_id'] : public_default_queue_id($pdo);
        if ($queueId === null) {
            throw new DomainException('No queue takes requests yet.');
        }
        $made = create_ticket($pdo, ['subject' => $f['subject'], 'requester_id' => $rid, 'submitter_member_id' => null, 'queue_id' => $queueId, 'priority' => $cat['default_priority'] ?? 'normal', 'type_id' => isset($cat['default_type_id']) ? (int) $cat['default_type_id'] : null,
            'category_id' => $f['category_id'], 'channel' => 'form', 'due_at' => null, 'external_id' => null, 'body' => $f['body'], 'assign' => true], null);
        $tid = $made['id'];
        $mid = (int) $pdo->query('SELECT min(id) FROM messages WHERE ticket_id = ' . $tid)->fetchColumn();
        foreach ($files as $file) {
            store_attachment($pdo, $tid, $mid > 0 ? $mid : null, $file, null, $rid);
        }
        $t = ticket_facts($pdo, $tid);
        notify($pdo, ['requester_id' => $rid], 'created', $tid, 'We have your request ' . $made['number'],
            'Thank you — we have your request ' . $made['number'] . ".\n\n" . requester_link_words($t, $made['raw_link']) . "\n\nReply to this email to add to it.", $mid > 0 ? $mid : null, 'created:' . $tid);
        record_form_submission($pdo, $f['email'], $ip, 'created', $tid);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ticket_id' => $tid, 'number' => $made['number'], 'requester_id' => $rid, 'raw_link' => $made['raw_link'], 'queue_id' => $queueId, 'assignee_member_id' => $made['assignee_member_id'], 'assignee_kind' => $made['assignee_kind']];
}
