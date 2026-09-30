#!/usr/bin/env bash
# Off-host copy of TMC backups (tender §4.7: RPO 15 minutes must survive the loss of the whole host;
# all data and backups stay in India). Run on the Docker host of a deployment (UAT, production),
# from cron or a systemd timer, a few minutes after each 15-minute backup:
#
#   5,20,35,50 * * * *  cd /srv/tmc-website-prod && scripts/backup/offsite-copy.sh >> backups/offsite.log 2>&1
#
# What it does
#   1. finds the backup volume of this directory's compose project and its newest snapshot
#   2. copies snapshots/ to TMC's backup target with rsync, keeping the hard links between snapshots
#      (an incremental snapshot costs only its changed files on the target too) and skipping a
#      backup that is still being written (.partial-*)
#   3. proves the newest snapshot arrived intact: an rsync --checksum comparison of every byte
#   4. records the result: the backup log (→ audit log, "offsite" check of /wp-json/tmc/v1/health),
#      backups/offsite.status and backups/offsite.prom (Prometheus textfile) in this directory
#   Exit status is non-zero when the copy failed (cron mails it; the health check turns red).
#
# Configuration — in this directory's .env (never in Git; the backups never contain these values):
#   TMC_OFFSITE_TARGET       user@host:/path  rsync over SSH to TMC's backup server in India, or
#                            /absolute/path   a mounted file system (NFS/SAN share, removable disk)
#   TMC_OFFSITE_SSH_KEY      private key file for the SSH target (chmod 600). On the target, restrict
#                            the key to that directory: command="rrsync /path",restrict ssh-ed25519 …
#   TMC_OFFSITE_KNOWN_HOSTS  known_hosts file holding the target's host key (checking is strict)
#   TMC_OFFSITE_PORT         SSH port (default 22)
#   TMC_OFFSITE_BWLIMIT      optional rsync --bwlimit, KiB/s
#   TMC_OFFSITE_PRUNE        1 (default): snapshots removed here by retention are removed on the target
#                            too, so the target holds the same 48 h / 30 days / 12 months;
#                            0: the target keeps everything and applies its own retention
#
#   scripts/backup/offsite-copy.sh [--target DEST] [--dry-run]
#
# Restore from the copy: scripts/dr/restore.sh --source-dir <copy> … or scripts/dr/drill.sh --source-dir <copy>
# (the copy has the same layout: <copy>/snapshots/<YYYYMMDDTHHMMSSZ>/). See docs/operations/backup-and-dr.md.
set -euo pipefail
cd "$(dirname "$0")/../.."
DEPLOY_DIR="$PWD"

usage() { sed -n '2,33p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }
log() { printf '%s offsite-copy: %s\n' "$(ts)" "$*"; }

TARGET_ARG="" DRY_RUN=0
while [ $# -gt 0 ]; do
  case "$1" in
    --target) TARGET_ARG="${2:?}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage 0 ;;
    *) echo "unknown option: $1" >&2; usage 2 ;;
  esac
done

[ -f .env ] || { log "ERROR: no .env in $DEPLOY_DIR"; exit 1; }
unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES   # only this directory's .env decides
set -a; . ./.env; set +a
command -v docker >/dev/null || { log "ERROR: docker is required"; exit 1; }

TARGET="${TARGET_ARG:-${TMC_OFFSITE_TARGET:-}}"
PRUNE="${TMC_OFFSITE_PRUNE:-1}"
PORT="${TMC_OFFSITE_PORT:-22}"
BWLIMIT="${TMC_OFFSITE_BWLIMIT:-}"
IMAGE="tmc-backup:latest"
NAME_RE='^[0-9]{8}T[0-9]{6}Z$'

mkdir -p backups && chmod 700 backups
STATUS_FILE="backups/offsite.status"
PROM_FILE="backups/offsite.prom"
STARTED="$(date -u +%s)"
LATEST=""

status_field() { sed -n "s/^$1=//p" "$STATUS_FILE" 2>/dev/null | head -n 1; }

