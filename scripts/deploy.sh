#!/usr/bin/env bash
# Deploy a checked-out commit to this server. Run by the self-hosted GitHub runners — UAT on hetser
# (.github/workflows/pipeline.yml) and production (.github/workflows/release.yml) — or by hand:
#   scripts/deploy.sh <checkout-dir> [target-dir]
#   TMC_DEPLOY_TARGET=production scripts/deploy.sh <checkout-dir> ~/docker/tmc-website-prod
#
#   1. back up (database dump, last 10 kept, + a full snapshot by the backup container)
#   2. sync code (server secrets untouched)   3. provision (idempotent)
#   4. update the UAT proxy route safely (TMC_PROXY_ROUTE=npm; production: none)
#   5. smoke test   6. record release
#
# TMC_DEPLOY_TARGET (uat | production, default uat) must match TMC_ENV in the target's .env
# (server | prod), so a UAT deploy can never land on production or the other way round.
set -euo pipefail
SRC="$(cd "${1:?usage: deploy.sh <checkout-dir> [target-dir]}" && pwd)"
DEST="${2:-$HOME/docker/tmc-website}"
SHA="$(git -C "$SRC" rev-parse --short HEAD 2>/dev/null || echo unknown)"
STAMP="$(date +%Y%m%d-%H%M%S)"
TARGET="${TMC_DEPLOY_TARGET:-uat}"
umask 077   # backups and release files readable by this user only

cd "$DEST"
[ -f .env ] || { echo "No .env in $DEST — create it with: scripts/make-env.sh server (UAT) or prod" >&2; exit 1; }
grep -q '^COMPOSE_FILE=' .env || printf '\nTMC_ENV=server\nCOMPOSE_FILE=docker-compose.yml:compose.server.yml\n' >> .env
unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES   # only this directory's .env decides
set -a; . ./.env; set +a

case "$TARGET" in
  uat)        want_env=server ;;
  production) want_env=prod ;;
  *) echo "TMC_DEPLOY_TARGET must be uat or production" >&2; exit 1 ;;
esac
if [ "${TMC_ENV:-}" != "$want_env" ]; then
  echo "Refusing: deploy target '$TARGET' needs TMC_ENV=$want_env in $DEST/.env (found '${TMC_ENV:-unset}')" >&2
  exit 1
fi
# UAT .env files created before the application gateway get its demo settings once (the key is
# generated here and never printed). Production never runs the demo backend.
if [ "$TARGET" = uat ] && ! grep -q '^TMC_APPS_MOCK_KEY=' .env; then
  printf '\n# Application gateway demo backend (added by deploy.sh; see docs/integration/gateway.md)\nTMC_DEMO=1\nTMC_APPS_MOCK_KEY=%s\n' \
    "$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c 40)" >> .env
  set -a; . ./.env; set +a
  echo "==> added TMC_DEMO and a generated TMC_APPS_MOCK_KEY to $DEST/.env"
fi
echo "==> deploying $SHA to $TARGET ($TMC_BASE_DOMAIN, $DEST)"

echo "==> [1/6] backup"
mkdir -p backups && chmod 700 backups
if docker compose ps --status running --services 2>/dev/null | grep -qx db; then
  export MYSQL_PWD="$DB_PASSWORD"
  docker compose exec -T -e MYSQL_PWD db mariadb-dump -u"$DB_USER" --single-transaction --quick "$DB_NAME" \
    | gzip > "backups/pre-deploy-$STAMP-$SHA.sql.gz"
  unset MYSQL_PWD
  ls -1t backups/pre-deploy-*.sql.gz | tail -n +11 | xargs -r rm -f
  chmod 600 backups/*.sql.gz
  echo "   backups/pre-deploy-$STAMP-$SHA.sql.gz ($(du -h "backups/pre-deploy-$STAMP-$SHA.sql.gz" | cut -f1))"
  # Full restore point (database + files + config) — rollback: scripts/dr/restore.sh --dump … or --backup <name>
  if docker compose ps --status running --services 2>/dev/null | grep -qx backup; then
    docker compose exec -T backup tmc-backup run pre-deploy || echo "   warning: full snapshot failed (the database dump above is still available)"
  fi
else
  echo "   database not running yet — skipped"
fi

echo "==> [2/6] sync code $SHA"
rsync -a --delete \
  --exclude='.git/' --exclude='.env' --exclude='.env.*' --exclude='demo-users.txt' \
  --exclude='backups/' --exclude='.deployed' --exclude='.release-history' \
  "$SRC/" "$DEST/"

echo "==> [3/6] provision"
./scripts/setup.sh

echo "==> [4/6] proxy route"
if [ "${TMC_PROXY_ROUTE:-npm}" != "npm" ]; then
  echo "   skipped (TMC_PROXY_ROUTE=${TMC_PROXY_ROUTE}: TMC's reverse proxy routes this environment)"
else
  want="$(sha256sum nginx/tmc-website.conf | cut -d' ' -f1)"
  have="$(docker exec nginx-proxy-manager sh -c 'sha256sum /data/nginx/custom/http.conf 2>/dev/null' | cut -d' ' -f1 || true)"
  if [ "$want" = "$have" ]; then
    echo "   unchanged"
  else
    docker exec nginx-proxy-manager sh -c 'mkdir -p /data/nginx/custom; [ -f /data/nginx/custom/http.conf ] && cp /data/nginx/custom/http.conf /data/nginx/custom/http.conf.bak || true'
    docker cp nginx/tmc-website.conf nginx-proxy-manager:/data/nginx/custom/http.conf
    if docker exec nginx-proxy-manager nginx -t >/dev/null 2>&1; then
      docker exec nginx-proxy-manager nginx -s reload && echo "   updated and reloaded"
    else
      docker exec nginx-proxy-manager sh -c 'if [ -f /data/nginx/custom/http.conf.bak ]; then mv /data/nginx/custom/http.conf.bak /data/nginx/custom/http.conf; else rm -f /data/nginx/custom/http.conf; fi'
      echo "   new route failed nginx -t — previous route restored" >&2
      exit 1
    fi
  fi
fi

echo "==> [5/6] smoke test"
./scripts/smoke-test.sh

echo "==> [6/6] record release"
echo "$SHA $STAMP ${GITHUB_ACTOR:-manual} ${GITHUB_RUN_ID:-} ${TARGET} ${TMC_RELEASE_TAG:-}" | tee .deployed >> .release-history
echo "==> deployed $SHA${TMC_RELEASE_TAG:+ ($TMC_RELEASE_TAG)} to $TARGET"
