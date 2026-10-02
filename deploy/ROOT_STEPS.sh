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
