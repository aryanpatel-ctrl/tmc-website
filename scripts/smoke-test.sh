#!/usr/bin/env bash
# HTTP smoke test of every site in both languages. Works on any environment: requests go to
# 127.0.0.1:80 with the site's Host header (compose.local.yml locally/CI, nginx-proxy-manager on the server).
set -uo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

SITES=("" "tmh." "hbchrcv." "mpmmcc." "hbchrcmzp." "hbchpunjab.")
FAILED=0
BODY="$(mktemp)"
trap 'rm -f "$BODY"' EXIT

check() { # host path expected-status [must-contain...]
  local host="$1" path="$2" want="$3"; shift 3
  local got
  got="$(curl -s -o "$BODY" -w '%{http_code}' -m 20 -H "Host: $host" "http://127.0.0.1$path")"
  local problem=""
  [ "$got" = "$want" ] || problem="HTTP $got (want $want)"
  for needle in "$@"; do
    grep -qF -- "$needle" "$BODY" || problem="${problem:+$problem; }missing '$needle'"
  done
  if grep -qE "Fatal error|Parse error|Warning: |Notice: |Deprecated: " "$BODY"; then
    problem="${problem:+$problem; }PHP error in page"
  fi
  if [ -n "$problem" ]; then
    printf '  FAIL  %-48s %s\n' "$host$path" "$problem"
    FAILED=1
  else
    printf '  ok    %-48s %s\n' "$host$path" "$got"
  fi
}

echo "==> smoke test (${TMC_BASE_DOMAIN})"
for site in "${SITES[@]}"; do
  host="${site}${TMC_BASE_DOMAIN}"
  check "$host" "/"    200 'lang="en-GB"' 'Skip to main content' 'tmc-hero' 'network-card'
  check "$host" "/hi/" 200 'lang="hi-IN"' 'मुख्य सामग्री पर जाएं' 'tmc-hero'
done
check "$TMC_BASE_DOMAIN" "/sitemap/"                 200 'sitemap-tree'
check "$TMC_BASE_DOMAIN" "/hi/sitemap-hi/"           200 'sitemap-tree'
check "$TMC_BASE_DOMAIN" "/screen-reader-access/"    200 'NVDA'
check "$TMC_BASE_DOMAIN" "/patient-care/"            200 'section-nav'
check "$TMC_BASE_DOMAIN" "/category/news/"           200 'card-grid'
check "$TMC_BASE_DOMAIN" "/?s=accessibility"         200 'result-list'
check "$TMC_BASE_DOMAIN" "/tenders/"                 200 'data-table' 'Sample tender'
check "$TMC_BASE_DOMAIN" "/tenders/?view=archive"    200 'data-table' 'badge-closed'
check "$TMC_BASE_DOMAIN" "/hi/tenders/"              200 'lang="hi-IN"' 'निविदाएं'
check "$TMC_BASE_DOMAIN" "/careers/"                 200 'data-table'
check "$TMC_BASE_DOMAIN" "/events/"                  200 'event-cards'
check "$TMC_BASE_DOMAIN" "/events/?view=calendar"    200 'cal-grid'
check "$TMC_BASE_DOMAIN" "/events/sample-cme-session/?ics=1" 200 'BEGIN:VCALENDAR'
check "$TMC_BASE_DOMAIN" "/departments/"             200 'Medical Oncology'
check "$TMC_BASE_DOMAIN" "/doctors/"                 200 'filter-form' 'doctor-card'
check "tmh.$TMC_BASE_DOMAIN" "/tenders/eoi-website-design-development-service/" 200 'TMH/TMH/2026-27/CAP/EO/0009' 'doc-list'
check "$TMC_BASE_DOMAIN" "/"                         200 'tmc-opportunities' 'dated-list'
check "$TMC_BASE_DOMAIN" "/does-not-exist-$RANDOM/" 404 'Go to home page'
check "$TMC_BASE_DOMAIN" "/wp-content/themes/tmc/assets/css/main.css" 200
check "$TMC_BASE_DOMAIN" "/wp-login.php"             200
check "$TMC_BASE_DOMAIN" "/xmlrpc.php"               403

# Feature checks: each scripts/smoke.d/*.sh calls `check` (one file per feature).
for extra in scripts/smoke.d/*.sh; do
  [ -f "$extra" ] || continue
  echo "--- $(basename "$extra")"
  # shellcheck disable=SC1090
  . "$extra"
done

if [ "$FAILED" -ne 0 ]; then
  echo "==> smoke test FAILED"
  exit 1
fi
echo "==> smoke test passed"
