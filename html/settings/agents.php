<?php
declare(strict_types=1);
/** /settings/agents — which OS agents work here (screen `agents`, read-only; settings.manage): their queues and their last dispatch. Hiring and grants are the kernel's. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_right('settings.manage');
require_human();
$pdo = db();
$agents = find_agents_here($pdo);
log_screen_view($pdo, 'agents');
if (wants_json()) {
    respond_screen(['agents' => array_map('present_agent_here', $agents)]);
}
render_screen('Agents', view('settings/agents.php', ['agents' => $agents, 'osUrl' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/launcher', 'tz' => member_timezone()]),
    ['activeNav' => 'agents', 'screen' => 'agents', 'entity' => 'member']);
