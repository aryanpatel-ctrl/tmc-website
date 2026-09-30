#!/usr/bin/env bash
# Run one quality gate against a running stack (CI job of .github/workflows/quality.yml, or a
# developer machine with the local stack up). Results: $QUALITY_OUT/<gate>/ (default tests/results).
#
#   tests/ci/run-gate.sh e2e|visual|a11y|html|lighthouse|links
#
# The gate's exit code is this script's exit code. A markdown summary of the gate is appended to
# $GITHUB_STEP_SUMMARY when it is set. Requires: node + `npm ci` in tests/ (all gates except
# visual), Playwright browsers (e2e, a11y, lighthouse), Java 11+ (html), Docker (visual, links).
set -uo pipefail
cd "$(dirname "$0")/../.." || exit 2
ROOT="$PWD"
GATE="${1:?usage: run-gate.sh e2e|visual|a11y|html|lighthouse|links}"

# Only the base domain is read from .env (never the secrets).
if [ -z "${TMC_BASE_DOMAIN:-}" ] && [ -f .env ]; then
  TMC_BASE_DOMAIN="$(grep -E '^TMC_BASE_DOMAIN=' .env | tail -n 1 | cut -d= -f2-)"
fi
export TMC_BASE_DOMAIN="${TMC_BASE_DOMAIN:-tmc.localhost}"
export QUALITY_OUT="${QUALITY_OUT:-$ROOT/tests/results}"
mkdir -p "$QUALITY_OUT"

# Pinned Playwright image for visual baselines (same version as tests/package.json).
PLAYWRIGHT_IMAGE="mcr.microsoft.com/playwright:v1.63.0-noble"

summary() {
  if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    (cd tests && node bin/step-summary.js "$1") >> "$GITHUB_STEP_SUMMARY" || true
  fi
}

status=0
case "$GATE" in
  e2e)
    (cd tests && QUALITY_SUITE=e2e npx --no-install playwright test) || status=$?
    (cd tests && node bin/failures.js e2e) || true
    summary e2e
    ;;

  visual)
    # Inside the pinned Playwright container, so fonts and rendering match the committed
    # baselines exactly. Host networking: the stack listens on 127.0.0.1:80, and Chromium
    # resolves *.localhost to the loopback address itself.
    docker run --rm --network host --ipc host \
      -e CI="${CI:-}" -e QUALITY_SUITE=visual -e QUALITY_UPDATE_BASELINES="${QUALITY_UPDATE_BASELINES:-0}" \
      -e TMC_BASE_DOMAIN -e QUALITY_OUT=/work/tests/results -e VISUAL_SITE="${VISUAL_SITE:-hbchrcv}" \
      -e HOME=/tmp -e npm_config_cache=/tmp/.npm \
      --user "$(id -u):$(id -g)" \
      -v "$ROOT:/work" -w /work/tests "$PLAYWRIGHT_IMAGE" \
      bash -c 'npm ci --no-audit --no-fund --loglevel=error && npx --no-install playwright test' || status=$?
    if [ "$QUALITY_OUT" != "$ROOT/tests/results" ] && [ -d "$ROOT/tests/results/visual" ]; then
      mkdir -p "$QUALITY_OUT" && cp -R "$ROOT/tests/results/visual" "$QUALITY_OUT/"
    fi
    (cd tests && node bin/failures.js visual) || true
    summary visual
    ;;

  a11y)
    (cd tests && QUALITY_SUITE=a11y npx --no-install playwright test) || status=$?
    (cd tests && node a11y/report.js) || status=1
    summary a11y
    ;;

  html)
    (cd tests && node html/validate.js) || status=$?
    summary html
    ;;

  lighthouse)
    (cd tests && node lighthouse/run.js) || status=$?
    summary lighthouse
    ;;

  links)
    # Published URL inventory of every site from the database (orphan detection).
    inventory="$QUALITY_OUT/links/inventory"
    mkdir -p "$inventory"
    while read -r id host; do
      tmp="$(mktemp)"
      if docker compose run --rm -T wpcli --url="$host" eval-file - < scripts/published-urls.php > "$tmp" 2>/dev/null \
        && node -e 'const d=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")); if(!Array.isArray(d.urls)) process.exit(1)' "$tmp"; then
        mv "$tmp" "$inventory/published-$id.json"
        echo "inventory $host: $(node -e 'console.log(JSON.parse(require("fs").readFileSync(process.argv[1],"utf8")).urls.length)' "$inventory/published-$id.json") published URLs"
      else
        rm -f "$tmp"
        echo "::warning::could not read the published URL inventory of $host from the database (the crawler falls back to the REST API / sitemap)"
      fi
    done < <(cd tests && node -e 'const s=require("./lib/sites"); for (const site of s.SITES) console.log(site.id, s.host(site))')
    (cd tests && node links/crawl.js) || status=$?
    summary links
    ;;

  *)
    echo "unknown gate: $GATE" >&2
    exit 2
    ;;
esac

exit "$status"
