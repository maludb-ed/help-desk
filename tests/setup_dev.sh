#!/usr/bin/env bash
# Builds the SCRATCH database the proofs run against — never the installed application's database.
#   tests/setup_dev.sh            (needs `sudo -n -u postgres`; recreates $HD_DEV_DB, default helpdesk_dev)
# 1. drops and creates the scratch database; 2. applies db/*.sql in order as postgres (the same files the installer applies);
# 3. leaves the cluster roles' passwords ALONE when the app is installed (reads them from config/.env); else gives them a scratch
#    password (the kernel's installer sets fresh ones at `apply` — it does so whenever the application has no config/.env, and this
#    script never writes one); 4. writes the proofs' environment to $HD_DEV_ENV (default /tmp/helpdesk-dev.env, mode 600): real
#    environment variables, which app/bootstrap.php and mcp/db.py read ahead of config/.env. Nothing here is committed or installed.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/.." && pwd)
DB=${HD_DEV_DB:-helpdesk_dev}
ENVF=${HD_DEV_ENV:-/tmp/helpdesk-dev.env}
PW=$(openssl rand -hex 16)
PSQL="sudo -n -u postgres psql -v ON_ERROR_STOP=1 -q"
$PSQL -c "DROP DATABASE IF EXISTS $DB" -c "CREATE DATABASE $DB"
for f in "$ROOT"/db/0*.sql; do $PSQL -d "$DB" -f "$f" >/dev/null; done
LIVEENV="$ROOT/config/.env"
lv() { grep -m1 "^$1=" "$LIVEENV" | cut -d= -f2-; }
if [ -f "$LIVEENV" ] && [ -n "$(lv DB_PASSWORD)" ] && [ -n "$(lv MCP_RECORDS_DB_PASSWORD)" ] && [ -n "$(lv MCP_ACTIVITY_DB_PASSWORD)" ]; then
  PW_RW=$(lv DB_PASSWORD); PW_REC=$(lv MCP_RECORDS_DB_PASSWORD); PW_ACT=$(lv MCP_ACTIVITY_DB_PASSWORD)
else
  PW_RW=$PW; PW_REC=$PW; PW_ACT=$PW
  for r in helpdesk_rw helpdesk_records_ro helpdesk_activity_ro; do $PSQL -c "ALTER ROLE $r WITH LOGIN PASSWORD '$PW'"; done
fi
umask 077
cat > "$ENVF" <<ENV
APP_ENV=dev
APP_DEBUG=1
APP_NAME="Help Desk"
APP_KEY=helpdesk
APP_URL=http://127.0.0.1:8291
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=$DB
DB_USER=helpdesk_rw
DB_PASSWORD=$PW_RW
MCP_RECORDS_DB_USER=helpdesk_records_ro
MCP_RECORDS_DB_PASSWORD=$PW_REC
MCP_ACTIVITY_DB_USER=helpdesk_activity_ro
MCP_ACTIVITY_DB_PASSWORD=$PW_ACT
APP_INTERNAL_PORT=8291
MCP_RECORDS_PORT=8294
MCP_ACTIVITY_PORT=8295
ACTION_TOKEN_KEY=$(openssl rand -hex 32)
ACTIONS_RELAY_KEY=$(openssl rand -hex 32)
OS_LAUNCHER_URL=https://app.example.invalid/
OS_INTERNAL_URL=http://127.0.0.1:8292
OS_APPLICATION_TOKEN=osapp_dev_$(openssl rand -hex 12)
MALUDB_API_URL=http://127.0.0.1:8293
MALUDB_API_TOKEN=dev-maludb-$(openssl rand -hex 8)
ATTACHMENT_MAX_BYTES=200000
ENV
echo "scratch database $DB ready; environment in $ENVF"
