#!/usr/bin/env bash
# Pinned WordPress components versus the newest releases on wordpress.org (tender §6, R-6-3).
# Dependabot (.github/dependabot.yml) covers images and GitHub Actions; this covers what it cannot
# read: plugins pinned in scripts/*.sh as NAME_VERSION="x.y.z" (POLYLANG_VERSION → polylang,
# REDIS_CACHE_VERSION → redis-cache) and the WordPress release inside the web image.
#
#   scripts/ops/check-updates.sh [--no-core] [--out FILE]
#
# Prints a Markdown table. Exit status: 0 all current, 3 at least one update available,
# 1 wordpress.org could not be reached. Needs curl and python3 (JSON), docker for the core check.
set -euo pipefail
cd "$(dirname "$0")/../.."

CORE=1 OUT=""
while [ $# -gt 0 ]; do
  case "$1" in
    --no-core) CORE=0; shift ;;
    --out) OUT="${2:?}"; shift 2 ;;
    -h|--help) sed -n '2,10p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

API="https://api.wordpress.org"
BODY="$(mktemp)"
trap 'rm -f "$BODY"' EXIT

# One top-level string field of the JSON document in $BODY (empty when absent or not JSON).
field() {
  python3 - "$1" "$BODY" <<'PY' 2>/dev/null || true
import json, sys
try:
    d = json.load(open(sys.argv[2]))
except ValueError:
    d = {}
v = d.get(sys.argv[1], "") if isinstance(d, dict) else ""
print(v if isinstance(v, str) else "")
PY
}
# GET url into $BODY; prints the HTTP status (000 when unreachable).
fetch() { curl -sS -m 20 -o "$BODY" -w '%{http_code}' "$1" 2>/dev/null || echo 000; }
newer() { # a b → true when b is a newer version than a
  [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" = "$2" ]
}

ROWS=() OUTDATED=0 UNREACHABLE=0
# NAME_VERSION="x.y.z" at the start of a line in any provisioning script.
while IFS=: read -r file line; do
  var="${line%%=*}"
  pinned="$(sed -E 's/^[A-Z0-9_]+="([^"]+)".*/\1/' <<<"$line")"
  slug="$(tr 'A-Z_' 'a-z-' <<<"${var%_VERSION}")"
  [[ "$slug" =~ ^[a-z0-9-]+$ ]] || continue
  status="$(fetch "$API/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=$slug&request%5Bfields%5D%5Bsections%5D=0")"
  if [ "$status" = 404 ]; then continue; fi   # not a wordpress.org plugin (another pinned tool)
  latest="$(field version)"
  if [ "$status" != 200 ] || [ -z "$latest" ]; then
    ROWS+=("| $slug (plugin) | $pinned | ? | wordpress.org unreachable (HTTP $status) | \`$file\` |"); UNREACHABLE=1; continue
  fi
  updated="$(field last_updated | cut -c1-10)"
  if newer "$pinned" "$latest"; then
    ROWS+=("| $slug (plugin) | $pinned | **$latest** (released ${updated:-?}) | update available — raise $var | \`$file\` |"); OUTDATED=1
  else
    ROWS+=("| $slug (plugin) | $pinned | $latest | current | \`$file\` |")
  fi
done < <(grep -HoE '^[A-Z][A-Z0-9_]*_VERSION="[0-9][0-9A-Za-z.+-]*"' scripts/*.sh | sort -u)

if [ "$CORE" -eq 1 ]; then
  base="$(sed -n 's/^FROM[[:space:]]\{1,\}\([^[:space:]]*\).*/\1/p' wordpress/Dockerfile | head -n 1)"
  offer=""
  if [ "$(fetch "$API/core/version-check/1.7/")" = 200 ]; then
    offer="$(python3 -c 'import json, sys; print(json.load(open(sys.argv[1]))["offers"][0]["current"])' "$BODY" 2>/dev/null || true)"
  fi
  image_wp=""
  if command -v docker >/dev/null && [ -n "$base" ]; then
    docker pull -q "$base" >/dev/null 2>&1 || true
    # shellcheck disable=SC2016 # PHP code
    image_wp="$(docker run --rm --entrypoint php "$base" -r 'include "/usr/src/wordpress/wp-includes/version.php"; echo $wp_version;' 2>/dev/null || true)"
  fi
  if [ -z "$offer" ]; then
    ROWS+=("| WordPress core | ${image_wp:-?} (image $base) | ? | wordpress.org unreachable | \`wordpress/Dockerfile\` |"); UNREACHABLE=1
  elif [ -z "$image_wp" ]; then
    ROWS+=("| WordPress core | ? (image $base) | $offer | could not read the image — check by hand | \`wordpress/Dockerfile\` |")
  elif newer "$image_wp" "$offer"; then
    ROWS+=("| WordPress core | $image_wp (image $base) | **$offer** | update available — rebuild when the image has it; minor releases also auto-update (WP_AUTO_UPDATE_CORE) | \`wordpress/Dockerfile\` |"); OUTDATED=1
  else
    ROWS+=("| WordPress core | $image_wp (image $base) | $offer | current | \`wordpress/Dockerfile\` |")
  fi
fi

report() {
  echo "## Pinned WordPress components — $(date -u '+%Y-%m-%d %H:%M UTC')"
  echo
  echo "| Component | In use | Newest | Status | Pinned in |"
  echo "|---|---|---|---|---|"
  if [ "${#ROWS[@]}" -gt 0 ]; then printf '%s\n' "${ROWS[@]}"; fi
  echo
  echo "Process and deadlines (security fixes within 30 days, critical on priority): docs/operations/patching.md."
}
if [ -n "$OUT" ]; then report > "$OUT"; else report; fi
if [ "$UNREACHABLE" -eq 1 ]; then exit 1; fi
if [ "$OUTDATED" -eq 1 ]; then exit 3; fi
exit 0
