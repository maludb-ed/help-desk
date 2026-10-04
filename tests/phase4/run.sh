#!/usr/bin/env bash
# Phase 4 — the two MCP servers, app_roles, the gate, the registry: tests/phase4/run.sh
# A fresh SCRATCH database (helpdesk_dev14 — never the installed one), the application on :8291 (php -S), a fake kernel (:8292 — run-facts, K6) and a fake MaluDB/MaluMail (:8293/:8294),
# and the servers under test on :8295 (records), :8296 (activity) and :8297 (records with a DEAD kernel url, for the fail-closed proofs). NOTHING REAL IS TOUCHED: no live service,
# Apache, unit, database or role password. PROOFS="gate tools_people" runs some. Needs `sudo -n -u postgres`, php, mcp/venv, the kernel's mcp/venv (for the hook proof).
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export HD_DEV_DB=${HD_DEV_DB:-helpdesk_dev14} HD_DEV_ENV=${HD_DEV_ENV:-/tmp/helpdesk-dev14.env} HD_DEV_STATE=${HD_DEV_STATE:-/tmp/helpdesk-dev14-state} SHOTS=${SHOTS:-/tmp/helpdesk-shots-p4}
mkdir -p "$HD_DEV_STATE"; rm -f "$HD_DEV_STATE/world.json" "$HD_DEV_STATE/fixture.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
"$ROOT/tests/phase4/mcp_servers.sh" start >/dev/null || { echo "mcp servers failed"; exit 1; }
trap '"$ROOT/tests/phase4/mcp_servers.sh" stop; "$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$HD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$HD_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$HD_DEV_STATE/maludb.log" FAKE_MALUMAIL_STATE="$HD_DEV_STATE/malumail.json" FAKE_MALUMAIL_LOG="$HD_DEV_STATE/malumail.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-gate tools_people tools_tickets tools_misc tools_activity registry hook}
for p in $proofs; do
  echo "== $p"
  php "tests/phase4/$p.php" || status=1
done
[ $status -eq 0 ] && echo "ALL PHASE 4 PROOFS PASSED" || echo "SOME PHASE 4 PROOFS FAILED"
exit $status
