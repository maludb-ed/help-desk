<?php
declare(strict_types=1);

/**
 * Tickets — the writers (slice 3). Every function runs inside the handler's transaction and answers what the log row needs; a refusal is a
 * DomainException in the sentence the screen shows. The triggers of db/010 keep the number, the policy, the clock and the dates.
 */

const ATTACHMENT_MIME_ALLOW = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml', 'application/pdf', 'text/plain', 'text/csv', 'text/markdown', 'application/json',
    'application/zip', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
const ATTACHMENT_MAX_FILES = 5;

function attachment_max_bytes(): int
{
    return max(1024, (int) (env('ATTACHMENT_MAX_BYTES', '10485760') ?: 10485760));
}

/** The plain body as sanitised HTML paragraphs (no editor in v1). */
function body_to_html(string $text): string
{
    $paras = preg_split('/\n{2,}/', trim($text)) ?: [];
    return implode('', array_map(static fn (string $p): string => '<p>' . nl2br(e($p)) . '</p>', $paras));
}

/**
 * File a ticket. $f: subject, body, as_note, requester_id, submitter_member_id, queue_id, category_id, type_id, priority, tags[], custom (validated),
 * due_at, cc_requester_ids[], external_id, channel, assign (apply the queue's default assignee). Answers ['id', 'number', 'raw_link', 'assignee_member_id', 'assignee_kind'].
 */