# Result for monitoring and the audit trail. Never prints the target's user name or key path.
finish() { # ok|failed message
  local result="$1" message="$2" finished last_ok last_name
  finished="$(date -u +%s)"
  last_ok="$(status_field LAST_SUCCESS)"; last_name="$(status_field LAST_SUCCESS_SNAPSHOT)"
  if [ "$result" = ok ]; then last_ok="$finished"; last_name="$LATEST"; fi
  if [ "$DRY_RUN" -eq 0 ]; then
    (
      umask 077
      {
        echo "LAST_RUN=$finished"
        echo "LAST_RESULT=$result"
        echo "LAST_MESSAGE=$message"
        echo "LAST_SUCCESS=${last_ok:-0}"
        echo "LAST_SUCCESS_SNAPSHOT=${last_name:-}"
        echo "TARGET_KIND=$KIND"
      } > "$STATUS_FILE.tmp" && mv -f "$STATUS_FILE.tmp" "$STATUS_FILE"
      cat > "$PROM_FILE.tmp" <<EOF
# HELP tmc_backup_offsite_last_success_timestamp_seconds Unix time of the newest verified off-host copy.
# TYPE tmc_backup_offsite_last_success_timestamp_seconds gauge
tmc_backup_offsite_last_success_timestamp_seconds ${last_ok:-0}
# HELP tmc_backup_offsite_last_run_success 1 if the most recent off-host copy succeeded, 0 if it failed.
# TYPE tmc_backup_offsite_last_run_success gauge
tmc_backup_offsite_last_run_success $([ "$result" = ok ] && echo 1 || echo 0)
EOF
      mv -f "$PROM_FILE.tmp" "$PROM_FILE"
    )
    if docker compose ps --status running --services 2>/dev/null | grep -qx backup; then
      docker compose exec -T backup tmc-backup record-offsite "$result" "$LATEST" "$STARTED" "$finished" "$message" \
        || log "warning: could not record the result in the backup log"
    else
      log "warning: backup service not running — result not recorded in the backup log"
    fi
  fi
  if [ "$result" = ok ]; then log "OK ${LATEST}: $message"; else log "FAILED: $message"; fi
  [ "$result" = ok ]
}

