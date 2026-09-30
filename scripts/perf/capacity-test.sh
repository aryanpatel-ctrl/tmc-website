#!/usr/bin/env bash
# Capacity test of a running stack (tender §4.7 R-4.7-9: sustain peak load without degrading agreed
# thresholds; §4.14 R-4.14-4: performance and load testing). k6 (grafana/k6, AGPL-3.0, pinned; a test
# tool only, never deployed) runs in a throw-away container on the stack's own network and talks to
# the WordPress container directly — no published port, no proxy, nothing leaves the host.
#
#   scripts/perf/capacity-test.sh [--rate 100] [--search-rate 5] [--duration 2m] [--out DIR]
#                                 [--p95-pages 800] [--p95-search 3000] [--min-cache-hit 0.9]
#
# Writes DIR/capacity-report.md (also printed; host CPU/memory appended) and DIR/capacity-summary.json.
# Exit status 0 when every threshold holds, non-zero otherwise. Default DIR: reports/capacity.
# Thresholds and how to size production from the result: docs/operations/capacity.md.
set -euo pipefail
cd "$(dirname "$0")/../.."
K6_IMAGE="grafana/k6:1.8.1"   # update via docs/operations/patching.md

RATE=100 SEARCH_RATE=5 DURATION=2m OUT="reports/capacity" P95_PAGES=800 P95_SEARCH=3000 MIN_HIT=0.9
while [ $# -gt 0 ]; do
  case "$1" in
    --rate) RATE="${2:?}"; shift 2 ;;
    --search-rate) SEARCH_RATE="${2:?}"; shift 2 ;;
    --duration) DURATION="${2:?}"; shift 2 ;;
    --out) OUT="${2:?}"; shift 2 ;;
    --p95-pages) P95_PAGES="${2:?}"; shift 2 ;;
    --p95-search) P95_SEARCH="${2:?}"; shift 2 ;;
    --min-cache-hit) MIN_HIT="${2:?}"; shift 2 ;;
    -h|--help) sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
die() { echo "capacity-test: $*" >&2; exit 2; }
for v in "$RATE" "$SEARCH_RATE" "$P95_PAGES" "$P95_SEARCH"; do [[ "$v" =~ ^[0-9]+$ ]] || die "rates and limits must be whole numbers"; done
[[ "$DURATION" =~ ^[0-9]+[smh]$ ]] || die "--duration must look like 90s, 2m or 1h"
[[ "$MIN_HIT" =~ ^(0(\.[0-9]+)?|1(\.0+)?)$ ]] || die "--min-cache-hit must be between 0 and 1"

[ -f .env ] || die "no .env — run scripts/make-env.sh first"
unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES
set -a; . ./.env; set +a

PROJECT="$(docker compose config 2>/dev/null | awk '/^name:/ { print $2; exit }')"
[ -n "$PROJECT" ] || die "cannot resolve the compose project"
WP="$(docker compose ps -q wordpress 2>/dev/null | head -n 1)"
[ -n "$WP" ] || die "the wordpress service is not running"
NET="${PROJECT}_tmc_edge"
IP="$(docker inspect -f "{{with index .NetworkSettings.Networks \"$NET\"}}{{.IPAddress}}{{end}}" "$WP")"
[ -n "$IP" ] || die "wordpress has no address on network $NET"

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
echo "==> capacity test: $RATE page views/s + $SEARCH_RATE searches/s for $DURATION against $PROJECT ($TMC_BASE_DOMAIN → $IP)"
rc=0
docker run --rm --network "$NET" --user "$(id -u):$(id -g)" \
  -v "$PWD/scripts/perf:/scripts:ro" -v "$OUT:/out" \
  -e "BASE_DOMAIN=$TMC_BASE_DOMAIN" -e "TARGET_IP=$IP" -e "RATE=$RATE" -e "SEARCH_RATE=$SEARCH_RATE" \
  -e "DURATION=$DURATION" -e "P95_PAGES_MS=$P95_PAGES" -e "P95_SEARCH_MS=$P95_SEARCH" -e "MIN_CACHE_HIT=$MIN_HIT" \
  -e K6_NO_USAGE_REPORT=true \
  "$K6_IMAGE" run --quiet --no-usage-report /scripts/capacity.js || rc=$?

[ -f "$OUT/capacity-report.md" ] || die "k6 wrote no report (exit $rc)"
cpus="$(nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null || echo '?')"
mem="$(awk '/^MemTotal:/ { printf "%.1f GiB", $2 / 1048576 }' /proc/meminfo 2>/dev/null || true)"
{
  echo "## Test environment"
  echo
  echo "| | |"
  echo "|---|---|"
  echo "| Stack | \`$PROJECT\` (${TMC_ENV:-?}), $TMC_BASE_DOMAIN, all services on one Docker host |"
  echo "| Host | $cpus CPU, ${mem:-memory unknown}, $(uname -sr) |"
  echo "| Code | $(git rev-parse --short HEAD 2>/dev/null || echo unknown) |"
  echo "| Load generator | $K6_IMAGE on the same host (it competes for CPU: results are a lower bound) |"
  echo "| Date | $(date -u '+%Y-%m-%d %H:%M UTC') |"
  echo
  echo "How to read this and size production: docs/operations/capacity.md."
} >> "$OUT/capacity-report.md"
echo "==> report: $OUT/capacity-report.md (k6 exit $rc)"
exit "$rc"
