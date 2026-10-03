<?php
declare(strict_types=1);

/**
 * The shell's menu — ONE table: the sidebar and the phone's tab bar read it (Phase 2 builds the shell), so a menu item and
 * the right that opens its screen can never disagree. An item shows only when the person holds its right (several joined
 * by "|": any one). Screens are the action manifest's (Phase 1); until a screen is built its item points at a page the
 * shell marks as coming.
 *   [id, url, icon, label, right]
 */
function nav_groups(): array
{
    return [
        'Help' => [
            ['home',          '/',              'feather-home',        'Home',             'tickets.view_own'],
            ['my-tickets',    '/my-tickets',    'feather-inbox',       'My tickets',       'tickets.view_own'],
            ['new-ticket',    '/tickets/new',   'feather-plus-circle', 'New request',      'tickets.create'],
            ['kb',            '/kb/',           'feather-book-open',   'Knowledge base',   'kb.read'],
        ],
        'Work' => [
            ['inbox',         '/inbox',         'feather-layers',      'Inbox',            'tickets.view_queue'],
            ['board',         '/board',         'feather-trello',      'Board',            'tickets.view_queue'],
            ['requesters',    '/requesters/',   'feather-users',       'Requesters',       'tickets.view_queue'],
            ['organizations', '/organizations/','feather-briefcase',   'Organizations',    'tickets.view_queue'],
            ['canned',        '/canned/',       'feather-message-square', 'Canned responses', 'tickets.work'],
            ['reports',       '/reports/',      'feather-bar-chart-2', 'Reports',          'reports.view'],
        ],
        'Settings' => [
            ['queues',        '/settings/queues/',     'feather-git-branch',  'Queues',          'settings.manage|queues.members'],
            ['statuses',      '/settings/statuses/',   'feather-flag',        'Statuses',        'settings.manage'],
            ['vocabulary',    '/settings/vocabulary/', 'feather-tag',         'Types, categories, tags', 'settings.manage'],
            ['fields',        '/settings/fields/',     'feather-list',        'Custom fields',   'settings.manage'],
            ['sla',           '/settings/sla/',        'feather-clock',       'SLA and hours',   'settings.manage'],
            ['automation',    '/settings/automation/', 'feather-zap',         'Automation',      'settings.manage'],
            ['mailboxes',     '/settings/mailboxes/',  'feather-mail',        'Mailboxes',       'settings.manage'],
            ['csat',          '/settings/csat',        'feather-smile',       'Satisfaction',    'settings.manage'],
            ['agents',        '/settings/agents',      'feather-cpu',         'Agents',          'settings.manage'],
            ['my-settings',   '/settings/',            'feather-settings',    'My settings',     'tickets.view_own'],
        ],
    ];
}

/** The phone's tabs: Home, My tickets, New request, Knowledge base — and More (the sidebar) which the layout adds. */
function nav_tabs(): array
{
    return [
        ['home',       '/',            'feather-home',        'Home',      'tickets.view_own'],
        ['my-tickets', '/my-tickets',  'feather-inbox',       'My tickets', 'tickets.view_own'],
        ['new-ticket', '/tickets/new', 'feather-plus-circle', 'New',       'tickets.create'],
        ['kb',         '/kb/',         'feather-book-open',   'Knowledge', 'kb.read'],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item (Queues opens to queues.members as well). */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest Help Desk role the member holds, in words — the header's badge. */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $st = db()->prepare('SELECT roles FROM members WHERE id = :id');
    $st->execute(['id' => (int) $m['id']]);
    $held = pg_text_array((string) ($st->fetchColumn() ?: '{}'));
    if ($held === []) {
        return ($m['capability'] ?? '') === 'admin' ? 'Help Desk admin' : 'Requester';
    }
    foreach (['admin' => 'Help Desk admin', 'lead' => 'Lead', 'support' => 'Support staff', 'collaborator' => 'Collaborator', 'user' => 'Requester'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return 'Requester';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** A path a page may send a person back to: local, no scheme, no protocol-relative — else null. */
function safe_local_path(?string $path): ?string
{
    if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
        return null;
    }
    return $path;
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/my-tickets' => 'My tickets', '/inbox' => 'Inbox', '/board' => 'Board', '/requesters/' => 'Requesters',
               '/organizations/' => 'Organizations', '/canned/' => 'Canned responses', '/reports/' => 'Reports', '/kb/' => 'Knowledge base',
               '/notifications' => 'Notifications', '/activity' => 'Activity', '/settings/' => 'My settings', '/settings/tokens/' => 'Tokens',
               '/settings/general' => 'Help Desk settings', '/settings/agents' => 'Agents', '/settings/sla/' => 'SLA and hours', '/settings/queues/' => 'Queues',
               '/settings/statuses/' => 'Statuses', '/settings/statuses/transitions' => 'Transitions', '/settings/vocabulary/' => 'Types, categories, tags', '/settings/fields/' => 'Custom fields'];
    foreach ($labels as $p => $label) {
        if ($path === $p) {
            return [$back, $label];
        }
    }
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    foreach (['tickets' => 'the ticket', 'settings/queues' => 'the queue', 'organizations' => 'the organization', 'requesters' => 'the requester'] as $dir => $label) {
        if (preg_match('#^/' . $dir . '/\d+$#', $path)) {
            return [$back, $label];
        }
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which
 * slice builds it — 200 after the right has been checked. bin/build_action_registry.php reads a controller that calls this
 * as unbuilt, so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        // An action that shares this file with its screen (csat_settings_save, settings_save): not built either.
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}
