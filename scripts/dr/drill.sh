#!/usr/bin/env bash
# DR drill (tender §4.7: RPO 15 minutes and RTO 1 hour "demonstrated through periodic drills").
#
# Restores the newest backup of a running stack into an isolated compose project (default tmc-dr:
# own project name, own volumes and networks, own container names, no published ports), proves
# the restored sites work from inside that network, measures RPO and RTO, and writes a Markdown
# report. The source stack is only read (its backup volume); nothing in it is changed.
#
#   scripts/dr/drill.sh [--source DIR] [--dr-dir DIR] [--project tmc-dr] [--backup NAME|latest]
#                       [--source-dir DIR] [--report FILE] [--keep]
#                       [--expect-site PREFIX --expect-post TITLE] [--expect-upload PATH]
#                       [--rpo-target SECONDS] [--rto-target SECONDS]
#
#   --source DIR       deployment directory of the stack being protected (default: this checkout)
#   --dr-dir DIR       where the DR copy is built (default: ~/docker/tmc-dr); re-created every drill
#   --source-dir DIR   use an off-site copy (<dir>/snapshots/<name>) instead of the source's volume
#   --expect-*         content that must exist after the restore (post title on site PREFIX, e.g.
#                      "tmh."; a file relative to wp-content/uploads)
#   --keep             leave the DR stack running for inspection (default: removed afterwards)
#
# Measured:
#   RPO  drill start − database state of the restored backup (data that would have been lost)
#   RTO  drill start → every smoke check passes on the restored stack (service back)
# Exit status 0 only when both objectives are met and every check passes.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
SOURCE="$REPO" DR_DIR="${HOME}/docker/tmc-dr" DR_PROJECT="tmc-dr" BACKUP="latest" SOURCE_DIR="" REPORT=""
KEEP=0 EXPECT_SITE="" EXPECT_POST="" EXPECT_UPLOAD="" RPO_TARGET=900 RTO_TARGET=3600
while [ $# -gt 0 ]; do
  case "$1" in
    --source) SOURCE="${2:?}"; shift 2 ;;
    --dr-dir) DR_DIR="${2:?}"; shift 2 ;;
    --project) DR_PROJECT="${2:?}"; shift 2 ;;
    --backup) BACKUP="${2:?}"; shift 2 ;;
    --source-dir) SOURCE_DIR="${2:?}"; shift 2 ;;
    --report) REPORT="${2:?}"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    --expect-site) EXPECT_SITE="${2?}"; shift 2 ;;
    --expect-post) EXPECT_POST="${2:?}"; shift 2 ;;
    --expect-upload) EXPECT_UPLOAD="${2:?}"; shift 2 ;;
    --rpo-target) RPO_TARGET="${2:?}"; shift 2 ;;
    --rto-target) RTO_TARGET="${2:?}"; shift 2 ;;
    -h|--help) sed -n '2,27p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done
die() { echo "drill: $*" >&2; exit 1; }

T0="$(date +%s)"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
SOURCE="$(cd "$SOURCE" && pwd)"
[ -f "$SOURCE/.env" ] || die "no .env in $SOURCE"
mkdir -p "$DR_DIR"
DR_DIR="$(cd "$DR_DIR" && pwd)"
[ "$DR_DIR" != "$SOURCE" ] || die "--dr-dir must differ from the source directory"
REPORT="${REPORT:-$DR_DIR/reports/dr-drill-$STAMP.md}"
mkdir -p "$(dirname "$REPORT")"
LOG="$(dirname "$REPORT")/dr-drill-$STAMP.log"
: > "$LOG"

senv() { grep -E "^$1=" "$SOURCE/.env" | tail -n 1 | cut -d= -f2- || true; }
compose_in() { local dir="$1"; shift; (cd "$dir" && unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES && set -a && . ./.env && set +a && docker compose "$@"); }
dcdr() { compose_in "$DR_DIR" "$@"; }
rand() { openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c "${1:-28}"; }
fmt() { printf '%dm %02ds' $(( $1 / 60 )) $(( $1 % 60 )); }
utc() { date -u -d "@$1" '+%Y-%m-%d %H:%M:%S UTC' 2>/dev/null || date -u -r "$1" '+%Y-%m-%d %H:%M:%S UTC'; }

