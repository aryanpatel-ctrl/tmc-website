# shellcheck shell=bash
# Security smoke checks (W2): HTTP security headers, CSP nonce on every script, security.txt,
# user enumeration, admin network allow-list. Sourced by scripts/smoke-test.sh, which provides
# check(), $BODY, $FAILED and the .env variables.

sec_result() { # label problem
  if [ -n "$2" ]; then
    printf '  FAIL  %-48s %s\n' "$1" "$2"
    FAILED=1
  else
    printf '  ok    %-48s %s\n' "$1" "${3:-ok}"
  fi
}

sec_fetch() { # host path -> response headers on stdout (lower-cased names, no CR), body in $BODY
  curl -s -o "$BODY" -D - -m 20 -H "Host: $1" "http://127.0.0.1$2" | tr -d '\r'
}

# sec_headers HOST PATH REGEX... : every extended regex must match a response header line (case-insensitive)
sec_headers() {
  local host="$1" path="$2" headers problem="" re
  shift 2
  headers="$(sec_fetch "$host" "$path")"
  for re in "$@"; do
    grep -qiE -- "$re" <<<"$headers" || problem="${problem:+$problem; }no header /$re/"
  done
  sec_result "$host$path" "$problem" "headers"
}

# sec_no_headers HOST PATH REGEX... : no response header line may match
sec_no_headers() {
  local host="$1" path="$2" headers problem="" re
  shift 2
  headers="$(sec_fetch "$host" "$path")"
  for re in "$@"; do
    if grep -qiE -- "$re" <<<"$headers"; then problem="${problem:+$problem; }unexpected /$re/"; fi
  done
  sec_result "$host$path" "$problem" "no leaks"
}

# sec_absent HOST PATH STATUS TEXT... : expected status and none of the texts in the body
sec_absent() {
  local host="$1" path="$2" want="$3" got problem="" needle
  shift 3
  got="$(curl -s -o "$BODY" -w '%{http_code}' -m 20 -H "Host: $host" "http://127.0.0.1$path")"
  [ "$got" = "$want" ] || problem="HTTP $got (want $want)"
  for needle in "$@"; do
    if grep -qiF -- "$needle" "$BODY"; then problem="${problem:+$problem; }reveals '$needle'"; fi
  done
  sec_result "$host$path" "$problem" "$got"
}

# sec_script_nonces HOST PATH : every executable <script> carries the nonce from the CSP header
sec_script_nonces() {
  local host="$1" path="$2" headers nonce unnonced
  headers="$(sec_fetch "$host" "$path")"
  nonce="$(grep -i '^content-security-policy:' <<<"$headers" | grep -oE "'nonce-[A-Za-z0-9_-]+'" | head -n 1 | sed -E "s/^'nonce-(.*)'$/\1/")"
  if [ -z "$nonce" ]; then
    sec_result "$host$path (script nonces)" "no nonce in Content-Security-Policy"
    return
  fi
  # Data blocks (JSON-LD, templates) are never executed, so CSP does not apply to them.
  unnonced="$(grep -oiE '<script[^>]*>' "$BODY" | grep -viE 'type="(application/(ld\+)?json|text/template|text/html|text/x-template)"' | grep -vF "nonce=\"$nonce\"" || true)"
  sec_result "$host$path (script nonces)" "${unnonced:+script without nonce: $(head -n 1 <<<"$unnonced")}" "all scripts nonced"
}

