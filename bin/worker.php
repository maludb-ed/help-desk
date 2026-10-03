#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * The Help Desk worker (slice 8) — ONE pass of nine steps, every minute from helpdesk-worker.timer: `php bin/worker.php [--only=send,mail,sla,snooze,auto_close,rules,survey,dispatch,housekeeping] [--limit=200]`.
 * Advisory-locked (a second pass while one runs prints {"skipped":"locked"}); each step its own try (an error is logged and the pass goes on); the report printed as one JSON line and
 * logged once as worker.pass with the counts, never a body. The clock: HD_WORKER_NOW (ISO 8601) replaces now() in the steps' selections outside production; the writes stamp the real time.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/app/features/tickets/handler.php';
require_once dirname(__DIR__) . '/app/features/automation/worker_steps.php';
$opts = getopt('', ['only::', 'limit::']);
$only = isset($opts['only']) && $opts['only'] !== false && $opts['only'] !== '' ? array_values(array_filter(array_map('trim', explode(',', (string) $opts['only'])))) : null;
$limit = isset($opts['limit']) && (int) $opts['limit'] > 0 ? (int) $opts['limit'] : 200;
$now = !app_is_prod() && (string) env('HD_WORKER_NOW', '') !== '' ? (new DateTimeImmutable((string) env('HD_WORKER_NOW')))->format('c') : date('c');
$GLOBALS['__public_door'] = 'cron';
$pdo = db();
if (!$pdo->query("SELECT pg_try_advisory_lock(hashtext('helpdesk_worker'))")->fetchColumn()) {
    echo json_encode(['skipped' => 'locked', 'at' => date('c')]) . "\n";
    exit(0);
}
$report = ['at' => date('c'), 'now' => $now];
$step = static function (string $name, callable $fn) use (&$report, $only): void {
    if ($only !== null && !in_array($name, $only, true)) {
        return;
    }
    try {
        $report[$name] = $fn();
    } catch (Throwable $e) {
        error_log('worker ' . $name . ': ' . $e->getMessage());
        $report[$name] = ['error' => mb_substr($e->getMessage(), 0, 200)];
    }
};
$step('send', static fn (): array => outbox_send_batch($pdo, $limit));
$step('mail', static fn (): int => mail_poll_due_mailboxes($pdo));
$step('sla', static fn (): array => ['breached' => sla_breach_pass($pdo, $now), 'warned' => sla_warning_pass($pdo, $now)]);
$step('snooze', static fn (): int => snooze_pass($pdo, $now));
$step('auto_close', static fn (): int => auto_close_pass($pdo, $now));
$step('rules', static fn (): array => rules_pass($pdo, $now));
$step('survey', static fn (): int => survey_pass($pdo, $now));
$step('dispatch', static fn (): array => dispatch_retry_pass($pdo, $now));
$step('housekeeping', static fn (): array => housekeeping_pass($pdo, $now));
$pdo->query("SELECT pg_advisory_unlock(hashtext('helpdesk_worker'))");
log_activity($pdo, 'worker.pass', null, null, ['source' => 'cron', 'after' => $report]);
echo json_encode($report, JSON_UNESCAPED_SLASHES) . "\n";
