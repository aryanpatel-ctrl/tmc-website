#!/usr/bin/env bash
# Static checks: PHP syntax (PHP 8.3, in Docker), theme.json, JavaScript, shell scripts.
set -uo pipefail
cd "$(dirname "$0")/.."
FAILED=0

echo "==> PHP syntax (php:8.3-cli)"
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c '
  fail=0
  for f in $(find src scripts -name "*.php"); do
    out=$(php -l "$f" 2>&1) || { echo "$out"; fail=1; }
  done
  exit $fail' || FAILED=1

echo "==> JSON"
for f in src/themes/tmc/theme.json; do
  python3 -m json.tool "$f" >/dev/null || { echo "invalid JSON: $f"; FAILED=1; }
done

echo "==> JavaScript"
if command -v node >/dev/null; then
  for f in $(find src -name "*.js"); do node --check "$f" || FAILED=1; done
else
  echo "   node not installed — skipped"
fi

echo "==> Shell"
if command -v shellcheck >/dev/null; then
  shellcheck -S error scripts/*.sh || FAILED=1
else
  for f in scripts/*.sh; do bash -n "$f" || FAILED=1; done
fi

[ "$FAILED" -eq 0 ] && echo "==> lint passed" || { echo "==> lint FAILED"; exit 1; }
