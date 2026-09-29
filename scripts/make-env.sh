#!/usr/bin/env bash
# Create .env with freshly generated secrets for one environment. Never overwrites an existing .env.
#
#   scripts/make-env.sh local    developer Mac   → http://tmc.localhost
#   scripts/make-env.sh ci       GitHub Actions  → http://tmc.localhost
#   scripts/make-env.sh server   hetser (UAT)    → http://tmc.100-79-142-44.sslip.io
set -euo pipefail
cd "$(dirname "$0")/.."

ENVIRONMENT="${1:-local}"
case "$ENVIRONMENT" in
  local|ci) DOMAIN="tmc.localhost";               FILES="docker-compose.yml:compose.local.yml" ;;
  server)   DOMAIN="tmc.100-79-142-44.sslip.io";  FILES="docker-compose.yml:compose.server.yml" ;;
  *) echo "usage: $0 local|ci|server" >&2; exit 1 ;;
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
EOF
chmod 600 .env
echo ".env created for '$ENVIRONMENT' ($DOMAIN) — secrets not shown."
