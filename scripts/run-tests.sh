#!/usr/bin/env bash
# Run every scripts/tests/*-test.php (WP-CLI eval-file) against the TMH site. Used by CI and `make test`.
set -uo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a
FAILED=0
for test in scripts/tests/*-test.php; do
  echo "==> $(basename "$test")"
  if ! docker compose run --rm -T wpcli --url="tmh.${TMC_BASE_DOMAIN}" eval-file - < "$test" 2> >(grep -vE "sendmail|Container " >&2); then
    FAILED=1
  fi
done
[ "$FAILED" -eq 0 ] && echo "==> all test suites passed" || { echo "==> test suites FAILED"; exit 1; }
