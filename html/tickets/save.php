<?php
declare(strict_types=1);
/**
 * Actions `ticket_create` / `ticket_update` (by the presence of `ticket`). Create: tickets.create; for someone else (on_behalf_of, requester) needs tickets.view_queue or tickets.work;
 * an agent's external_id is idempotent (200 with the existing ticket); the category routes the queue, type and priority; the custom values go through slice 2's validator;
 * the secure link is minted and the `created` notice queued; the queue's default assignee is applied (an agent is dispatched after commit). Log `ticket.create`.
 * Update: work; subject, requester, organization, type, category, due_at, custom, external_id; log `ticket.update` (changed fields).
 */
require_once dirname(__DIR__, 2) . '/app/features/tickets/handler.php';
hd_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$meRow = current_member();
$id = request_integer('ticket') ?? request_integer('ticket_id');
if ($id !== null) {
    $t = ticket_load($pdo, $id);
    require_work($t);
    $errors = [];
    $fields = [];
    if (req_has('subject')) {
        $fields['subject'] = (string) req_val('subject');
        if ($fields['subject'] === '' || mb_strlen($fields['subject']) > 300) {
            $errors['subject'] = 'A subject is one line, up to 300 characters.';
        }
    }
    if (req_has('requester')) {
        $v = (string) req_val('requester');
        if ($v !== '') {
            $rid = filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : null;
            if ($rid === null && str_contains($v, '@')) {
                $rid = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e)', ['e' => $v]);
            }
            if ($rid === null || find_requester($pdo, $rid) === null) {
                $errors['requester'] = 'Choose the requester from the list, or give an email.';
            } else {
                $fields['requester_id'] = $rid;
            }
        }
    }
    $fields['organization_id'] = hd_ref($pdo, req_has('organization') ? 'organization' : 'organization_id', $t['organization_id'], 'SELECT 1 FROM organizations WHERE id = :id AND active', 'organization', $errors);
    $fields['type_id'] = hd_ref($pdo, req_has('type') ? 'type' : 'type_id', $t['type_id'], 'SELECT 1 FROM ticket_types WHERE id = :id AND archived_at IS NULL', 'type', $errors);
    $fields['category_id'] = hd_ref($pdo, req_has('category') ? 'category' : 'category_id', $t['category_id'], 'SELECT 1 FROM categories WHERE id = :id AND archived_at IS NULL', 'category', $errors);
    if (req_has('due_at')) {
        $fields['due_at'] = request_when('due_at', $errors, 'The due date');
    }
    if (req_has('external_id')) {
        $fields['external_id'] = (string) req_val('external_id') === '' ? null : mb_substr((string) req_val('external_id'), 0, 200);
    }
    $custom = request_custom();
    if ($custom !== null) {
        $v = custom_values_validate($pdo, 'ticket', $custom, $fields['type_id']);
        if ($v['errors'] !== []) {
            hd_refuse_fields($v['errors']);
        }
        $fields['custom'] = $v['values'];
    }
    if ($errors !== []) {
        hd_refuse_fields($errors);
    }
    $d = hd_guard($pdo, static function () use ($pdo, $me, $t, $fields): array {
        $pdo->beginTransaction();
        $d = set_ticket_fields($pdo, $t['ticket_id'], $fields, $me);
        if ($d['after'] !== []) {
            ticket_log($pdo, $t, 'ticket.update', ['before' => $d['before'], 'after' => $d['after']]);
        }
        $pdo->commit();
        return $d;
    });
    ticket_done($t, $d['after'] === [] ? 'Nothing changed on ' . $t['number'] : 'Saved ' . $t['number'], ['changed' => array_keys($d['after'])], 'saved');
}