function create_ticket(PDO $pdo, array $f, ?int $actorMemberId): array
{
    $st = $pdo->prepare('INSERT INTO tickets (subject, requester_id, submitter_member_id, queue_id, status_id, priority, type_id, category_id, channel, custom_values, due_at, external_id, created_by)
                         VALUES (:s, :r, :sub, :q, (SELECT id FROM ticket_statuses WHERE is_default), :p, :t, :c, :ch, CAST(:cv AS jsonb), :d, :e, :by) RETURNING id, number');
    $st->execute(['s' => $f['subject'], 'r' => $f['requester_id'], 'sub' => $f['submitter_member_id'], 'q' => $f['queue_id'], 'p' => $f['priority'], 't' => $f['type_id'], 'c' => $f['category_id'],
                  'ch' => $f['channel'], 'cv' => json_encode((object) ($f['custom'] ?? [])), 'd' => $f['due_at'], 'e' => $f['external_id'], 'by' => $actorMemberId]);
    $row = $st->fetch();
    $id = (int) $row['id'];
    foreach ($f['cc_requester_ids'] ?? [] as $rid) {
        add_follower($pdo, $id, 'cc', null, (int) $rid, $actorMemberId);
    }
    foreach ($f['tags'] ?? [] as $slug) {
        $tagId = tag_by_slug_or_create($pdo, (string) $slug);
        $pdo->prepare('INSERT INTO ticket_tags (ticket_id, tag_id) VALUES (:t, :g) ON CONFLICT DO NOTHING')->execute(['t' => $id, 'g' => $tagId]);
    }
    // the first message: the requester's public reply (a member requester by their member id; an external by their requester row) — or an internal note by the filer
    if (($f['body'] ?? '') !== '') {
        $req = $pdo->prepare('SELECT member_id FROM requesters WHERE id = :r');
        $req->execute(['r' => $f['requester_id']]);
        $reqMember = $req->fetchColumn();
        if (!empty($f['as_note'])) {
            add_message($pdo, $id, 'internal_note', $f['body'], ['author_member_id' => $actorMemberId, 'channel' => $f['channel'] === 'agent' ? 'agent' : 'web']);
        } elseif ($reqMember !== null && $reqMember !== false) {
            add_message($pdo, $id, 'public_reply', $f['body'], ['author_member_id' => (int) $reqMember, 'channel' => $f['channel'] === 'agent' ? 'agent' : 'web']);
        } else {
            add_message($pdo, $id, 'public_reply', $f['body'], ['author_requester_id' => $f['requester_id'], 'channel' => $f['channel'] === 'form' ? 'portal' : 'web']);
        }
    }
    $raw = (string) $pdo->query('SELECT hd_secure_link_mint(' . $id . ')')->fetchColumn();
    $assignee = null;
    $assigneeKind = null;
    if (!empty($f['assign'])) {                                                      // slice 8: the queue's assignment method (manual, default, round-robin, least-loaded)
        $assignee = assign_on_arrival($pdo, $id, 'create');
        if ($assignee !== null) {
            $assigneeKind = (string) $pdo->query('SELECT member_kind FROM members WHERE id = ' . (int) $assignee)->fetchColumn();
        }
    }
    return ['id' => $id, 'number' => (string) $row['number'], 'raw_link' => $raw, 'assignee_member_id' => $assignee, 'assignee_kind' => $assigneeKind];
}

/**
 * One entry in the conversation. $opts: author_member_id | author_requester_id, channel, mentions[], attachment_ids[] (re-parented to this message), email_message_id, in_reply_to.
 * The trigger (db/010) moves the ticket's dates and clocks. Answers the message id.
 */
function add_message(PDO $pdo, int $ticketId, string $kind, string $body, array $opts): int
{
    $st = $pdo->prepare('INSERT INTO messages (ticket_id, kind, author_member_id, author_requester_id, body_text, body_html, channel, email_message_id, in_reply_to, mentions)
                         VALUES (:t, :k, :am, :ar, :b, :h, :c, :em, :ir, CAST(:m AS bigint[])) RETURNING id');
    $st->execute(['t' => $ticketId, 'k' => $kind, 'am' => $opts['author_member_id'] ?? null, 'ar' => $opts['author_requester_id'] ?? null, 'b' => $body, 'h' => body_to_html($body), 'c' => $opts['channel'] ?? 'web',
                  'em' => $opts['email_message_id'] ?? null, 'ir' => $opts['in_reply_to'] ?? null, 'm' => '{' . implode(',', array_map('intval', $opts['mentions'] ?? [])) . '}']);
    $id = (int) $st->fetchColumn();
    $ids = array_values(array_filter(array_map('intval', $opts['attachment_ids'] ?? [])));
    if ($ids !== []) {
        $pdo->prepare('UPDATE attachments SET message_id = :m WHERE ticket_id = :t AND message_id IS NULL AND id = ANY (CAST(:ids AS bigint[]))')->execute(['m' => $id, 't' => $ticketId, 'ids' => '{' . implode(',', $ids) . '}']);
    }
    return $id;
}

/** Assign (a person, an agent, nobody) and/or move to a queue. The assignee must be in the (new) queue. Answers before/after with names. */
function assign_ticket(PDO $pdo, int $ticketId, ?int $assigneeMemberId, ?int $queueId, int $by, string $via = 'manual', bool $assigneeGiven = true): array
{
    $t = ticket_facts($pdo, $ticketId) ?? throw new DomainException('Not found.');
    $newQueue = $queueId ?? $t['queue_id'];
    $newAssignee = $assigneeGiven ? $assigneeMemberId : $t['assignee_member_id'];
    $name = static function (?int $m) use ($pdo): array {
        if ($m === null) {
            return [null, null];
        }
        $st = $pdo->prepare("SELECT display_name, member_kind FROM members WHERE id = :m AND status = 'active' AND capability IS NOT NULL");
        $st->execute(['m' => $m]);
        $r = $st->fetch();
        return $r === false ? [null, null] : [$r['display_name'], $r['member_kind']];
    };
    $qn = static function (int $q) use ($pdo): ?string {
        $st = $pdo->prepare('SELECT name FROM queues WHERE id = :q AND archived_at IS NULL');
        $st->execute(['q' => $q]);
        $n = $st->fetchColumn();
        return $n === false ? null : (string) $n;
    };
    $newQueueName = $qn($newQueue) ?? throw new DomainException('That queue is not here.');
    if ($newAssignee !== null) {
        [$an, $ak] = $name($newAssignee);
        if ($an === null) {
            throw new DomainException('That member is not active here.');
        }
        if (!member_in_queue($pdo, $newAssignee, $newQueue)) {
            throw new DomainException($an . ' is not in the ' . $newQueueName . ' queue.');
        }
    }
    $pdo->prepare('UPDATE tickets SET assignee_member_id = :a, queue_id = :q WHERE id = :id')->execute(['a' => $newAssignee, 'q' => $newQueue, 'id' => $ticketId]);
    [$bn, $bk] = $name($t['assignee_member_id']);
    [$an, $ak] = $name($newAssignee);
    $before = ['assignee_member_id' => $t['assignee_member_id'], 'assignee_name' => $bn, 'assignee_kind' => $bk, 'queue_id' => $t['queue_id'], 'queue_name' => $qn($t['queue_id'])];
    $after = ['assignee_member_id' => $newAssignee, 'assignee_name' => $an, 'assignee_kind' => $ak, 'queue_id' => $newQueue, 'queue_name' => $newQueueName, 'via' => $via];
    return ['before' => $before, 'after' => $after, 'changed' => $before['assignee_member_id'] !== $after['assignee_member_id'] || $before['queue_id'] !== $after['queue_id']];
}

/** Change fields: priority, type_id, category_id, due_at, subject, requester_id, organization_id, external_id, custom (merged by key). Answers the changed before/after. */
function set_ticket_fields(PDO $pdo, int $ticketId, array $fields, int $by): array
{
    $t = ticket_facts($pdo, $ticketId) ?? throw new DomainException('Not found.');
    $before = [];
    $after = [];
    $set = [];
    foreach (['priority', 'type_id', 'category_id', 'due_at', 'subject', 'requester_id', 'organization_id', 'external_id'] as $k) {
        if (!array_key_exists($k, $fields)) {
            continue;
        }
        $old = $t[$k];
        $new = $fields[$k];
        if ($k === 'due_at') {
            $old = $old === null ? null : json_ts((string) $old);
            $new = $new === null ? null : json_ts((string) $new);
        }
        if ($old !== $new) {
            $before[$k] = $old;
            $after[$k] = $new;
            $set[$k] = $fields[$k];
        }
    }
    if (array_key_exists('custom', $fields) && is_array($fields['custom'])) {
        $cv = $t['custom_values'];
        foreach ($fields['custom'] as $key => $value) {
            if (($cv[$key] ?? null) !== $value) {
                $before['custom.' . $key] = $cv[$key] ?? null;
                $after['custom.' . $key] = $value;
                if ($value === null) {
                    unset($cv[$key]);
                } else {
                    $cv[$key] = $value;
                }
            }
        }
        if (array_filter(array_keys($after), static fn (string $k): bool => str_starts_with($k, 'custom.')) !== []) {
            $set['custom_values'] = json_encode((object) $cv);
        }
    }
    if ($set === []) {
        return ['before' => [], 'after' => []];
    }
    $sql = 'UPDATE tickets SET ' . implode(', ', array_map(static fn (string $k): string => $k === 'custom_values' ? 'custom_values = CAST(:custom_values AS jsonb)' : "$k = :$k", array_keys($set))) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($set + ['id' => $ticketId]);
    return ['before' => $before, 'after' => $after];
}

/** Add and remove tags (by slug or label; a new slug makes the tag). Answers the ticket's tags after. */
function set_ticket_tags(PDO $pdo, int $ticketId, array $add, array $remove): array
{
    foreach ($add as $s) {
        $gid = tag_by_slug_or_create($pdo, (string) $s);
        $pdo->prepare('INSERT INTO ticket_tags (ticket_id, tag_id) VALUES (:t, :g) ON CONFLICT DO NOTHING')->execute(['t' => $ticketId, 'g' => $gid]);
    }
    foreach ($remove as $s) {
        $pdo->prepare('DELETE FROM ticket_tags WHERE ticket_id = :t AND tag_id = (SELECT id FROM tags WHERE slug = :s)')->execute(['t' => $ticketId, 's' => slugify_tag((string) $s)]);
    }
    $st = $pdo->prepare('SELECT g.slug FROM ticket_tags tt JOIN tags g ON g.id = tt.tag_id WHERE tt.ticket_id = :t ORDER BY g.slug');
    $st->execute(['t' => $ticketId]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

function add_follower(PDO $pdo, int $ticketId, string $kind, ?int $memberId, ?int $requesterId, ?int $by): void
{
    $pdo->prepare('INSERT INTO ticket_followers (ticket_id, kind, member_id, requester_id, added_by) VALUES (:t, :k, :m, :r, :by) ON CONFLICT DO NOTHING')
        ->execute(['t' => $ticketId, 'k' => $kind, 'm' => $memberId, 'r' => $requesterId, 'by' => $by]);
}

function remove_follower(PDO $pdo, int $ticketId, string $kind, ?int $memberId, ?int $requesterId): bool
{
    $st = $pdo->prepare('DELETE FROM ticket_followers WHERE ticket_id = :t AND kind = :k AND (member_id = :m OR requester_id = :r)');
    $st->execute(['t' => $ticketId, 'k' => $kind, 'm' => $memberId, 'r' => $requesterId]);
    return $st->rowCount() > 0;
}

function link_tickets(PDO $pdo, int $from, int $to, string $kind, ?int $by): int
{
    if ($from === $to) {
        throw new DomainException('A ticket cannot link to itself.');
    }
    if (!isset(LINK_KINDS[$kind]) || $kind === 'merged_into') {
        throw new DomainException('The link is parent_child, related, duplicate_of, blocks or follow_up_of.');
    }
    $st = $pdo->prepare('INSERT INTO ticket_links (from_ticket_id, to_ticket_id, kind, created_by) VALUES (:f, :t, :k, :by) ON CONFLICT (from_ticket_id, to_ticket_id, kind) DO UPDATE SET kind = EXCLUDED.kind RETURNING id');
    $st->execute(['f' => $from, 't' => $to, 'k' => $kind, 'by' => $by]);
    if ($kind === 'parent_child') {
        $pdo->prepare('UPDATE tickets SET parent_id = :p WHERE id = :c')->execute(['p' => $from, 'c' => $to]);
    }
    return (int) $st->fetchColumn();
}

function unlink_tickets(PDO $pdo, int $from, int $to, string $kind): bool
{
    $st = $pdo->prepare('DELETE FROM ticket_links WHERE kind = :k AND ((from_ticket_id = :f AND to_ticket_id = :t) OR (from_ticket_id = :t2 AND to_ticket_id = :f2))');
    $st->execute(['k' => $kind, 'f' => $from, 't' => $to, 't2' => $to, 'f2' => $from]);
    if ($kind === 'parent_child') {
        $pdo->prepare('UPDATE tickets SET parent_id = NULL WHERE id IN (:a, :b) AND parent_id IN (:c, :d)')->execute(['a' => $from, 'b' => $to, 'c' => $from, 'd' => $to]);
    }
    return $st->rowCount() > 0;
}

/** Merge the source into the target: messages, attachments, tags, followers and time move; the source is solved `duplicate`, linked and marked. Answers the counts. */
function merge_tickets(PDO $pdo, int $sourceId, int $targetId, int $by, ?string $note): array
{
    if ($sourceId === $targetId) {
        throw new DomainException('Choose two different tickets.');
    }
    $s = ticket_facts($pdo, $sourceId) ?? throw new DomainException('Not found.');
    $t = ticket_facts($pdo, $targetId) ?? throw new DomainException('Not found.');
    if ($t['status_category'] === 'closed') {
        throw new DomainException($t['number'] . ' is closed — merge into an open ticket.');
    }
    if ($s['merged_into_id'] !== null) {
        throw new DomainException($s['number'] . ' was merged already.');
    }
    $moved = [];
    $m = $pdo->prepare('UPDATE messages SET ticket_id = :t WHERE ticket_id = :s');
    $m->execute(['t' => $targetId, 's' => $sourceId]);
    $moved['messages'] = $m->rowCount();
    $a = $pdo->prepare('UPDATE attachments SET ticket_id = :t WHERE ticket_id = :s');
    $a->execute(['t' => $targetId, 's' => $sourceId]);
    $moved['attachments'] = $a->rowCount();
    $pdo->prepare('INSERT INTO ticket_tags (ticket_id, tag_id) SELECT :t, tag_id FROM ticket_tags WHERE ticket_id = :s ON CONFLICT DO NOTHING')->execute(['t' => $targetId, 's' => $sourceId]);
    $pdo->prepare('INSERT INTO ticket_followers (ticket_id, kind, member_id, requester_id, added_by) SELECT :t, kind, member_id, requester_id, added_by FROM ticket_followers WHERE ticket_id = :s ON CONFLICT DO NOTHING')->execute(['t' => $targetId, 's' => $sourceId]);
    if ($s['requester_id'] !== $t['requester_id']) {
        $pdo->prepare('INSERT INTO ticket_followers (ticket_id, kind, member_id, requester_id, added_by) VALUES (:t, \'cc\', :m, :r, :by) ON CONFLICT DO NOTHING')
            ->execute(['t' => $targetId, 'm' => $s['requester_member_id'], 'r' => $s['requester_member_id'] === null ? $s['requester_id'] : null, 'by' => $by]);
    }
    $e = $pdo->prepare('UPDATE time_entries SET ticket_id = :t WHERE ticket_id = :s');
    $e->execute(['t' => $targetId, 's' => $sourceId]);
    $moved['time'] = $e->rowCount();
    $pdo->prepare('INSERT INTO ticket_links (from_ticket_id, to_ticket_id, kind, created_by) VALUES (:s, :t, \'merged_into\', :by) ON CONFLICT DO NOTHING')->execute(['s' => $sourceId, 't' => $targetId, 'by' => $by]);
    $solved = status_by_key($pdo, 'solved') ?? throw new DomainException('No solved status.');
    $pdo->prepare('UPDATE tickets SET merged_into_id = :t, status_id = :st, resolution_code = \'duplicate\' WHERE id = :s')->execute(['t' => $targetId, 'st' => $solved['status_id'], 's' => $sourceId]);
    add_message($pdo, $targetId, 'internal_note', 'Merged ' . $s['number'] . ' into this ticket.' . ($note !== null && $note !== '' ? "\n\n" . $note : ''), ['author_member_id' => $by, 'channel' => 'web']);
    return $moved + ['source_number' => $s['number'], 'target_number' => $t['number']];
}

function log_time(PDO $pdo, int $ticketId, int $memberId, int $minutes, bool $billable, ?string $note, string $workedOn): int
{
    $st = $pdo->prepare('INSERT INTO time_entries (ticket_id, member_id, minutes, billable, note, worked_on) VALUES (:t, :m, :mi, :b, :n, :d) RETURNING id');
    $st->execute(['t' => $ticketId, 'm' => $memberId, 'mi' => $minutes, 'b' => $billable ? 't' : 'f', 'n' => $note, 'd' => $workedOn]);
    return (int) $st->fetchColumn();
}

function delete_time_entry(PDO $pdo, int $entryId, int $by): void
{
    $st = $pdo->prepare('DELETE FROM time_entries WHERE id = :id');
    $st->execute(['id' => $entryId]);
    if ($st->rowCount() !== 1) {
        throw new DomainException('Not found.');
    }
}

/** Store an uploaded file for a ticket (and a message when given). $file: a $_FILES entry. Answers the attachment id. */
function store_attachment(PDO $pdo, int $ticketId, ?int $messageId, array $file, ?int $byMember, ?int $byRequester = null): int
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new DomainException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? 'That file is too large.' : 'The upload did not arrive.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size > attachment_max_bytes()) {
        $max = attachment_max_bytes();
        throw new DomainException('A file is at most ' . ($max >= 1048576 ? intdiv($max, 1048576) . ' MB' : intdiv($max, 1024) . ' KB') . '.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('The upload did not arrive.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
    $name = preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'file';
    if (!in_array($mime, ATTACHMENT_MIME_ALLOW, true) || preg_match('/\.(exe|bat|cmd|com|msi|scr|ps1|sh|js|vbs|jar|dll)$/i', $name)) {
        throw new DomainException('That kind of file is not accepted (images, PDFs, text, office documents and zip are).');
    }
    $sha = hash_file('sha256', $tmp);
    $st = $pdo->prepare('INSERT INTO attachments (ticket_id, message_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by_member_id, uploaded_by_requester_id) VALUES (:t, :m, :n, :mt, :s, :h, :p, :bm, :br) RETURNING id');
    $st->execute(['t' => $ticketId, 'm' => $messageId, 'n' => $name, 'mt' => $mime, 's' => $size, 'h' => $sha, 'p' => 'pending', 'bm' => $byMember, 'br' => $byRequester]);
    $id = (int) $st->fetchColumn();
    $dir = APP_ROOT . '/storage/attachments/' . $ticketId;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new DomainException('The attachment store is not writable.');
    }
    $path = 'attachments/' . $ticketId . '/' . $id . '-' . substr($sha, 0, 12);
    $full = APP_ROOT . '/storage/' . $path;
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $full) : rename($tmp, $full))) {
        throw new DomainException('The file could not be stored.');
    }
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $path, 'id' => $id]);
    return $id;
}

