# shellcheck shell=bash disable=SC2034
# (SC2034: FAILED and BODY belong to scripts/smoke-test.sh, which sources this file.)
# W4 smoke checks, sourced by scripts/smoke-test.sh (uses its check function, $BODY and $FAILED).
# Application front ends, location map (no map request before consent), share links, gateway
# refusals; in demo environments (TMC_DEMO=1) also real round trips through the gateway to the mock.

w4_tmh="tmh.${TMC_BASE_DOMAIN}"

w4_absent() { # needle — the last fetched body must NOT contain it
  if grep -qF -- "$1" "$BODY"; then
    printf '  FAIL  %-48s %s\n' "(previous page)" "contains '$1'"
    FAILED=1
  fi
}

w4_post() { # host path content-type data expected-status must-contain [token]
  local host="$1" path="$2" type="$3" data="$4" want="$5" needle="$6" token="${7:-}" got problem=""
  local extra=()
  [ -n "$token" ] && extra=(-H "X-TMC-Token: $token")
  got="$(curl -s -o "$BODY" -w '%{http_code}' -m 20 -H "Host: $host" -H "Content-Type: $type" ${extra[@]+"${extra[@]}"} --data "$data" "${ORIGIN:-http://127.0.0.1}$path")"
  [ "$got" = "$want" ] || problem="HTTP $got (want $want)"
  grep -qF -- "$needle" "$BODY" || problem="${problem:+$problem; }missing '$needle'"
  if [ -n "$problem" ]; then
    printf '  FAIL  %-48s %s\n' "POST $host$path" "$problem"
    FAILED=1
  else
    printf '  ok    %-48s %s\n' "POST $host$path" "$got"
  fi
}

# Blocks are on their pages (form, or "not available" when no service is registered).
check "$w4_tmh" "/patient-care/appointments/" 200 'tmc-app-appointment'
check "$w4_tmh" "/education/results/"         200 'tmc-app-results'
check "$w4_tmh" "/feedback/"                  200 'tmc-app-form'
check "$w4_tmh" "/donate/"                    200 'tmc-app-donate'
check "$w4_tmh" "/hi/daan/"                   200 'tmc-app-donate' 'lang="hi-IN"'

# Location map: address + directions; the OpenStreetMap iframe is only created after consent.
check "$w4_tmh" "/contact-us/"                200 'tmc-map' 'Get directions' 'tmc-map-load' 'approximate location'
w4_absent '<iframe'
check "$w4_tmh" "/hi/sampark/"                200 'tmc-map'
w4_absent '<iframe'

# Share links on news/events/tenders: plain links, no third-party scripts.
check "$TMC_BASE_DOMAIN" "/events/sample-cme-session/" 200 'share-links' 'facebook.com/sharer'
check "$w4_tmh" "/tenders/eoi-website-design-development-service/" 200 'share-links'
w4_absent 'connect.facebook.net'
w4_absent 'platform.twitter.com'

# Gateway: unknown services refused with a generic message; internal actions not exposed.
check "$TMC_BASE_DOMAIN" "/wp-json/tmc/v1/apps/nosuchservice/anything" 404 '"code":"unknown"'

if [ "${TMC_DEMO:-0}" = "1" ]; then
  check "$w4_tmh" "/patient-care/appointments/" 200 'data-tmc-app="appointment"' 'Demo backend.' 'Show available times'
  w4_absent 'tmc-apps-mock'
  check "$w4_tmh" "/feedback/" 200 'data-tmc-app="form"' 'Website feedback'
  check "$w4_tmh" "/wp-json/tmc/v1/apps/appointments/departments" 200 '"ok":true' '"departments"'
  w4_absent 'tmc-apps-mock'
  check "$w4_tmh" "/wp-json/tmc/v1/apps/payments/checkout?order_id=ord_000000000000000000000000" 404 '"code":"unknown"'
  check "$w4_tmh" "/?tmc_demo_checkout=ord_000000000000000000000000" 200 'Demo payment gateway' 'Demonstration only.'
  check "$w4_tmh" "/" 200 'social-links' 'Demo links'

  # Round trip over HTTP (Apache → gateway → mock): JSON API with the page token, and the no-JS form post.
  check "$w4_tmh" "/education/results/" 200 'name="_tmc_token"'
  w4_token="$(grep -o 'name="_tmc_token" value="[^"]*"' "$BODY" | head -n 1 | sed 's/.*value="//; s/"$//')"
  w4_post "$w4_tmh" "/wp-json/tmc/v1/apps/results/lookup" "application/json" '{"roll_number":"DEMO1001","date_of_birth":"2000-01-15"}' 200 'Qualified' "$w4_token"
  w4_absent 'tmc-apps-mock'
  w4_post "$w4_tmh" "/wp-json/tmc/v1/apps/results/lookup" "application/json" '{"roll_number":"DEMO1001","date_of_birth":"2000-01-15"}' 403 '"code":"forbidden"'
  w4_post "$w4_tmh" "/education/results/" "application/x-www-form-urlencoded" "tmc_app=results&tmc_app_service=results&tmc_app_instance=tmc-app-results-1&_tmc_token=${w4_token}&roll_number=DEMO1001&date_of_birth=2000-01-15" 200 'Qualified'
fi