// ---- create -------------------------------------------------------------------------------------------------------
require_right('tickets.create');
$isAgent = ($meRow['member_kind'] ?? '') === 'agent';
$canOthers = has_right('tickets.view_queue') || has_right('tickets.work');
$errors = [];
$subject = (string) req_val('subject');
if ($subject === '' || mb_strlen($subject) > 300) {
    $errors['subject'] = 'A subject is one line, up to 300 characters.';
}
$body = (string) req_val('body');
if ($body === '' || mb_strlen($body) > 20000) {
    $errors['body'] = 'Say what is going on (up to 20,000 characters).';
}
$externalId = (string) req_val('external_id') === '' ? null : mb_substr((string) req_val('external_id'), 0, 200);
if ($externalId !== null && ($existing = find_ticket_by_external_id($pdo, $externalId)) !== null) {
    $ex = ticket_facts($pdo, $existing);
    emit_action_status(true, ['did' => $ex['number'] . ' already exists for that external id', 'record_id' => $existing, 'ticket_id' => $existing, 'number' => $ex['number']]);
    if (wants_json()) {
        respond_saved(['did' => $ex['number'] . ' already exists for that external id', 'record_id' => $existing, 'ticket_id' => $existing, 'number' => $ex['number'], 'location' => '/tickets/' . $existing]);
    }
    saved_go('/tickets/' . $existing);
}
// who is it for
$for = (string) req_val('for');
$onBehalfOf = request_integer('on_behalf_of');
$reqParam = (string) req_val('requester');
$custName = (string) req_val('requester_name');
$custEmail = strtolower((string) req_val('requester_email'));
if ($for === 'member' && $onBehalfOf === null) {
    $errors['on_behalf_of'] = 'Choose the person it is for.';
}
if ($for === 'customer') {
    if ($reqParam === '' && $custEmail === '') {
        $errors['requester_email'] = 'Give the customer\'s email.';
    }
}
$requesterId = null;
$submitter = null;
if ($for !== 'member' && $for !== 'customer' && $onBehalfOf === null && $reqParam === '' && $custEmail === '') {
    $requesterId = (int) one_value($pdo, 'SELECT hd_requester_for_member(:m)', ['m' => $me]);     // me (an agent under a run token: the agent)
} else {
    if (!$canOthers) {
        refuse(422, 'You can file a ticket for yourself here; support files on behalf of others.');
    }
    if ($onBehalfOf !== null) {
        $m = find_member_by_id($pdo, $onBehalfOf);
        if ($m === null || $m['status'] !== 'active') {
            $errors['on_behalf_of'] = 'That person is not here.';
        } else {
            $requesterId = (int) one_value($pdo, 'SELECT hd_requester_for_member(:m)', ['m' => $onBehalfOf]);
        }
    } elseif ($reqParam !== '' && filter_var($reqParam, FILTER_VALIDATE_INT) !== false) {
        $requesterId = find_requester($pdo, (int) $reqParam) !== null ? (int) $reqParam : null;
        if ($requesterId === null) {
            $errors['requester'] = 'That requester is not here.';
        }
    } else {
        $email = $reqParam !== '' && str_contains($reqParam, '@') ? strtolower($reqParam) : $custEmail;
        if (!preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            $errors['requester_email'] = 'That is not an email address.';
        } else {
            $requesterId = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e, :n)', ['e' => $email, 'n' => $custName === '' ? null : $custName]);
        }
    }
    $submitter = $me;
}
// routing
$category = hd_ref($pdo, 'category', null, 'SELECT 1 FROM categories WHERE id = :id AND archived_at IS NULL' . ($canOthers ? '' : ' AND requester_visible'), 'category', $errors);
$catRow = $category === null ? null : find_category($pdo, $category);
$queue = $canOthers ? hd_ref($pdo, 'queue', null, 'SELECT 1 FROM queues WHERE id = :id AND archived_at IS NULL', 'queue', $errors) : null;
$queue ??= $catRow['default_queue_id'] ?? null;
$queue ??= (int) $pdo->query('SELECT default_queue_id FROM app_settings WHERE id = 1')->fetchColumn();
$type = $canOthers ? hd_ref($pdo, 'type', null, 'SELECT 1 FROM ticket_types WHERE id = :id AND archived_at IS NULL', 'type', $errors) : null;
$type ??= $catRow['default_type_id'] ?? null;
$priority = (string) req_val('priority');
if ($priority === '') {
    $priority = $catRow['default_priority'] ?? 'normal';
}
if (!isset(PRIORITY_COLORS[$priority])) {
    $errors['priority'] = 'The priority is low, normal, high or urgent.';
}
$due = $canOthers ? request_when('due_at', $errors, 'The due date') : null;
$tags = $canOthers ? (request_list('tags') ?? []) : [];
$ccIds = [];
if ($canOthers) {
    foreach (request_list('cc') ?? [] as $cc) {
        if (filter_var($cc, FILTER_VALIDATE_INT) !== false && find_requester($pdo, (int) $cc) !== null) {
            $ccIds[] = (int) $cc;
        } elseif (preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $cc)) {
            $ccIds[] = (int) one_value($pdo, 'SELECT hd_requester_for_email(:e)', ['e' => strtolower($cc)]);
        } else {
            $errors['cc'] = $cc . ' is not an email address or a requester.';
        }
    }
}
$custom = request_custom();
$cv = [];
if ($custom !== null) {
    $v = custom_values_validate($pdo, 'ticket', $custom, $type);
    if ($v['errors'] !== []) {
        hd_refuse_fields($v['errors']);
    }
    $cv = array_filter($v['values'], static fn ($x): bool => $x !== null);
}
if ($errors !== []) {
    hd_refuse_fields($errors);
}
$f = ['subject' => $subject, 'body' => $body, 'as_note' => $canOthers && hd_yes('as_note', false), 'requester_id' => $requesterId, 'submitter_member_id' => $submitter, 'queue_id' => $queue, 'category_id' => $category,
      'type_id' => $type, 'priority' => $priority, 'tags' => $tags, 'custom' => $cv, 'due_at' => $due, 'cc_requester_ids' => array_values(array_unique($ccIds)), 'external_id' => $externalId,
      'channel' => $isAgent ? 'agent' : 'web', 'assign' => true];