/** The file of an attachment the caller may see (the view decides): [path, filename, mime, size, message_id, ticket_id] or null. */
function attachment_path(PDO $pdo, int $attachmentId): ?array
{
    $st = $pdo->prepare('SELECT a.attachment_id, a.ticket_id, a.message_id, a.filename, a.mime_type, a.byte_size, b.storage_path FROM mcp_attachments a JOIN attachments b ON b.id = a.attachment_id WHERE a.attachment_id = :id');
    $st->execute(['id' => $attachmentId]);
    $r = $st->fetch();
    return $r === false ? null : ['path' => APP_ROOT . '/storage/' . $r['storage_path'], 'filename' => $r['filename'], 'mime' => $r['mime_type'], 'size' => (int) $r['byte_size'], 'message_id' => $r['message_id'] === null ? null : (int) $r['message_id'], 'ticket_id' => (int) $r['ticket_id']];
}

function delete_attachment(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT ticket_id, filename, storage_path, byte_size, mime_type FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $r = $st->fetch();
    if ($r === false) {
        throw new DomainException('Not found.');
    }
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    $full = APP_ROOT . '/storage/' . $r['storage_path'];
    if (is_file($full)) {
        @unlink($full);
    }
    return ['ticket_id' => (int) $r['ticket_id'], 'filename' => $r['filename']];
}

