<?php
declare(strict_types=1);
/** /my-tickets — the tickets the person asked for, filed, is cc'd on or follows, by state (screen `my-tickets`; params: state). The empty shape until slice 3. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/home/queries.php';
require_login();
require_human();
require_right('tickets.view_own');
$pdo = db();
$state = in_array(request_string('state'), ['open', 'waiting', 'solved', 'all'], true) ? request_string('state') : 'open';
log_screen_view($pdo, 'my-tickets');
if (wants_json()) {
    respond_screen(['state' => $state, 'tickets' => [], 'can_create' => has_right('tickets.create')]);
}
render_screen('My tickets', view('my-tickets/page.php', ['state' => $state, 'canCreate' => has_right('tickets.create')]), ['activeNav' => 'my-tickets', 'screen' => 'my-tickets']);