SRC_PROJECT="$(compose_in "$SOURCE" config 2>/dev/null | awk '/^name:/ { print $2; exit }')"
[ -n "$SRC_PROJECT" ] || die "cannot resolve the source compose project"
[[ "$DR_PROJECT" =~ ^[a-z0-9][a-z0-9_-]*$ ]] || die "invalid project name: $DR_PROJECT"
case "$DR_PROJECT" in "$SRC_PROJECT"|tmc-website|tmc-prod) die "refusing to use project '$DR_PROJECT' for a drill (it is a live stack name)" ;; esac

TIMELINE=()   # "step|seconds"
mark() { TIMELINE+=("$1|$2"); }
echo "==> DR drill $STAMP: $SRC_PROJECT → $DR_PROJECT ($DR_DIR)" | tee -a "$LOG"

# ---------------------------------------------------------------- 1. backup to restore
t="$(date +%s)"
HELPER=(docker run --rm --user 0 --network none)
if [ -n "$SOURCE_DIR" ]; then
  SOURCE_DIR="$(cd "$SOURCE_DIR" && pwd)"; SRC_MOUNT=(-v "$SOURCE_DIR:/backups:ro"); RESTORE_SRC=(--source-dir "$SOURCE_DIR")
else
  vol="$(docker volume ls -q --filter "label=com.docker.compose.project=$SRC_PROJECT" --filter "label=com.docker.compose.volume=backup_data" | head -n 1)"
  [ -n "$vol" ] || die "no backup volume for project $SRC_PROJECT"
  SRC_MOUNT=(-v "$vol:/backups:ro"); RESTORE_SRC=(--source-volume "$vol")
