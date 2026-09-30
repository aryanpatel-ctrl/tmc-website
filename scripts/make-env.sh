#!/usr/bin/env bash
# Create .env with freshly generated secrets for one environment. Never overwrites an existing .env.
#
#   scripts/make-env.sh local    developer Mac   → http://tmc.localhost
#   scripts/make-env.sh ci       GitHub Actions  → http://tmc.localhost
#   scripts/make-env.sh server   hetser (UAT)    → http://tmc.100-79-142-44.sslip.io
#   TMC_BASE_DOMAIN=tmc.gov.in scripts/make-env.sh prod   production host (compose.prod.yml)
#   TMC_BASE_DOMAIN=tmc.gov.in scripts/make-env.sh dr     DR host: production configuration, restored from backup
set -euo pipefail
cd "$(dirname "$0")/.."

ENVIRONMENT="${1:-local}"
case "$ENVIRONMENT" in
  local|ci) DOMAIN="tmc.localhost";               FILES="docker-compose.yml:compose.local.yml" ;;
  server)   DOMAIN="tmc.100-79-142-44.sslip.io";  FILES="docker-compose.yml:compose.server.yml" ;;
  prod|dr)  DOMAIN="${TMC_BASE_DOMAIN:?set TMC_BASE_DOMAIN to the production domain, e.g. TMC_BASE_DOMAIN=tmc.gov.in}"
            FILES="docker-compose.yml:compose.prod.yml" ;;
  *) echo "usage: $0 local|ci|server|prod|dr" >&2; exit 1 ;;
esac

if [ -f .env ]; then
  echo ".env already exists — not overwriting."
  exit 0
fi

rand() { openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c "${1:-28}"; }

umask 077
cat > .env <<EOF
# TMC website — $ENVIRONMENT environment. Secrets: chmod 600, never commit.
TMC_ENV=$ENVIRONMENT
COMPOSE_FILE=$FILES
TMC_BASE_DOMAIN=$DOMAIN

DB_NAME=tmc_wp
DB_USER=tmc_wp
DB_PASSWORD=$(rand 32)
DB_ROOT_PASSWORD=$(rand 32)

WP_ADMIN_USER=tmcadmin
WP_ADMIN_PASSWORD=$(rand 24)
WP_ADMIN_EMAIL=admin@example.com

TMC_AUDIT_KEY=$(rand 48)

# Application gateway — local, CI and UAT are demonstration environments that use the DEMO mock
# backend (docs/integration/gateway.md). Real TMC services get TMC_APP_<SERVICE>_KEY lines instead.
TMC_DEMO=1
TMC_APPS_MOCK_KEY=$(rand 40)
EOF
if [ "$ENVIRONMENT" = prod ] || [ "$ENVIRONMENT" = dr ]; then
  cat >> .env <<EOF

# Production stack (compose.prod.yml): own project and container names, WordPress on the loopback
# port below behind TMC's reverse proxy, no UAT proxy route. On the DR host, replace TMC_AUDIT_KEY
# with production's key from the secret store, so the audit log still verifies after a failover.
COMPOSE_PROJECT_NAME=tmc-prod
TMC_WP_ENVIRONMENT=production
TMC_PROXY_ROUTE=none
TMC_HTTP_PORT=8080
SMOKE_ORIGIN=http://127.0.0.1:8080
EOF
fi
if [ "$ENVIRONMENT" != local ] && [ "$ENVIRONMENT" != ci ]; then
  cat >> .env <<'EOF'

# Off-host backup copy to TMC's backup target in India (scripts/backup/offsite-copy.sh, run from
# cron; docs/operations/backup-and-dr.md section 2). Fill in and uncomment:
# TMC_OFFSITE_TARGET=tmcbackup@backup.example:/
# TMC_OFFSITE_SSH_KEY=/home/deploy/.ssh/tmc_offsite
# TMC_OFFSITE_KNOWN_HOSTS=/home/deploy/.ssh/tmc_offsite_known_hosts
EOF
fi
chmod 600 .env
echo ".env created for '$ENVIRONMENT' ($DOMAIN) — secrets not shown."