KIND="none"
case "$TARGET" in
  "") finish failed "TMC_OFFSITE_TARGET is not set (see docs/operations/backup-and-dr.md)"; exit 1 ;;
  /*) KIND="path" ;;
  *:/*) KIND="ssh" ;;
  *) finish failed "TMC_OFFSITE_TARGET must be user@host:/absolute/path or /absolute/path"; exit 1 ;;
esac
TARGET="${TARGET%/}"   # user@host:/ (an rrsync-restricted key) becomes user@host:, i.e. /snapshots under that directory
[ -n "$TARGET" ] || { finish failed "TMC_OFFSITE_TARGET must not be the root directory"; exit 1; }
[[ "$PRUNE" =~ ^[01]$ ]] || { finish failed "TMC_OFFSITE_PRUNE must be 0 or 1"; exit 1; }
[[ "$PORT" =~ ^[0-9]{1,5}$ ]] || { finish failed "TMC_OFFSITE_PORT must be a port number"; exit 1; }
[ -z "$BWLIMIT" ] || [[ "$BWLIMIT" =~ ^[0-9]+$ ]] || { finish failed "TMC_OFFSITE_BWLIMIT must be a number (KiB/s)"; exit 1; }

# One copy at a time (a slow link must not pile up overlapping runs).
if command -v flock >/dev/null; then
  exec 8>backups/.offsite.lock
  if ! flock -n 8; then log "previous copy still running — skipped"; exit 0; fi
fi

PROJECT="$(docker compose config 2>/dev/null | awk '/^name:/ { print $2; exit }')"
[ -n "$PROJECT" ] || { finish failed "cannot resolve the compose project of $DEPLOY_DIR"; exit 1; }
VOLUME="$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.volume=backup_data" | head -n 1)"
[ -n "$VOLUME" ] || { finish failed "no backup volume for project $PROJECT"; exit 1; }
docker image inspect "$IMAGE" >/dev/null 2>&1 || docker compose build -q backup >/dev/null
LATEST="$(docker run --rm --network none -v "$VOLUME:/backups:ro" "$IMAGE" latest 2>/dev/null || true)"
if ! [[ "$LATEST" =~ $NAME_RE ]]; then LATEST=""; finish failed "no snapshot to copy in $VOLUME"; exit 1; fi

# Everything below runs in a throw-away container of the backup image (rsync + OpenSSH client):
# the backup volume is mounted read-only; the key is copied to a private file inside the container.
RUN=(docker run --rm --user 0 -v "$VOLUME:/backups:ro" -e "LATEST=$LATEST" -e "PRUNE=$PRUNE" -e "DRY_RUN=$DRY_RUN" -e "BWLIMIT=$BWLIMIT")
if [ "$KIND" = path ]; then
  mkdir -p "$TARGET"
  TARGET="$(cd "$TARGET" && pwd)"
  RUN+=(--network none -v "$TARGET:/offsite" -e "DEST=/offsite" -e "OWNER=$(id -u):$(id -g)")
else
  key="${TMC_OFFSITE_SSH_KEY:-}" known="${TMC_OFFSITE_KNOWN_HOSTS:-}"
  [ -n "$key" ] && [ -r "$key" ] || { finish failed "TMC_OFFSITE_SSH_KEY is not set or not readable"; exit 1; }
  [ -n "$known" ] && [ -r "$known" ] || { finish failed "TMC_OFFSITE_KNOWN_HOSTS is not set or not readable (host key checking is mandatory)"; exit 1; }
  RUN+=(-v "$(cd "$(dirname "$key")" && pwd)/$(basename "$key"):/run/tmc/key:ro"
        -v "$(cd "$(dirname "$known")" && pwd)/$(basename "$known"):/run/tmc/known_hosts:ro"
        -e "DEST=$TARGET" -e "PORT=$PORT" -e "OWNER=")
fi

log "copying $VOLUME → $KIND target (newest snapshot $LATEST, prune=$PRUNE${BWLIMIT:+, bwlimit=${BWLIMIT} KiB/s}$([ "$DRY_RUN" -eq 1 ] && echo ', dry run'))"
rc=0
out="$("${RUN[@]}" --entrypoint bash "$IMAGE" -c '
  set -euo pipefail
  opts=(-rlptH --numeric-ids --chmod=D700,F600 --partial-dir=.rsync-partial --delay-updates "--exclude=/.partial-*")
  via=()
  if [ -n "$OWNER" ]; then opts+=(--chown="$OWNER"); fi
  if [ "$PRUNE" = 1 ]; then opts+=(--delete-after); fi
  if [ -n "$BWLIMIT" ]; then opts+=(--bwlimit="$BWLIMIT"); fi
  if [ "$DRY_RUN" = 1 ]; then opts+=(--dry-run --itemize-changes); fi
  if [ -f /run/tmc/key ]; then
    install -m 600 /run/tmc/key /tmp/key
    ssh_cmd="ssh -i /tmp/key -p $PORT -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile=/run/tmc/known_hosts -o ConnectTimeout=20 -o ServerAliveInterval=30"
    via=(-e "$ssh_cmd")
  else
    mkdir -p "$DEST/snapshots"
  fi
  rc=0
  rsync "${opts[@]}" "${via[@]}" --stats /backups/snapshots/ "$DEST/snapshots/" > /tmp/rsync.out 2>&1 || rc=$?
  # 24 = files vanished while copying (retention removed an old snapshot meanwhile): not an error.
  if [ "$rc" -ne 0 ] && [ "$rc" -ne 24 ]; then tail -n 5 /tmp/rsync.out; echo "RESULT rsync-exit-$rc"; exit 1; fi
  sent="$(sed -n "s/^Total transferred file size: \([0-9,]*\).*/\1/p" /tmp/rsync.out | tr -d ,)"
  files="$(sed -n "s/^Number of regular files transferred: \([0-9,]*\).*/\1/p" /tmp/rsync.out | tr -d ,)"
  if [ "$DRY_RUN" = 1 ]; then grep -E "^[<>ch*]" /tmp/rsync.out | head -n 50 || true; echo "RESULT dry-run ${files:-0} files"; exit 0; fi
  # Byte-for-byte proof for the newest snapshot: a checksum comparison must find nothing to transfer.
  if ! rsync -rlH --checksum --dry-run --itemize-changes "${via[@]}" "/backups/snapshots/$LATEST/" "$DEST/snapshots/$LATEST/" > /tmp/verify.out 2>&1; then
    tail -n 5 /tmp/verify.out; echo "RESULT verify-failed"; exit 1
  fi
  diff="$(grep -E "^[<>c]" /tmp/verify.out | head -n 5 || true)"
  if [ -n "$diff" ]; then printf "%s\n" "$diff"; echo "RESULT verify-mismatch"; exit 1; fi
  echo "RESULT copied ${files:-0} files (${sent:-0} bytes); newest snapshot verified by checksum"
' 2>&1)" || rc=$?
printf '%s\n' "$out" | grep -v '^RESULT ' | sed 's/^/   /' || true
message="$(printf '%s\n' "$out" | sed -n 's/^RESULT //p' | tail -n 1)"

if [ "$DRY_RUN" -eq 1 ]; then
  log "dry run: ${message:-no result} — nothing copied or recorded"
  exit "$rc"
fi
if [ "$rc" -ne 0 ]; then
  finish failed "${message:-copy failed (exit $rc)}"
  exit 1
fi
finish ok "$message"