fi
docker image inspect tmc-backup:latest >/dev/null 2>&1 || docker build -q -t tmc-backup:latest "$SOURCE/backup" >/dev/null
if [ "$BACKUP" = latest ]; then BACKUP="$("${HELPER[@]}" "${SRC_MOUNT[@]}" tmc-backup:latest latest)"; fi
[[ "$BACKUP" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || die "no usable backup found"
BENV="$("${HELPER[@]}" "${SRC_MOUNT[@]}" --entrypoint cat tmc-backup:latest "/backups/snapshots/$BACKUP/backup.env")"
bfield() { sed -n "s/^$1=//p" <<<"$BENV" | head -n 1; }
DB_SNAPSHOT_AT="$(bfield DB_SNAPSHOT_AT)"
[[ "$DB_SNAPSHOT_AT" =~ ^[0-9]+$ ]] || die "backup $BACKUP has no DB_SNAPSHOT_AT"
RPO=$(( T0 - DB_SNAPSHOT_AT ))
# Schedule adherence: largest gap between the snapshots of the last 48 hours (computed with GNU date in the helper).
GAPS="$("${HELPER[@]}" "${SRC_MOUNT[@]}" --entrypoint bash tmc-backup:latest -c '
  for n in $(ls /backups/snapshots | grep -E "^[0-9]{8}T[0-9]{6}Z$" | sort); do
    date -u -d "${n:0:4}-${n:4:2}-${n:6:2} ${n:9:2}:${n:11:2}:${n:13:2}" +%s
  done' | awk -v since=$(( T0 - 172800 )) '$1 >= since { if (prev) { g = $1 - prev; if (g > max) max = g }; prev = $1; n++ } END { printf "%d %d\n", n, max }')"
RECENT_COUNT="${GAPS% *}" MAX_GAP="${GAPS#* }"
mark "Locate newest backup ($BACKUP)" $(( $(date +%s) - t ))

# ---------------------------------------------------------------- 2. isolated DR directory
t="$(date +%s)"
if [ -f "$DR_DIR/.env" ]; then
  old_project="$(grep -E '^COMPOSE_PROJECT_NAME=' "$DR_DIR/.env" | cut -d= -f2- || true)"
  [ "$old_project" = "$DR_PROJECT" ] || die "$DR_DIR/.env belongs to project '${old_project:-?}', not $DR_PROJECT — choose another --dr-dir"
  dcdr down -v --remove-orphans >>"$LOG" 2>&1 || true   # previous drill leftovers: start clean
fi
rsync -a --delete --exclude='.git/' --exclude='.env' --exclude='.env.*' --exclude='backups/' --exclude='reports/' \
  --exclude='demo-users.txt' --exclude='.deployed' --exclude='.release-history' --exclude='.claude/' "$SOURCE/" "$DR_DIR/"
DOMAIN="$(bfield TMC_BASE_DOMAIN)"; DOMAIN="${DOMAIN:-$(senv TMC_BASE_DOMAIN)}"
AUDIT_KEY="$(senv TMC_AUDIT_KEY)"; AUDIT_NOTE="copied from the source environment"
if [ -z "$AUDIT_KEY" ]; then AUDIT_KEY="$(rand 48)"; AUDIT_NOTE="not available — a new key was generated, so the audit chain cannot be verified"; fi
(
  umask 077
  cat > "$DR_DIR/.env" <<EOF
# DR drill environment — generated by scripts/dr/drill.sh; removed with the drill stack.
TMC_ENV=dr
COMPOSE_PROJECT_NAME=$DR_PROJECT
COMPOSE_FILE=docker-compose.yml:compose.dr.yml
TMC_BASE_DOMAIN=$DOMAIN
TMC_WP_ENVIRONMENT=$(senv TMC_WP_ENVIRONMENT)
DB_NAME=$(senv DB_NAME)
DB_USER=$(senv DB_USER)
DB_PASSWORD=$(rand 32)
DB_ROOT_PASSWORD=$(rand 32)
WP_ADMIN_USER=tmcadmin
WP_ADMIN_PASSWORD=$(rand 24)
WP_ADMIN_EMAIL=admin@example.com
TMC_AUDIT_KEY=$AUDIT_KEY
EOF
)
config="$(dcdr config 2>/dev/null)"
[ "$(awk '/^name:/ { print $2; exit }' <<<"$config")" = "$DR_PROJECT" ] || die "DR compose project did not resolve to $DR_PROJECT"
if grep -qE '^[[:space:]]+published:' <<<"$config"; then die "DR configuration publishes ports — refusing (must be isolated)"; fi
if grep -E 'container_name:' <<<"$config" | grep -qvE "container_name: $DR_PROJECT-|container_name: tmc-dr-"; then
  die "DR configuration has container names that are not DR-specific — refusing"
fi
mark "Prepare isolated DR environment" $(( $(date +%s) - t ))

# ---------------------------------------------------------------- 3. restore
t="$(date +%s)"
echo "==> restore (log: $LOG)"
restore_ok=1
"$REPO/scripts/dr/restore.sh" --target "$DR_DIR" --backup "$BACKUP" "${RESTORE_SRC[@]}" >>"$LOG" 2>&1 || restore_ok=0
while read -r _ step secs; do
  case "$step" in verify) mark "Verify backup checksums" "$secs" ;; database) mark "Restore database" "$secs" ;;
    files) mark "Restore files" "$secs" ;; provision) mark "Provision stack (setup.sh)" "$secs" ;; audit) mark "Record restore in audit log" "$secs" ;; esac
done < <(grep -E '^TIMING (verify|database|files|provision|audit) ' "$LOG" || true)
[ "$restore_ok" -eq 1 ] || { tail -n 40 "$LOG" >&2; }

# ---------------------------------------------------------------- 4. smoke test from inside the DR network
t="$(date +%s)"
NET="$(docker network ls -q --filter "label=com.docker.compose.project=$DR_PROJECT" --filter "label=com.docker.compose.network=tmc_edge" | head -n 1)"
smoke_ok=0 SMOKE_OUT=""
if [ "$restore_ok" -eq 1 ] && [ -n "$NET" ]; then
  if SMOKE_OUT="$(docker run --rm --network "$NET" -v "$DR_DIR:/repo:ro" -w /repo -e SMOKE_ORIGIN=http://wordpress \
      tmc-wordpress:latest bash scripts/smoke-test.sh 2>&1)"; then smoke_ok=1; fi