$r = hd_guard($pdo, static function () use ($pdo, $me, $f, $onBehalfOf, $submitter): array {
    $pdo->beginTransaction();
    $r = create_ticket($pdo, $f, $me);
    $t = ticket_facts($pdo, $r['id']);
    ticket_log($pdo, $t, 'ticket.create', ['after' => ['number' => $r['number'], 'requester_id' => $f['requester_id'], 'queue_id' => $f['queue_id'], 'priority' => $f['priority'], 'channel' => $f['channel'],
        'on_behalf_of' => $onBehalfOf ?? ($submitter !== null ? $f['requester_id'] : null), 'category_id' => $f['category_id'], 'external_id' => $f['external_id']]]);
    notify($pdo, ['requester_id' => $f['requester_id']], 'created', $r['id'], $r['number'] . ': ' . $f['subject'], 'Your request ' . $r['number'] . ' "' . $f['subject'] . '" was opened. ' . requester_link_words($t, $r['raw_link']), null, 'created:' . $r['id']);
    if ($r['assignee_member_id'] !== null && $r['assignee_kind'] !== 'agent') {
        notify($pdo, ['member_id' => $r['assignee_member_id']], 'assigned', $r['id'], $r['number'] . ' is yours: ' . $f['subject'], $r['number'] . ' "' . $f['subject'] . '" was assigned to you. See it here: ' . app_url('/tickets/' . $r['id']), null, 'assigned:' . $r['id'] . ':' . $r['assignee_member_id']);
    }
    $pdo->commit();
    return $r;
});
if ($r['assignee_member_id'] !== null && $r['assignee_kind'] === 'agent') {
    $t = ticket_facts($pdo, $r['id']);
    $d = dispatch_to_agent($pdo, $r['id'], $r['assignee_member_id'], 'assigned', $isAgent ? null : $me);
    ticket_log($pdo, $t, 'ticket.dispatch', ['after' => ['agent_member_id' => $r['assignee_member_id'], 'via' => $d['via'], 'run_id' => $d['run_id'], 'status' => $d['status']]]);
}
hd_done('Filed ' . $r['number'] . ' — ' . $subject, $r['id'], '/tickets/' . $r['id'], 'ticketChanged', ['ticket_id' => $r['id'], 'number' => $r['number'], 'queue_id' => $f['queue_id']]);
