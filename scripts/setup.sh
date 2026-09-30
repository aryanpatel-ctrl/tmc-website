#!/usr/bin/env bash
# Bring a TMC environment to its expected state. Idempotent: runs on every deploy, in CI and locally.
#
#   1. containers   2. network + 5 unit sites   3. pinned plugins + language packs
#   4. TMC theme on every site   5. per site: languages, home page, migrations, pages/menus/content
#   6. object cache + page cache (scripts/setup-cache.sh)
set -euo pipefail
cd "$(dirname "$0")/.."
# Settings added after an environment was first created (make-env.sh writes them for new ones).
# Demonstration environments only (local, CI, UAT): the DEMO mock backend's key and the demo flag.
if grep -qE '^TMC_ENV=(local|ci|server)$' .env; then
  grep -q '^TMC_APPS_MOCK_KEY=' .env || echo "TMC_APPS_MOCK_KEY=$(openssl rand -hex 20)" >> .env
  grep -q '^TMC_DEMO=' .env || echo "TMC_DEMO=1" >> .env
fi
set -a; . ./.env; set +a

POLYLANG_VERSION="3.8.10"
TWO_FACTOR_VERSION="0.17.0"   # wordpress.org/plugins/two-factor (GPL-2.0-or-later): TOTP + backup codes
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

echo "==> WordPress core (pinned by the image tag in wordpress/Dockerfile)"
# The image copies WordPress into the wp_html volume only on first start, so an existing environment
# keeps its core version until it is updated here; older cores are brought up to the pinned release.
CORE_VERSION="$(sed -n 's/^FROM wordpress:\([0-9][0-9.]*\)-.*/\1/p' wordpress/Dockerfile | head -n 1)"
if [ -n "$CORE_VERSION" ]; then
  installed_core="$(wp core version)"
  if [ "$installed_core" != "$CORE_VERSION" ] && [ "$(printf '%s\n%s\n' "$installed_core" "$CORE_VERSION" | sort -V | head -n 1)" = "$installed_core" ]; then
    echo "   WordPress $installed_core → $CORE_VERSION"
    wp core update --version="$CORE_VERSION"
    wp core update-db --network
  fi
fi

echo "==> plugins (pinned)"
# Exact pins: (re)installed whenever the installed version differs from the pinned one.
if [ "$(wp plugin get polylang --field=version 2>/dev/null)" != "$POLYLANG_VERSION" ]; then
  wp plugin install polylang --version="$POLYLANG_VERSION" --force
fi
wp plugin is-active polylang --network || wp plugin activate polylang --network
# Exact pin (reinstalled when the version differs); MFA policy in src/mu-plugins/tmc-core/security-mfa.php
if [ "$(wp plugin get two-factor --field=version 2>/dev/null)" != "$TWO_FACTOR_VERSION" ]; then
  wp plugin install two-factor --version="$TWO_FACTOR_VERSION" --force
fi
wp plugin is-active two-factor --network || wp plugin activate two-factor --network
# Plugins bundled with the WordPress image that TMC does not use are removed (smaller attack surface).
installed_plugins="$(wp plugin list --field=name)"
for unused in akismet hello; do
  if grep -qx "$unused" <<<"$installed_plugins"; then wp plugin delete "$unused"; fi
done

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