fi
printf '%s\n' "$SMOKE_OUT" >>"$LOG"
T1="$(date +%s)"
RTO=$(( T1 - T0 ))
mark "Smoke test inside the DR network" $(( T1 - t ))

# ---------------------------------------------------------------- 5. verification
t="$(date +%s)"
incurl() { # host path → "status" (body in $CURL_BODY)
  CURL_BODY="$(docker run --rm --network "$NET" tmc-wordpress:latest curl -s -m 30 -H "Host: $1" -w '\n%{http_code}' "http://wordpress$2" 2>/dev/null || true)"
  CURL_STATUS="${CURL_BODY##*$'\n'}"; CURL_BODY="${CURL_BODY%$'\n'*}"
}
CHECKS=()   # "result|check|detail"
check_row() { CHECKS+=("$1|$2|$3"); }
if [ "$restore_ok" -eq 1 ] && [ -n "$NET" ]; then
  check_row "$([ "$smoke_ok" -eq 1 ] && echo PASS || echo FAIL)" "Smoke test (all sites, both languages, health endpoint)" \
    "$(grep -c '^  ok ' <<<"$SMOKE_OUT" || true) passed, $(grep -c '^  FAIL ' <<<"$SMOKE_OUT" || true) failed"
  if [ -n "$EXPECT_POST" ]; then
    # shellcheck disable=SC2016 # PHP code
    path="$(dcdr run --rm -T -e "TMC_EXPECT_TITLE=$EXPECT_POST" wpcli --url="${EXPECT_SITE}${DOMAIN}" eval \
      'global $wpdb; $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_status = %s ORDER BY ID DESC LIMIT 1", getenv( "TMC_EXPECT_TITLE" ), "publish" ) ); echo $id ? wp_parse_url( get_permalink( $id ), PHP_URL_PATH ) : "";' </dev/null 2>>"$LOG" | tr -d '\r' | tail -n 1)"
    if [ -n "$path" ]; then incurl "${EXPECT_SITE}${DOMAIN}" "$path"; fi
    if [ -n "$path" ] && [ "$CURL_STATUS" = 200 ] && grep -qF -- "$EXPECT_POST" <<<"$CURL_BODY"; then
      check_row PASS "Content created before the backup is present" "\"$EXPECT_POST\" at ${EXPECT_SITE}${DOMAIN}$path (HTTP 200)"
    else
      check_row FAIL "Content created before the backup is present" "\"$EXPECT_POST\" not found (path '${path}', HTTP ${CURL_STATUS:-none})"
    fi
  fi
  if [ -n "$EXPECT_UPLOAD" ]; then
    incurl "${EXPECT_SITE}${DOMAIN}" "/wp-content/uploads/$EXPECT_UPLOAD"
    check_row "$([ "$CURL_STATUS" = 200 ] && echo PASS || echo FAIL)" "Uploaded file is present" "/wp-content/uploads/$EXPECT_UPLOAD (HTTP ${CURL_STATUS:-none})"
  fi
  # shellcheck disable=SC2016 # PHP code
  audit="$(dcdr run --rm -T wpcli --url="$DOMAIN" eval '$r = tmc_audit_verify(); echo ( $r["ok"] ? "intact" : "broken at entry " . $r["broken_at"] ) . ", " . $r["count"] . " entries";' </dev/null 2>>"$LOG" | tr -d '\r' | tail -n 1)"
  if [[ "$audit" == intact* ]]; then check_row PASS "Audit log hash chain after restore" "$audit (key $AUDIT_NOTE)"
  elif [[ "$AUDIT_NOTE" == "not available"* ]]; then check_row INFO "Audit log hash chain after restore" "$audit (key $AUDIT_NOTE)"
  else check_row FAIL "Audit log hash chain after restore" "${audit:-no answer} (key $AUDIT_NOTE)"; fi
  incurl "$DOMAIN" "/wp-json/tmc/v1/health"
  check_row "$([ "$CURL_STATUS" = 200 ] && echo PASS || echo FAIL)" "Health endpoint on the restored stack" "HTTP ${CURL_STATUS:-none}: $(tr -d '\n' <<<"$CURL_BODY" | cut -c1-300)"
