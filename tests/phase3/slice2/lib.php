<?php
/** Helpers for the slice 2 proofs (docs/build-specs/vocabulary.md, "Proof") — slice 1's library plus a records_ro connection for the view checks. */
require dirname(__DIR__) . '/slice1/lib.php';
pdo()->exec("SELECT set_config('app.member_id', '1', false)");   // the proofs read mcp_* views as the owner

/** A connection as the records read role (helpdesk_records_ro), acting as a member. */
function ro_pdo(int $member): PDO
{
    $p = new PDO(sprintf('pgsql:host=%s;port=%s;dbname=%s', need('DB_HOST'), need('DB_PORT'), need('DB_NAME')), need('MCP_RECORDS_DB_USER'), need('MCP_RECORDS_DB_PASSWORD'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $p->exec("SELECT set_config('app.member_id', '$member', false)");
    return $p;
}
function status_id(string $key): int { return (int) one('SELECT id FROM ticket_statuses WHERE key = :k', ['k' => $key]); }
function type_id(string $key): int { return (int) one('SELECT id FROM ticket_types WHERE key = :k', ['k' => $key]); }
