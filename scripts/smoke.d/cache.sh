# Page cache (repeat view HIT, bypass rules) and static-file caching + compression.
# Sourced by scripts/smoke-test.sh (uses its FAILED flag and ORIGIN, the address requests go to).
# shellcheck shell=bash
# shellcheck disable=SC2034 # FAILED is read by scripts/smoke-test.sh, which sources this file

cache_origin="${ORIGIN:-${SMOKE_ORIGIN:-http://127.0.0.1}}"   # ORIGIN is set by smoke-test.sh

cache_headers() { # host path [curl args…] → response headers, lower-case names
  local host="$1" path="$2"; shift 2
  curl -s -o /dev/null -D - -m 20 -H "Host: $host" "$@" "$cache_origin$path" | tr -d '\r' | awk -F': ' '{ printf "%s: %s\n", tolower($1), $2 }'
}

cache_expect() { # label, extended regex the headers must match, headers
  if grep -qE -- "$2" <<<"$3"; then
    printf '  ok    %-48s %s\n' "$1" "$(grep -E -- "$2" <<<"$3" | head -1)"
  else
    printf '  FAIL  %-48s no header matching /%s/\n' "$1" "$2"
    FAILED=1
  fi
}

for cache_host in "tmh.$TMC_BASE_DOMAIN" "$TMC_BASE_DOMAIN"; do
  cache_headers "$cache_host" "/" >/dev/null   # warm (earlier checks usually did already)
  cache_expect "$cache_host/ repeat view from page cache" '^x-tmc-cache: HIT$' "$(cache_headers "$cache_host" "/")"
done
cache_headers "tmh.$TMC_BASE_DOMAIN" "/hi/" >/dev/null
cache_expect "tmh.$TMC_BASE_DOMAIN/hi/ repeat view" '^x-tmc-cache: HIT$' "$(cache_headers "tmh.$TMC_BASE_DOMAIN" "/hi/")"
cache_expect "logged-in cookie is never served from cache" '^x-tmc-cache: BYPASS$' "$(cache_headers "tmh.$TMC_BASE_DOMAIN" "/" -H 'Cookie: wordpress_logged_in_smoke=1')"
cache_expect "POST is never served from cache" '^x-tmc-cache: BYPASS$' "$(cache_headers "tmh.$TMC_BASE_DOMAIN" "/" --data 'smoke=1')"
cache_expect "search is never cached" '^x-tmc-cache: BYPASS$' "$(cache_headers "tmh.$TMC_BASE_DOMAIN" "/?s=smoke")"
cache_expect "unknown query string is never cached" '^x-tmc-cache: BYPASS$' "$(cache_headers "tmh.$TMC_BASE_DOMAIN" "/?smoke=$RANDOM")"
cache_expect "HTML compressed (Brotli)" '^content-encoding: br$' "$(cache_headers "$TMC_BASE_DOMAIN" "/" -H 'Accept-Encoding: br, gzip')"
cache_expect "HTML compressed (gzip fallback)" '^content-encoding: gzip$' "$(cache_headers "$TMC_BASE_DOMAIN" "/" -H 'Accept-Encoding: gzip')"
cache_css="/wp-content/themes/tmc/assets/css/main.css"
cache_expect "versioned CSS cached for a year" '^cache-control: public, max-age=31536000, immutable$' "$(cache_headers "$TMC_BASE_DOMAIN" "$cache_css?ver=smoke" -H 'Accept-Encoding: br')"
cache_expect "unversioned CSS cached for a week" '^cache-control: public, max-age=604800$' "$(cache_headers "$TMC_BASE_DOMAIN" "$cache_css")"
cache_expect "CSS compressed" '^content-encoding: (br|gzip)$' "$(cache_headers "$TMC_BASE_DOMAIN" "$cache_css" -H 'Accept-Encoding: br, gzip')"

# The site and WP-CLI must share one cache server: a purge from WP-CLI (deploys, the cron container)
# has to reach what visitors are served. On UAT a shared Docker network once made the site resolve
# the cache host to another project's server, so purges never arrived (docs/operations/environments.md).
# Needs the compose stack of this directory; skipped where the smoke test runs without it (DR drill).
if command -v docker >/dev/null 2>&1 && docker compose ps --status running --services 2>/dev/null | grep -qx wordpress; then
  cache_headers "tmh.$TMC_BASE_DOMAIN" "/" >/dev/null
  if docker compose run --rm -T wpcli --url="tmh.$TMC_BASE_DOMAIN" eval 'tmc_page_cache_purge_blog( get_current_blog_id() );' </dev/null >/dev/null 2>&1; then
    cache_expect "purge from WP-CLI reaches the site (shared cache)" '^x-tmc-cache: MISS$' "$(cache_headers "tmh.$TMC_BASE_DOMAIN" "/")"
    cache_expect "page cached again after the purge" '^x-tmc-cache: HIT$' "$(cache_headers "tmh.$TMC_BASE_DOMAIN" "/")"
  else
    printf '  FAIL  %-48s %s\n' "page-cache purge from WP-CLI" "wp eval failed"
    FAILED=1
  fi
fi
unset cache_origin cache_host cache_css