else
  check_row FAIL "Restore" "restore.sh failed — see the log"
fi
mark "Verification" $(( $(date +%s) - t ))

# ---------------------------------------------------------------- 6. result, report, teardown
RPO_OK=$([ "$RPO" -le "$RPO_TARGET" ] && echo PASS || echo FAIL)
RTO_OK=$([ "$restore_ok" -eq 1 ] && [ "$smoke_ok" -eq 1 ] && [ "$RTO" -le "$RTO_TARGET" ] && echo PASS || echo FAIL)
RESULT=PASS
[ "$RPO_OK" = PASS ] && [ "$RTO_OK" = PASS ] || RESULT=FAIL
for row in "${CHECKS[@]}"; do [ "${row%%|*}" != FAIL ] || RESULT=FAIL; done
if [ "${RECENT_COUNT:-0}" -ge 2 ]; then GAP_TEXT="$(fmt "$MAX_GAP") across $RECENT_COUNT backups"; else GAP_TEXT="not enough history yet ($RECENT_COUNT backup in the last 48 h)"; fi

{
  echo "# DR drill report — $(utc "$T0")"
  echo
  echo "**Result: $RESULT**"
  echo
  echo "| Item | Value |"
  echo "|---|---|"
  echo "| Protected stack | \`$SRC_PROJECT\` ($DOMAIN) on $(hostname) |"
  echo "| DR stack | \`$DR_PROJECT\` — isolated project, own volumes and networks, no published ports |"
  echo "| Backup restored | \`$BACKUP\` ($(bfield TIER)), database state $(utc "$DB_SNAPSHOT_AT") |"
  echo "| Backup size | database $(bfield DB_BYTES) bytes (compressed), $(bfield FILES_TOTAL) files |"
  release="$(bfield RELEASE)"
  echo "| Release in backup | ${release:-not recorded (not a deployed environment)} |"
  echo "| Code used for DR | $(git -C "$SOURCE" rev-parse --short HEAD 2>/dev/null || echo "deployment copy") |"
  echo
  echo "## Objectives"
  echo
  echo "| Objective | Target | Measured | Result |"
  echo "|---|---|---|---|"
  echo "| RPO — data that would have been lost (drill start − database state of newest backup) | ≤ $(fmt "$RPO_TARGET") | $(fmt "$RPO") | $RPO_OK |"
  echo "| RTO — drill start until every smoke check passes on the restored stack | ≤ $(fmt "$RTO_TARGET") | $(fmt "$RTO") | $RTO_OK |"
  echo "| Backup schedule — largest gap between backups, last 48 h | ≤ 15 min + backup time | $GAP_TEXT | INFO |"
  echo
  echo "## Timeline"
  echo
  echo "| Step | Duration |"
  echo "|---|---|"
  for row in "${TIMELINE[@]}"; do echo "| ${row%%|*} | $(fmt "${row##*|}") |"; done
  echo
  echo "## Verification"
  echo
  echo "| Result | Check | Detail |"
  echo "|---|---|---|"
  for row in "${CHECKS[@]}"; do
    r="${row%%|*}"; rest="${row#*|}"
    echo "| $r | ${rest%%|*} | $(sed 's/|/\\|/g' <<<"${rest#*|}") |"
  done
  echo
  echo "## Smoke test output"
  echo
  echo '```'
  printf '%s\n' "${SMOKE_OUT:-not run}"
  echo '```'
  echo
  echo "Full log: \`$(basename "$LOG")\`. Procedure: docs/operations/backup-and-dr.md."
} > "$REPORT"

if [ "$KEEP" -eq 1 ]; then
  echo "==> DR stack kept running: cd $DR_DIR && docker compose ps" | tee -a "$REPORT"
else
  dcdr down -v --remove-orphans >>"$LOG" 2>&1 || true
  printf '\nDR stack and its volumes removed after the drill.\n' >> "$REPORT"
fi

echo "==> RPO $(fmt "$RPO") ($RPO_OK), RTO $(fmt "$RTO") ($RTO_OK) — drill $RESULT"
echo "==> report: $REPORT"
[ "$RESULT" = PASS ]