for sec_host in "$TMC_BASE_DOMAIN" "tmh.$TMC_BASE_DOMAIN"; do
  sec_headers "$sec_host" "/" \
    "^content-security-policy:.*script-src 'self' 'nonce-[A-Za-z0-9_-]{16,}'" \
    "^content-security-policy:.*object-src 'none'" \
    "^content-security-policy:.*frame-ancestors 'self'" \
    "^content-security-policy:.*base-uri 'self'" \
    "^x-content-type-options: nosniff" \
    "^x-frame-options: sameorigin" \
    "^referrer-policy: strict-origin-when-cross-origin" \
    "^permissions-policy:.*camera=\(\)" \
    "^cross-origin-opener-policy: same-origin" \
    "^cross-origin-resource-policy: same-site"
  sec_no_headers "$sec_host" "/" "unsafe-eval" "^x-powered-by:" "^server:.*[0-9]" "^x-pingback:"
  sec_script_nonces "$sec_host" "/"
  check "$sec_host" "/.well-known/security.txt" 200 "Contact: " "Expires: " "Canonical: http"
  sec_headers "$sec_host" "/.well-known/security.txt" "^content-type: text/plain"
  sec_absent "$sec_host" "/?author=1" 404 "/author/" "${WP_ADMIN_USER:-tmcadmin}"
  sec_absent "$sec_host" "/wp-json/wp/v2/users" 401 "\"slug\"" "${WP_ADMIN_USER:-tmcadmin}"
  sec_absent "$sec_host" "/?rest_route=/wp/v2/users" 401 "\"slug\"" "${WP_ADMIN_USER:-tmcadmin}"
done
unset sec_host

check "$TMC_BASE_DOMAIN" "/security.txt" 301
sec_headers "$TMC_BASE_DOMAIN" "/wp-login.php" "^content-security-policy:.*object-src 'none'" "^x-frame-options: sameorigin"
sec_xfo="$(sec_fetch "$TMC_BASE_DOMAIN" "/wp-login.php" | grep -ciE '^x-frame-options:' || true)"
sec_result "$TMC_BASE_DOMAIN/wp-login.php (X-Frame-Options once)" "$([ "$sec_xfo" = 1 ] || echo "$sec_xfo X-Frame-Options headers")" "1 header"
unset sec_xfo
sec_absent "$TMC_BASE_DOMAIN" "/wp-json/oembed/1.0/embed?url=http%3A%2F%2F$TMC_BASE_DOMAIN%2Fsitemap%2F" 200 "author_name" "author_url"
sec_absent "$TMC_BASE_DOMAIN" "/wp-sitemap-users-1.xml" 404 "${WP_ADMIN_USER:-tmcadmin}"

# Admin allow-list: a client outside the default private ranges gets 403. The request arrives from
# the Docker gateway / reverse proxy (a trusted proxy for mod_remoteip), which forwards the client
# address in X-Forwarded-For. Only meaningful with the default list.
if [ -z "${TMC_ADMIN_ALLOW_CIDRS:-}" ]; then
  sec_code="$(curl -s -o /dev/null -w '%{http_code}' -m 20 -H "Host: $TMC_BASE_DOMAIN" -H "X-Forwarded-For: 198.51.100.7" "http://127.0.0.1/wp-login.php")"
  sec_result "$TMC_BASE_DOMAIN/wp-login.php (from 198.51.100.7)" "$([ "$sec_code" = 403 ] || echo "HTTP $sec_code (want 403)")" "$sec_code"
  sec_code="$(curl -s -o /dev/null -w '%{http_code}' -m 20 -H "Host: $TMC_BASE_DOMAIN" -H "X-Forwarded-For: 198.51.100.7" "http://127.0.0.1/wp-admin/")"
  sec_result "$TMC_BASE_DOMAIN/wp-admin/ (from 198.51.100.7)" "$([ "$sec_code" = 403 ] || echo "HTTP $sec_code (want 403)")" "$sec_code"
  sec_code="$(curl -s -o /dev/null -w '%{http_code}' -m 20 -H "Host: $TMC_BASE_DOMAIN" -H "X-Forwarded-For: 198.51.100.7" "http://127.0.0.1/wp-admin/admin-ajax.php")"
  sec_result "$TMC_BASE_DOMAIN/admin-ajax.php public (from 198.51.100.7)" "$([ "$sec_code" != 403 ] || echo "HTTP 403: public endpoint blocked")" "$sec_code"
  unset sec_code
fi
