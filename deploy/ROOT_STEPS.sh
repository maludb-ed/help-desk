#!/usr/bin/env bash
# Help Desk — what root or the owner does to install it beside the kernel, in order. Written at the end of Phase 0
# (2026-10-02); later phases ADD steps and mark them. NOTHING HERE HAS BEEN RUN: the proofs use a scratch database
# (tests/setup_dev.sh), php -S and fixtures, and never touch an installed application, Apache or systemd.
#   bash deploy/ROOT_STEPS.sh          prints this plan (the default)
#   sudo bash deploy/ROOT_STEPS.sh apply   runs the steps with no decision in them (0 and 2)
set -euo pipefail
APP=/srv/apps/helpdesk
KERNEL=/var/www
MODE=${1:-plan}
run() { echo "+ $*"; if [ "$MODE" = apply ]; then "$@"; fi; }

# ------------------------------------------------------------------------------------------------------------------
# 0. PIN THE PORTS before the installer's first apply (the owner's decision D12; the lesson of 2026-10-02, when a vhost went
#    live with {{MCP_RECORDS_PORT}} in it and Apache fell on every name). A config/.env holding ONLY the three ports: the
#    installer reads them (step 'ports' says done), fills every other required key itself, and sets fresh role passwords
#    because DB_PASSWORD is absent. Never put anything else in this file by hand.
echo "== 0. Pin the ports (ROOT) — only if $APP/config/.env does not exist yet"
if [ ! -f "$APP/config/.env" ]; then
  run bash -c "printf 'APP_INTERNAL_PORT=8182\nMCP_RECORDS_PORT=8825\nMCP_ACTIVITY_PORT=8826\n' > '$APP/config/.env' && chgrp www-data '$APP/config/.env' && chmod 640 '$APP/config/.env'"
else
  echo "   (config/.env exists — leaving it alone)"
fi
#    See that it worked:  php $KERNEL/bin/app_install.php plan $APP --domain subello.com | grep -E '^(todo|done) +(ports|vhost)'   -> ports done, no {{…}} unfilled

# ------------------------------------------------------------------------------------------------------------------
# 1. Look (read-only, any user): what the installer would do.
echo "== 1. The installer's plan (read-only)"
echo "   php $KERNEL/bin/app_install.php plan $APP --by <super-admin email> --domain subello.com"

# ------------------------------------------------------------------------------------------------------------------
# 2. The installer's apply (ROOT) — PHASE 2 onward, not before the shell exists: database and roles, config/.env, the vhost,
#    the units, the registration (catalog kind ours, the application with /sso and /sso/logout, scope_kind none), the
#    application token into config/.env, skills imported, approval policies, the installing super-admin's admin grant.
#    --grant-standing-departments gives every standing department the Requester role (key user) at write (K10's rule).
#    --hire-agents hires expert, triage and frontline (a default application's rule). Run it twice: the roles are read from
#    app_roles only once the records MCP answers (Phase 4).
echo "== 2. apply (ROOT) — from Phase 2"
echo "   sudo php $KERNEL/bin/app_install.php apply $APP --by <super-admin email> --domain subello.com --grant-standing-departments --hire-agents"

# ------------------------------------------------------------------------------------------------------------------
# 3. The owner's keys and decisions (any time after 2):
echo "== 3. The owner's"
echo "   - MaluMail: create support@subello.com, point its new-mail webhook at https://helpdesk.subello.com/channels/malumail/mail,"
echo "     put MALUMAIL_API_KEY, MAIL_FROM, MAIL_FROM_NAME and the mailbox's webhook secret into $APP/config/.env (slice 7)"
echo "   - the K6 text sender, if texts are wanted: php $KERNEL/bin/notify_endpoint_set.php (already set when another application uses it)"
echo "   - DNS and TLS for helpdesk.subello.com (until then /etc/hosts maps it to this host)"
echo "   - the agents' duties (triage */5, frontline */10) in Agent HR until the installer reads agents[].duty"
echo "   - add helpdesk to $KERNEL/bin/install_default_applications.sh (K10) and build K8, K9, K11 in the kernel"

# ------------------------------------------------------------------------------------------------------------------
# 4. PHASE 3 (slices 8–10, 2026-10-03/04) — after 2, or again if 2 ran before Phase 3:
echo "== 4. Phase 3"
echo "   - the worker timer (slice 8): the installer installs deploy/helpdesk-worker.{service,timer}; if 2 ran earlier, re-run apply so the units land, then"
echo "     systemctl status helpdesk-worker.timer   (one pass a minute: outbox, mail poll, SLA, snooze, auto-close, rules, surveys, dispatch retries, housekeeping)"
echo "   - the vhost gained rewrites in Phase 3 (a rule's runs, a merge, /reports/{report}, /kb/s/{slug}, /kb/{slug} — both vhosts): if 2 ran earlier, re-run apply so the"
echo "     rendered deploy/apache-helpdesk.conf is installed again, then apachectl configtest && systemctl reload apache2"
echo "   - the knowledge base's files live under $APP/storage/attachments/articles (slice 9): the storage directory must be writable by www-data (the installer makes storage/)"
echo "   - nothing else: reports (slice 10) write no table and need no key"

# ------------------------------------------------------------------------------------------------------------------
# 5. PHASE 4 (the two MCP servers, 2026-10-04) — nothing new to decide; what `apply` now does that it did not:
echo "== 5. Phase 4"
echo "   - mcp/venv exists (python3 -m venv mcp/venv && mcp/venv/bin/pip install -r mcp/requirements.txt — the installer's step 'venv' says done); the proofs use it"
echo "   - apply installs and starts helpdesk-records-mcp and helpdesk-activity-mcp on MCP_RECORDS_PORT and MCP_ACTIVITY_PORT (pinned in step 0), reachable as https://helpdesk.<domain>/mcp/records and /mcp/activity"
echo "   - the installer's step 'registry' writes $KERNEL/mcp/registries/helpdesk.json (96 actions, 14 resolved entities) and asks you to restart certstudy-actions-mcp: that is when the kernel's Actions MCP offers Help Desk's actions"
echo "   - check:  curl -s -o /dev/null -w '%{http_code}\n' https://helpdesk.<domain>/mcp/records   -> 401 (no token) means the server answers behind the proxy"
