<?php
declare(strict_types=1);
/** Placeholder (Phase 2): the screen is built by slice 1 (queues and people) — this file is replaced then. The registry builder reads render_nav_stub() as unbuilt. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
render_nav_stub('queues', 'slice 1 (queues and people)', 'settings.manage|queues.members');
