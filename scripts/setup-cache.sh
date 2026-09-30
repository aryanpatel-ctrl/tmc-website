#!/usr/bin/env bash
# Object cache + full-page cache (tender §4.7 peak load). Idempotent; run by setup.sh on every
# environment and every deploy, after content, so the final step can purge stale pages.
#
#   1. Redis Object Cache plugin (GPL-3.0), pinned, network-activated
#   2. its object-cache.php drop-in (updated only when it differs from the pinned plugin's copy)
#   3. the TMC page-cache drop-in, verification, purge (scripts/perf/install-page-cache.php)
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

REDIS_CACHE_VERSION="3.0.0"   # https://wordpress.org/plugins/redis-cache/ — update via docs/operations/patching.md

wp() { docker compose run --rm -T wpcli --url="$TMC_BASE_DOMAIN" "$@" </dev/null; }

installed="$(wp plugin get redis-cache --field=version 2>/dev/null || true)"
if [ "$installed" != "$REDIS_CACHE_VERSION" ]; then
  echo "   redis-cache ${installed:-not installed} → $REDIS_CACHE_VERSION"
  wp plugin install redis-cache --version="$REDIS_CACHE_VERSION" --force
fi
wp plugin is-active redis-cache --network || wp plugin activate redis-cache --network

# shellcheck disable=SC2016 # PHP code, expanded by PHP
if ! wp eval '$d = WP_CONTENT_DIR . "/object-cache.php"; $p = WP_PLUGIN_DIR . "/redis-cache/includes/object-cache.php"; exit( is_file( $d ) && md5_file( $d ) === md5_file( $p ) ? 0 : 1 );'; then
  wp redis update-dropin   # copies the drop-in and flushes the object cache
fi

wp eval-file /tmc-scripts/perf/install-page-cache.php
