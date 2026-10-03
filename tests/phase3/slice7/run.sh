#!/usr/bin/env bash
# Phase 3, slice 7 — email and notifications: tests/phase3/slice7/run.sh
# A fresh SCRATCH database (helpdesk_dev10 — never the installed one), the application on :8291 (php -S, or HD_APP=apache for a real Apache with
# the rendered deploy vhost), a fake kernel (:8292) and a fake MaluDB (:8293). Needs `sudo -n -u postgres`, php, the kernel's web/node_modules
# (Playwright + Chromium) for the browser proof. Screenshots go to $SHOTS (default /tmp/helpdesk-shots-p3s7). One line per check; exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../../.." && pwd)
export HD_DEV_DB=${HD_DEV_DB:-helpdesk_dev10} HD_DEV_ENV=${HD_DEV_ENV:-/tmp/helpdesk-dev10.env} HD_DEV_STATE=${HD_DEV_STATE:-/tmp/helpdesk-dev10-state} SHOTS=${SHOTS:-/tmp/helpdesk-shots-p3s7}
mkdir -p "$SHOTS" "$HD_DEV_STATE"; rm -f "$HD_DEV_STATE/world.json"
"$ROOT/tests/setup_dev.sh" >/dev/null || { echo "setup failed"; exit 1; }
"$ROOT/tests/phase2/servers.sh" start >/dev/null || { echo "servers failed"; exit 1; }
trap '"$ROOT/tests/phase2/servers.sh" stop' EXIT
set -a; . "$HD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$HD_DEV_STATE/kernel.json" FAKE_MALUDB_LOG="$HD_DEV_STATE/maludb.log" FAKE_MALUMAIL_STATE="$HD_DEV_STATE/malumail.json" FAKE_MALUMAIL_LOG="$HD_DEV_STATE/malumail.log"
cd "$ROOT"
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
proofs=${PROOFS:-webhook threading parsing poll outbound leaks screens json}
for p in $proofs; do
  [ "$p" = browser ] && continue
  echo "== $p"; php "tests/phase3/slice7/$p.php" || status=1
done
if [ -z "${PROOFS:-}" ] || [[ " $PROOFS " == *" browser "* ]]; then echo "== browser"; node tests/phase3/slice7/browser.mjs || status=1; fi
echo "== registry"; php bin/build_action_registry.php --check >/dev/null 2>&1 && echo "  ok   the registry matches the manifest" || { echo "  FAIL the registry is stale — run bin/build_action_registry.php"; status=1; }
[ $status -eq 0 ] && echo "ALL SLICE 7 PROOFS PASSED" || echo "SOME SLICE 7 PROOFS FAILED"
exit $status
