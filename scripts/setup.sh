#!/usr/bin/env bash
# Bring a TMC environment to its expected state. Idempotent: runs on every deploy, in CI and locally.
#
#   1. containers   2. network + 5 unit sites   3. pinned plugins + language packs
#   4. TMC theme on every site   5. per site: languages, home page, migrations, pages/menus/content
#   6. object cache + page cache (scripts/setup-cache.sh)
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

POLYLANG_VERSION="3.8.10"
SITES=("" "tmh." "hbchrcv." "mpmmcc." "hbchrcmzp." "hbchpunjab.")

wp()      { docker compose run --rm -T wpcli --url="$TMC_BASE_DOMAIN" "$@" </dev/null; }
wp_on()   { local site="$1"; shift; docker compose run --rm -T wpcli --url="${site}${TMC_BASE_DOMAIN}" "$@" </dev/null; }
wp_file() { docker compose run --rm -T wpcli --url="$1${TMC_BASE_DOMAIN}" eval-file - < "$2"; }

echo "==> containers"
docker compose up -d --build --remove-orphans
for _ in $(seq 1 60); do
  docker compose exec -T wordpress test -f /var/www/html/wp-includes/version.php 2>/dev/null && break
  sleep 2
done

echo "==> network + unit sites"
./scripts/install-network.sh

echo "==> plugins (pinned)"
if ! wp plugin is-installed polylang; then
  wp plugin install polylang --version="$POLYLANG_VERSION"
fi
wp plugin is-active polylang --network || wp plugin activate polylang --network

echo "==> language packs"
wp language core install hi_IN en_GB >/dev/null 2>&1 || true
wp language plugin install --all hi_IN en_GB >/dev/null 2>&1 || true

echo "==> theme"
wp theme enable tmc --network >/dev/null
for site in "${SITES[@]}"; do
  if [ "$(wp_on "$site" option get stylesheet)" != "tmc" ]; then
    wp_on "$site" theme activate tmc
  fi
done

echo "==> per-site configuration and content"
for site in "${SITES[@]}"; do
  echo "--- ${site}${TMC_BASE_DOMAIN}"
  wp_file "$site" scripts/setup-languages.php | grep -v "already configured" || true
  wp_file "$site" scripts/seed-home-pages.php
  wp_on "$site" eval-file /tmc-scripts/migrate.php          # run-once data migrations
  wp_file "$site" scripts/seed-site-structure.php | grep -vE "^  page /" || true
done

echo "==> caching (Redis object cache + page cache; purged after every provision)"
./scripts/setup-cache.sh

echo "==> setup complete for ${TMC_ENV:-?} (${TMC_BASE_DOMAIN})"
