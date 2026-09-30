# Health endpoint used by uptime monitors (docs/operations/monitoring.md). Sourced by smoke-test.sh.
# shellcheck shell=bash

check "$TMC_BASE_DOMAIN" "/wp-json/tmc/v1/health" 200 '"status":"ok"' '"database":{"ok":true}' '"backup":{"ok":true' '"cron":{"ok":true'
if grep -qF -- "$DB_PASSWORD" "$BODY" || grep -qF -- "$TMC_AUDIT_KEY" "$BODY"; then
  printf '  FAIL  %-48s %s\n' "health endpoint" "response contains a secret"
  FAILED=1
fi
check "tmh.$TMC_BASE_DOMAIN" "/wp-json/tmc/v1/health" 200 '"status":"ok"' '"page_cache":{"ok":true}'
