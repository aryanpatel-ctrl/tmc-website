#!/usr/bin/env bash
# Deploy a checked-out commit to this server. Run by the self-hosted GitHub runner on hetser
# (see .github/workflows/pipeline.yml), or by hand:  scripts/deploy.sh <checkout-dir>
#
#   1. back up the database (last 10 kept)   2. sync code (server secrets untouched)
#   3. provision (idempotent)   4. update the proxy route safely   5. smoke test   6. record release
set -euo pipefail
SRC="$(cd "${1:?usage: deploy.sh <checkout-dir> [target-dir]}" && pwd)"
DEST="${2:-$HOME/docker/tmc-website}"
SHA="$(git -C "$SRC" rev-parse --short HEAD 2>/dev/null || echo unknown)"
STAMP="$(date +%Y%m%d-%H%M%S)"

cd "$DEST"
[ -f .env ] || { echo "No .env in $DEST — create it with: scripts/make-env.sh server" >&2; exit 1; }
grep -q '^COMPOSE_FILE=' .env || printf '\nTMC_ENV=server\nCOMPOSE_FILE=docker-compose.yml:compose.server.yml\n' >> .env
set -a; . ./.env; set +a

echo "==> [1/6] database backup"
mkdir -p backups && chmod 700 backups
if docker ps --format '{{.Names}}' | grep -qx tmc-db; then
  export MYSQL_PWD="$DB_PASSWORD"
  docker exec -e MYSQL_PWD tmc-db mariadb-dump -u"$DB_USER" --single-transaction --quick "$DB_NAME" \
    | gzip > "backups/pre-deploy-$STAMP-$SHA.sql.gz"
  unset MYSQL_PWD
  ls -1t backups/pre-deploy-*.sql.gz | tail -n +11 | xargs -r rm -f
  echo "   backups/pre-deploy-$STAMP-$SHA.sql.gz ($(du -h "backups/pre-deploy-$STAMP-$SHA.sql.gz" | cut -f1))"
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

echo "==> [5/6] smoke test"
./scripts/smoke-test.sh

echo "==> [6/6] record release"
echo "$SHA $STAMP ${GITHUB_ACTOR:-manual} ${GITHUB_RUN_ID:-}" | tee .deployed >> .release-history
echo "==> deployed $SHA"
