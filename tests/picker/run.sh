#!/usr/bin/env bash
# The record picker (docs/record-picker.md): tests/picker/run.sh
# A fresh SCRATCH database (helpdesk_dev_picker — never the installed one; the checkout needs a scratch config/.env, see below), the application on :8392 (php -S with tests/dev_router.php) and a
# fake kernel on :8393 for the directory sync. Needs `sudo -n -u postgres`, php; BROWSER=1 adds the headless-Chromium pass
# (tests/picker/picker-browser.mjs; the kernel's web/node_modules: `ln -s /var/www/web/node_modules` beside a scratch copy, see the file's header).
# One line per check; exit 0 only if all passed.
set -uo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
export HD_DEV_DB=${HD_DEV_DB:-helpdesk_dev_picker} HD_DEV_ENV=${HD_DEV_ENV:-/tmp/helpdesk-picker.env}
STATE=${HD_DEV_STATE:-/tmp/helpdesk-picker-state}
case "$HD_DEV_DB" in helpdesk_dev*) ;; *) echo "refusing to run against $HD_DEV_DB: a proof writes — only a helpdesk_dev* scratch database" >&2; exit 2;; esac
mkdir -p "$STATE"
# The scratch database is built HERE, not by tests/setup_dev.sh: that script gives the cluster's roles fresh passwords whenever the checkout has no
# config/.env — and the roles (helpdesk_rw, …) are the INSTALLED application's too. This checkout's config/.env (a scratch copy; gitignored) carries
# the roles' current passwords; nothing here alters a role.
ENVSRC="$ROOT/config/.env"
[ -f "$ENVSRC" ] || { echo "no $ENVSRC: copy the roles' credentials into a scratch config/.env first (DB_NAME=$HD_DEV_DB, APP_INTERNAL_PORT=8392)" >&2; exit 2; }
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $HD_DEV_DB" -c "CREATE DATABASE $HD_DEV_DB" || exit 1
for f in "$ROOT"/db/0*.sql; do $PSQL -d "$HD_DEV_DB" -f "$f" >/dev/null || { echo "migration $f failed"; exit 1; }; done
umask 077
{ grep -v -E '^(DB_NAME|APP_URL|APP_INTERNAL_PORT|OS_INTERNAL_URL|APP_ENV|OS_ENABLED)=' "$ENVSRC"
  echo "DB_NAME=$HD_DEV_DB"; echo "APP_URL=http://127.0.0.1:8392"; echo "APP_INTERNAL_PORT=8392"; echo "OS_INTERNAL_URL=http://127.0.0.1:8393"; echo "APP_ENV=development"; echo "OS_ENABLED="; } > "$HD_DEV_ENV"
set -a; . "$HD_DEV_ENV"; set +a
export FAKE_KERNEL_STATE="$STATE/kernel.json"
php -r '$f=json_decode(file_get_contents($argv[1]),true); file_put_contents($argv[2], json_encode(["feed"=>$f["feed"]]));' "$ROOT/bin/dev_directory.json" "$FAKE_KERNEL_STATE"
cd "$ROOT"
stop() { [ -f "$STATE/kernel.pid" ] && kill "$(cat "$STATE/kernel.pid")" 2>/dev/null; [ -f "$STATE/app.pid" ] && kill "$(cat "$STATE/app.pid")" 2>/dev/null; fuser -k 8392/tcp 8393/tcp >/dev/null 2>&1; rm -f "$STATE"/*.pid; }
stop
php -S 127.0.0.1:8393 tests/fake_kernel.php >"$STATE/kernel.log" 2>&1 & echo $! > "$STATE/kernel.pid"
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8392 -t html tests/dev_router.php >"$STATE/app.log" 2>&1 & echo $! > "$STATE/app.pid"
trap stop EXIT
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8392/api/v1/health && break; sleep 0.2; done
php bin/directory_sync.php --full >/dev/null || { echo "the first sync failed"; exit 1; }
status=0
for f in app/picker.php app/pickers.php html/pick/index.php app/views/shared/picker-rows.php app/views/layout.php app/views/tickets/form.php app/views/tickets/partials/fields-panel.php app/views/requesters/form.php app/views/requesters/list.php app/views/queues/form.php app/views/queues/view.php app/views/views/form.php; do
  php -l "$f" >/dev/null 2>&1 && echo "  ok   lint $f" || { echo "  FAIL lint $f"; status=1; }; done
node --check html/assets/js/record-picker.js 2>/dev/null && echo "  ok   node parses record-picker.js" || { echo "  FAIL record-picker.js does not parse"; status=1; }
echo "== proof"; HD_LOG="$STATE/app.log" php tests/picker/proof.php || status=1
if [ "${BROWSER:-}" = 1 ]; then
  echo "== browser"; W=/tmp/helpdesk-picker; mkdir -p "$W" "${SHOTS:-/tmp/helpdesk-picker-shots}"; ln -sfn /var/www/web/node_modules "$W/node_modules"; cp tests/picker/picker-browser.mjs "$W/"
  ( cd "$W" && S="${SHOTS:-/tmp/helpdesk-picker-shots}" BASE=http://127.0.0.1:8392 ROOT="$ROOT" ENVF="$HD_DEV_ENV" node picker-browser.mjs ) || status=1
fi
[ $status -eq 0 ] && echo "ALL PICKER PROOFS PASSED" || echo "SOME PICKER PROOFS FAILED"
exit $status