/** Remove a ticket's files from storage (before the row goes). */
function remove_ticket_files(int $ticketId): void
{
    $dir = APP_ROOT . '/storage/attachments/' . $ticketId;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}

/** A new secure link (the old one dies). The raw token is answered ONCE, for the outbox. */
function rotate_secure_link(PDO $pdo, int $ticketId): string
{
    return (string) $pdo->query('SELECT hd_secure_link_mint(' . $ticketId . ')')->fetchColumn();
}

function touch_viewer(PDO $pdo, int $ticketId, int $memberId, bool $composing): void
{
    $pdo->prepare('INSERT INTO ticket_viewers (ticket_id, member_id, composing, last_seen_at) VALUES (:t, :m, :c, now()) ON CONFLICT (ticket_id, member_id) DO UPDATE SET composing = EXCLUDED.composing, last_seen_at = now()')
        ->execute(['t' => $ticketId, 'm' => $memberId, 'c' => $composing ? 't' : 'f']);
    $pdo->exec("DELETE FROM ticket_viewers WHERE last_seen_at < now() - interval '10 minutes'");
}

/**
 * Queue a notice: outbox rows per channel the recipient's prefs allow (an external: email only), and the in-app row for a member. $recipient:
 * ['member_id' => …] or ['requester_id' => …]. Never a note's text, never another requester's address. Answers how many rows were queued.
 */
