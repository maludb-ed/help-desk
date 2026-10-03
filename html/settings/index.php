<?php
declare(strict_types=1);
/**
 * /settings/?tab=notify — the person's own settings (screen `settings`; tickets.view_own): HOW I AM TOLD (email, text, which events, which by text,
 * a digest). Texts go to the phone verified in the operating system — this application never sees it; what the kernel last said about it is shown in words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/queries.php';
require_once dirname(__DIR__, 2) . '/app/features/settings/present.php';
require_login();
require_human();
require_right('tickets.view_own');
$pdo = db();
$me = (int) current_member_id();
$tab = 'notify';
$prefs = find_prefs($pdo, $me);
$refusal = last_text_refusal($pdo, $me);
log_screen_view($pdo, 'settings');
if (wants_json()) {
    respond_screen(['tab' => $tab, 'notify' => present_prefs($prefs) + ['text_note' => $refusal], 'kinds' => NOTICE_KINDS]);
}
$notices = ['prefs_saved' => ['success', 'Saved how you are told.']];
render_screen('My settings', view('settings/index.php', ['tab' => $tab, 'prefs' => $prefs, 'refusal' => $refusal,
    'osChannels' => rtrim((string) env('OS_LAUNCHER_URL', '/'), '/') . '/settings/channels', 'notice' => $notices[$_GET['notice'] ?? ''] ?? null]),
    ['activeNav' => 'my-settings', 'screen' => 'settings', 'entity' => 'settings']);
