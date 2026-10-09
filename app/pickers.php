<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract). One entry per record kind.
 * `search` reads through the mcp views the screens' own selects read (a source can list nothing a screen's user could not
 * see in the select it replaced), `label` names one record, `right` is the gate of the screens that use the field (any one of
 * "a|b" admits — nav_has_right()), `params` are the only filter keys the endpoint forwards (integers; a field's `include`
 * selectors must name them). Rows: id, label, detail (one muted line), badge + color (the status-color vocabulary) — optional.
 */
$people_rows = static function (array $rows): array {
    $me = (int) current_member_id();
    return array_map(static function (array $m) use ($me): array {
        $agent = $m['member_kind'] === 'agent';
        return [
            'id' => (int) $m['member_id'], 'label' => $m['display_name'],
            'detail' => implode(' · ', array_filter([$m['job_title'] ?? '', (int) $m['member_id'] === $me ? 'you' : ''])),
            'badge' => $agent ? 'Agent' : null, 'color' => 'info',
        ];
    }, $rows);
};

return [
    // A person (not an agent): on whose behalf a ticket is filed. The ticket form shows the field to those who see queues or work tickets.
    'person' => [
        'title' => 'People', 'noun' => 'person', 'right' => 'tickets.view_queue|tickets.work', 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($people_rows): array {
            $r = find_assignable_members_page($pdo, $q, $page, ['humans' => true]);
            $r['rows'] = $people_rows($r['rows']);
            return $r;
        },
        'label' => static fn (PDO $pdo, int $id): ?string => find_member_name($pdo, $id),
    ],
    // Who can hold a ticket: a person or an agent; with `queue` (the ticket panel's queue field) only that queue's people.
    'assignee' => [
        'title' => 'Assignees', 'noun' => 'assignee', 'right' => 'tickets.work|tickets.manage', 'params' => ['queue'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($people_rows): array {
            $r = find_assignable_members_page($pdo, $q, $page, ['queue' => $params['queue'] ?? null]);
            $r['rows'] = $people_rows($r['rows']);
            return $r;
        },
        'label' => static fn (PDO $pdo, int $id): ?string => find_member_name($pdo, $id),
    ],
    // Anyone who may be named in a queue (a queue's default assignee, "Add a member"); `not_named_in` leaves out those already named.
    'member' => [
        'title' => 'People and agents', 'noun' => 'person or agent', 'right' => 'settings.manage|queues.members', 'params' => ['not_named_in'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page) use ($people_rows): array {
            $r = find_assignable_members_page($pdo, $q, $page, ['not_named_in' => $params['not_named_in'] ?? null]);
            $r['rows'] = $people_rows($r['rows']);
            return $r;
        },
        'label' => static fn (PDO $pdo, int $id): ?string => find_member_name($pdo, $id),
    ],
    // A company: a requester's organization (requester screens: tickets.view_queue; the saved-view screen: views.personal|views.shared). mcp_organizations narrows the rows to what the caller sees.
    'organization' => [
        'title' => 'Organizations', 'noun' => 'organization', 'right' => 'tickets.view_queue|views.personal|views.shared', 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/requesters/queries.php';
            $r = find_organizations_page($pdo, $q, $page);
            $r['rows'] = array_map(static fn (array $o): array => [
                'id' => (int) $o['organization_id'], 'label' => $o['name'],
                'detail' => implode(' · ', array_filter([$o['support_tier'] ?? '', implode(', ', array_slice($o['domains'], 0, 2))])),
            ], $r['rows']);
            return $r;
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            require_once __DIR__ . '/features/requesters/queries.php';
            return find_organization_name($pdo, $id);
        },
    ],
];