function notify(PDO $pdo, array $recipient, string $kind, int $ticketId, string $subject, string $body, ?int $messageId = null, ?string $dedupe = null): int
{
    if (!empty($recipient['requester_id'])) {                            // slice 7: the ONE queueing function (app/features/notify/queue.php)
        return notify_requester($pdo, (int) $recipient['requester_id'], $kind, $ticketId, $subject, $body, $dedupe, $messageId);
    }
    return notify_member($pdo, (int) ($recipient['member_id'] ?? 0), $kind, $ticketId, $subject, $body, $dedupe, $messageId);
}

/** The notice words of a ticket for a requester: the secure link for an external, the page for a member. */
function requester_link_words(array $t, ?string $rawToken): string
{
    if ($t['requester_member_id'] === null) {
        return $rawToken !== null ? 'See it here: ' . app_url('/t/' . $rawToken) : 'Reply to this email to add to it.';
    }
    return 'See it here: ' . app_url('/tickets/' . $t['ticket_id']);
}

/** The queue's leads (named), for a requester's reply with no assignee. */
function queue_lead_ids(PDO $pdo, int $queueId): array
{
    $st = $pdo->prepare('SELECT member_id FROM queue_members WHERE queue_id = :q AND is_lead');
    $st->execute(['q' => $queueId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
