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
