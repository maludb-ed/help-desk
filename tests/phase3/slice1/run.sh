#!/usr/bin/env bash
# Phase 3, slice 1 — queues and people: tests/phase3/slice1/run.sh
# A fresh SCRATCH database (helpdesk_dev3 — never the installed one), the application on :8291 (php -S, or HD_APP=apache for a real Apache with
# the rendered deploy vhost), a fake kernel (:8292) and a fake MaluDB (:8293). Needs `sudo -n -u postgres`, php, the kernel's web/node_modules
# (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/helpdesk-shots-p3s1). One line per check; exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export HD_DEV_DB=${HD_DEV_DB:-helpdesk_dev3} HD_DEV_ENV=${HD_DEV_ENV:-/tmp/helpdesk-dev3.env} HD_DEV_STATE=${HD_DEV_STATE:-/tmp/helpdesk-dev3-state} SHOTS=${SHOTS:-/tmp/helpdesk-shots-p3s1}
mkdir -p "$SHOTS" "$HD_DEV_STATE"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$HD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$HD_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$HD_DEV_STATE/maludb.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-rights queues organizations requesters sla settings json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice1/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice1/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 1 PROOFS PASSED" || echo "SOME SLICE 1 PROOFS FAILED"
exit $status
