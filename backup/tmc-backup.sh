#!/usr/bin/env bash
# TMC scheduled backups — tender §4.7: RPO 15 minutes; scheduled backup of content, databases and
# configuration with a documented, tested restore (scripts/dr/restore.sh, scripts/dr/drill.sh).
#
# Runs as the "backup" container (backup/Dockerfile, docker-compose.yml). Every
# TMC_BACKUP_INTERVAL_MINUTES (15), aligned to the clock (:00 :15 :30 :45 UTC):
#   1. database   mariadb-dump --single-transaction: one consistent InnoDB snapshot, no table locks,
#                 the site stays online; gzip; integrity (gzip -t) and completeness (dump footer) checked
#   2. files      wp-content/uploads, plugins, languages with rsync --link-dest: files unchanged since
#                 the previous snapshot are hard links, so each snapshot is complete on its own but
#                 only changed files take space (incremental). Taken after the dump, so every file the
#                 database refers to is present
#   3. config     non-secret settings, deployed release, compose/proxy/Apache files, plugin inventory,
#                 wp-config.php with every line that may hold a secret removed. .env is never read
#   4. seal       SHA-256 of every file (files.sha256; only new files are hashed) and SHA256SUMS
#   5. retention  every snapshot for 48 h, first of each day for 30 days, first of each month for
#                 12 months (retention.awk)
#   6. status     status/last-success, status/metrics.prom (backup age), status/backup.log, and one row
#                 per run in <prefix>tmc_backup_log, which WordPress copies into the audit log and
#                 reports in /wp-json/tmc/v1/health
#
# Snapshot layout: /backups/snapshots/<YYYYMMDDTHHMMSSZ>/{db.sql.gz, files/, files.sha256, config/,
#                  backup.env, SHA256SUMS}; /backups/latest → newest snapshot.
#
#   tmc-backup daemon            schedule loop (container default)
#   tmc-backup run [tier]        one backup now (tier: manual, pre-deploy, …)
#   tmc-backup verify [name]     re-check every checksum of a snapshot (default: latest)
#   tmc-backup list | latest | status | health | prune
#   tmc-backup record-offsite ok|failed <name> <started> <finished> <message>
#                                record an off-host copy (scripts/backup/offsite-copy.sh, run on the host)
set -Eeuo pipefail
umask 077

ROOT="${TMC_BACKUP_ROOT:-/backups}"
SNAPSHOTS="$ROOT/snapshots"
STATUS_DIR="$ROOT/status"
SOURCE="${TMC_BACKUP_SOURCE:-/source}"       # WordPress volume, read-only
PROJECT="${TMC_BACKUP_PROJECT:-/project}"    # deployment directory, read-only; only an allow-list is copied
LIB="${TMC_BACKUP_LIB:-/usr/local/lib/tmc-backup}"
INTERVAL_MINUTES="${TMC_BACKUP_INTERVAL_MINUTES:-15}"
MAX_AGE="${TMC_BACKUP_MAX_AGE_SECONDS:-$(( INTERVAL_MINUTES * 120 ))}"   # health: two intervals
KEEP_RECENT_HOURS="${TMC_BACKUP_KEEP_RECENT_HOURS:-48}"
KEEP_DAILY_DAYS="${TMC_BACKUP_KEEP_DAILY_DAYS:-30}"
KEEP_MONTHLY_MONTHS="${TMC_BACKUP_KEEP_MONTHLY_MONTHS:-12}"
DB_HOST="${DB_HOST:-tmc-mariadb}"
TABLE_PREFIX="${TMC_TABLE_PREFIX:-tmc_}"
TABLE="${TABLE_PREFIX}tmc_backup_log"
FILE_SETS=(uploads plugins languages)
NAME_RE='^[0-9]{8}T[0-9]{6}Z$'
SELF="$(readlink -f "$0")"
CNF=""
trap 'if [ -n "${CNF:-}" ]; then rm -f "$CNF"; fi' EXIT

log() {
  local line
  line="$(date -u +%Y-%m-%dT%H:%M:%SZ) tmc-backup: $*"
  printf '%s\n' "$line" >&2
  { printf '%s\n' "$line" >> "$STATUS_DIR/backup.log"; } 2>/dev/null || true
}
die() { log "ERROR: $*"; exit 1; }
num() { if [[ "${1:-}" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '0'; fi; }
field() { sed -n "s/^$1=//p" "$2" 2>/dev/null | head -n 1; }   # read KEY=value without sourcing the file

# ------------------------------------------------------------------------------ database access

db_cnf() {
  if [ -n "$CNF" ]; then return 0; fi
  : "${DB_NAME:?DB_NAME is not set}" "${DB_USER:?DB_USER is not set}" "${DB_PASSWORD:?DB_PASSWORD is not set}"
  [[ "$TABLE_PREFIX" =~ ^[A-Za-z0-9_]+$ ]] || die "invalid TMC_TABLE_PREFIX"
  CNF="$(mktemp /tmp/tmc-backup-cnf.XXXXXX)"
  # Credentials in a 0600 option file, never on the command line (visible in the process list).
  printf '[client]\nhost=%s\nuser=%s\npassword="%s"\n' "$DB_HOST" "$DB_USER" \
    "$(printf '%s' "$DB_PASSWORD" | sed 's/\\/\\\\/g; s/"/\\"/g')" > "$CNF"
}

sql() { mariadb --defaults-extra-file="$CNF" --batch --skip-column-names --database="$DB_NAME" -e "SET time_zone = '+00:00'; $1"; }

wait_for_db() {
  local _
  for _ in $(seq 1 60); do
    if sql "SELECT 1" >/dev/null 2>&1; then return 0; fi
    sleep 2
  done
  return 1
}

ensure_table() {
  sql "CREATE TABLE IF NOT EXISTS \`$TABLE\` (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    started_at datetime NOT NULL,
    finished_at datetime NOT NULL,
    status varchar(16) NOT NULL,
    name varchar(32) NOT NULL DEFAULT '',
    tier varchar(16) NOT NULL DEFAULT '',
    db_bytes bigint unsigned NOT NULL DEFAULT 0,
    files_total int unsigned NOT NULL DEFAULT 0,
    files_new int unsigned NOT NULL DEFAULT 0,
    duration_seconds int unsigned NOT NULL DEFAULT 0,
    snapshots int unsigned NOT NULL DEFAULT 0,
    volume_free_percent tinyint unsigned NOT NULL DEFAULT 0,
    verified tinyint unsigned NOT NULL DEFAULT 0,
    message varchar(255) NOT NULL DEFAULT '',
    audited tinyint unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY status_finished (status, finished_at),
    KEY audited (audited)
  ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
}

# record status name tier started finished db_bytes files_total files_new snapshots free_percent verified message
record() {
  local msg
  msg="$(printf '%s' "${12}" | tr -cd 'A-Za-z0-9 ._:/()=,+-' | cut -c1-250)"
  if ! sql "INSERT INTO \`$TABLE\` (started_at, finished_at, status, name, tier, db_bytes, files_total, files_new,
        duration_seconds, snapshots, volume_free_percent, verified, message)
      VALUES (FROM_UNIXTIME($(num "$4")), FROM_UNIXTIME($(num "$5")), '$1', '$2', '$3', $(num "$6"), $(num "$7"),
        $(num "$8"), $(( $(num "$5") - $(num "$4") )), $(num "$9"), $(num "${10}"), $(num "${11}"), '$msg')" >/dev/null 2>&1; then
    log "warning: could not write the run to $TABLE (database unavailable?)"
  fi
}

# ------------------------------------------------------------------------------ helpers

snapshot_names() {
  local d
  for d in "$SNAPSHOTS"/*; do
    d="${d##*/}"
    if [[ "$d" =~ $NAME_RE ]]; then printf '%s\n' "$d"; fi
  done | sort
}
latest_name() {
  local name
  name="$(snapshot_names | tail -n 1)"
  if [ -z "$name" ]; then return 1; fi
  printf '%s\n' "$name"
}
volume_free_percent() { df -P "$ROOT" | awk 'NR == 2 { gsub("%", "", $5); print 100 - $5 }'; }
last_success_field() { awk -v f="$1" '{ print $f }' "$STATUS_DIR/last-success" 2>/dev/null || true; }

write_metrics() { # last_run_ok
  local finished snapshot_at name size duration
  name="$(last_success_field 1)"
  snapshot_at="$(num "$(last_success_field 2)")"
  finished="$(num "$(last_success_field 3)")"
  size=0; duration=0
  if [ -n "$name" ] && [ -f "$SNAPSHOTS/$name/backup.env" ]; then
    size="$(num "$(field DB_BYTES "$SNAPSHOTS/$name/backup.env")")"
    duration=$(( $(num "$(field FINISHED_AT "$SNAPSHOTS/$name/backup.env")") - $(num "$(field STARTED_AT "$SNAPSHOTS/$name/backup.env")") ))
  fi
  cat > "$STATUS_DIR/metrics.prom.tmp" <<EOF
# HELP tmc_backup_last_success_timestamp_seconds Unix time the newest good backup finished (age = now - value).
# TYPE tmc_backup_last_success_timestamp_seconds gauge
tmc_backup_last_success_timestamp_seconds $finished
# HELP tmc_backup_last_db_snapshot_timestamp_seconds Unix time of the database state in the newest good backup (RPO).
# TYPE tmc_backup_last_db_snapshot_timestamp_seconds gauge
tmc_backup_last_db_snapshot_timestamp_seconds $snapshot_at
# HELP tmc_backup_last_run_success 1 if the most recent run succeeded, 0 if it failed.
# TYPE tmc_backup_last_run_success gauge
tmc_backup_last_run_success $1
# HELP tmc_backup_last_duration_seconds Duration of the newest good backup.
# TYPE tmc_backup_last_duration_seconds gauge
tmc_backup_last_duration_seconds $duration
# HELP tmc_backup_last_db_bytes Compressed size of the newest database dump.
# TYPE tmc_backup_last_db_bytes gauge
tmc_backup_last_db_bytes $size
# HELP tmc_backup_snapshots Snapshots currently retained.
# TYPE tmc_backup_snapshots gauge
tmc_backup_snapshots $(snapshot_names | wc -l | tr -d ' ')
EOF
  mv -f "$STATUS_DIR/metrics.prom.tmp" "$STATUS_DIR/metrics.prom"
}

# ------------------------------------------------------------------------------ one snapshot (child process)

write_config() {
  local dir="$1" key f d slug main version
  {
    echo "# Non-secret settings at backup time. Secrets (.env, salts, passwords, keys) are never backed up:"
    echo "# TMC IT keeps them in its secret store and supplies them when restoring (docs/operations/backup-and-dr.md)."
    for key in TMC_ENV TMC_BASE_DOMAIN TMC_WP_ENVIRONMENT COMPOSE_PROJECT_NAME DB_NAME DB_USER TMC_BACKUP_INTERVAL_MINUTES; do
      printf '%s=%s\n' "$key" "${!key:-}"
    done
    printf 'WORDPRESS_VERSION=%s\n' "$(sed -n "s/^\$wp_version = '\(.*\)';.*/\1/p" "$SOURCE/wp-includes/version.php" 2>/dev/null || true)"
  } > "$dir/settings.env"
  if [ -f "$PROJECT/.deployed" ]; then cp "$PROJECT/.deployed" "$dir/release.txt"; fi
  if [ -f "$PROJECT/.release-history" ]; then tail -n 50 "$PROJECT/.release-history" > "$dir/release-history.txt"; fi
  for f in docker-compose.yml compose.local.yml compose.server.yml compose.prod.yml compose.dr.yml \
           nginx/tmc-website.conf wordpress/apache-tmc.conf wordpress/php.ini; do
    if [ -f "$PROJECT/$f" ]; then
      mkdir -p "$dir/project/$(dirname "$f")"
      cp "$PROJECT/$f" "$dir/project/$f"
    fi
  done
  if [ -r "$SOURCE/wp-config.php" ]; then
    sed -E '/(KEY|SALT|PASSWORD|PASSWD|SECRET|TOKEN|AUTH)/I s#.*#// [line removed by tmc-backup: may contain a secret]#' \
      "$SOURCE/wp-config.php" > "$dir/wp-config.redacted.php"
  fi
  if [ -r "$SOURCE/.htaccess" ]; then cp "$SOURCE/.htaccess" "$dir/htaccess"; fi
  for d in "$SOURCE"/wp-content/plugins/*/; do
    if [ ! -d "$d" ]; then continue; fi
    slug="$(basename "$d")"
    main="$(grep -l -m1 -E '^[[:space:]*]*Plugin Name:' "$d"*.php 2>/dev/null | head -n 1 || true)"
    version=""
    if [ -n "$main" ]; then
      version="$(grep -m1 -E '^[[:space:]*]*Version:' "$main" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r' || true)"
    fi
    printf '%s %s\n' "$slug" "${version:-unknown}"
  done > "$dir/plugins.txt"
  for f in advanced-cache.php object-cache.php db.php sunrise.php; do
    if [ -f "$SOURCE/wp-content/$f" ]; then (cd "$SOURCE/wp-content" && sha256sum "$f"); fi
  done > "$dir/dropins.txt"
}

cmd__snapshot() { # work name tier started previous
  local work="$1" name="$2" tier="$3" started="$4" prev="$5" set rc db_snapshot_at files_total files_new
  local -a link
  step() { printf '%s\n' "$1" > "$work/.step"; }
  mkdir -p "$work/files" "$work/config"
  db_cnf

  step database
  db_snapshot_at="$(date -u +%s)"
  mariadb-dump --defaults-extra-file="$CNF" --single-transaction --quick --hex-blob --triggers \
    --skip-lock-tables --default-character-set=utf8mb4 "$DB_NAME" | gzip -6 > "$work/db.sql.gz"
  step database-verify
  gzip -t "$work/db.sql.gz"
  gzip -dc "$work/db.sql.gz" | tail -n 1 | grep -q '^-- Dump completed'

  step files
  for set in "${FILE_SETS[@]}"; do
    mkdir -p "$work/files/$set"
    if [ ! -d "$SOURCE/wp-content/$set" ]; then continue; fi
    link=()
    if [ -n "$prev" ] && [ -d "$SNAPSHOTS/$prev/files/$set" ]; then link=(--link-dest="$SNAPSHOTS/$prev/files/$set"); fi
    rc=0
    rsync -rltp --delete --chmod=D700,F600 ${link[@]+"${link[@]}"} "$SOURCE/wp-content/$set/" "$work/files/$set/" || rc=$?
    if [ "$rc" -ne 0 ] && [ "$rc" -ne 24 ]; then   # 24 = a file vanished while copying (deleted meanwhile)
      echo "rsync of $set failed with exit code $rc" >&2
      return 1
    fi
  done

  step checksums
  (cd "$work" && find files -type f -links 1 -print0 | xargs -0 -r sha256sum) > "$work/.new.sha256"
  if [ -n "$prev" ] && [ -f "$SNAPSHOTS/$prev/files.sha256" ]; then
    # Hard-linked (unchanged) files keep the hash recorded by the previous snapshot.
    (cd "$work" && find files -type f -links +1 -print) > "$work/.linked.list"
    awk -v missing="$work/.missing.list" '
      NR == FNR { if (substr($0, 65, 2) == "  ") hash[substr($0, 67)] = substr($0, 1, 64); next }
      ($0 in hash) { print hash[$0] "  " $0; next }
      { print > missing }' "$SNAPSHOTS/$prev/files.sha256" "$work/.linked.list" > "$work/.linked.sha256"
    if [ -s "$work/.missing.list" ]; then
      (cd "$work" && tr '\n' '\0' < .missing.list | xargs -0 -r sha256sum) >> "$work/.linked.sha256"
    fi
  else
    (cd "$work" && find files -type f -links +1 -print0 | xargs -0 -r sha256sum) > "$work/.linked.sha256"
  fi
  cat "$work/.new.sha256" "$work/.linked.sha256" > "$work/files.sha256"
  files_total="$(wc -l < "$work/files.sha256" | tr -d ' ')"
  files_new="$(wc -l < "$work/.new.sha256" | tr -d ' ')"
  rm -f "$work/.new.sha256" "$work/.linked.sha256" "$work/.linked.list" "$work/.missing.list"

  step config
  write_config "$work/config"

  step seal
  {
    echo "FORMAT=1"
    echo "NAME=$name"
    echo "TIER=$tier"
    echo "STARTED_AT=$started"
    echo "DB_SNAPSHOT_AT=$db_snapshot_at"
    echo "FINISHED_AT=$(date -u +%s)"
    echo "DB_BYTES=$(stat -c %s "$work/db.sql.gz")"
    echo "FILES_TOTAL=$files_total"
    echo "FILES_NEW=$files_new"
    echo "PREVIOUS=$prev"
    echo "TMC_ENV=${TMC_ENV:-}"
    echo "TMC_BASE_DOMAIN=${TMC_BASE_DOMAIN:-}"
    echo "RELEASE=$(cut -d' ' -f1 "$PROJECT/.deployed" 2>/dev/null || true)"
  } > "$work/backup.env"
  (
    cd "$work"
    { printf '%s\0' db.sql.gz files.sha256 backup.env; find config -type f -print0 | sort -z; } | xargs -0 sha256sum > SHA256SUMS
    sha256sum -c --quiet SHA256SUMS
  )
  rm -f "$work/.step"
}

# ------------------------------------------------------------------------------ commands

cmd_run() {
  local tier="${1:-manual}" started name work prev finished rc env_file snapshots free message
  [[ "$tier" =~ ^[a-z-]{1,16}$ ]] || die "invalid tier: $tier"
  mkdir -p "$SNAPSHOTS" "$STATUS_DIR"
  exec 9>"$ROOT/.lock"
  if ! flock -w 900 9; then log "another backup is still running — skipped"; exec 9>&-; return 1; fi

  db_cnf
  started="$(date -u +%s)"
  if ! wait_for_db; then
    log "FAILED: database $DB_HOST unreachable"
    write_metrics 0
    exec 9>&-
    return 1
  fi
  ensure_table >/dev/null 2>&1 || log "warning: could not create $TABLE"

  name="$(date -u -d "@$started" +%Y%m%dT%H%M%SZ)"
  while [ -e "$SNAPSHOTS/$name" ]; do sleep 1; started="$(date -u +%s)"; name="$(date -u -d "@$started" +%Y%m%dT%H%M%SZ)"; done
  work="$SNAPSHOTS/.partial-$name"
  prev="$(latest_name || true)"
  log "starting $name ($tier)${prev:+, incremental from $prev}"

  rc=0
  "$SELF" __snapshot "$work" "$name" "$tier" "$started" "$prev" || rc=$?
  finished="$(date -u +%s)"
  if [ "$rc" -ne 0 ]; then
    message="failed at step: $(cat "$work/.step" 2>/dev/null || echo unknown)"
    rm -rf -- "$work"
    log "FAILED $name — $message"
    write_metrics 0
    record failed "$name" "$tier" "$started" "$finished" 0 0 0 "$(snapshot_names | wc -l)" "$(volume_free_percent)" 0 "$message"
    exec 9>&-
    return 1
  fi

  mv -T -- "$work" "$SNAPSHOTS/$name"
  ln -sfn "snapshots/$name" "$ROOT/.latest.tmp" && mv -Tf "$ROOT/.latest.tmp" "$ROOT/latest"
  env_file="$SNAPSHOTS/$name/backup.env"
  printf '%s %s %s\n' "$name" "$(field DB_SNAPSHOT_AT "$env_file")" "$finished" > "$STATUS_DIR/last-success"
  cmd_prune
  snapshots="$(snapshot_names | wc -l | tr -d ' ')"
  free="$(volume_free_percent)"
  write_metrics 1
  record success "$name" "$tier" "$started" "$finished" "$(field DB_BYTES "$env_file")" "$(field FILES_TOTAL "$env_file")" \
    "$(field FILES_NEW "$env_file")" "$snapshots" "$free" 1 "database $(field DB_BYTES "$env_file") bytes; $(field FILES_NEW "$env_file") new of $(field FILES_TOTAL "$env_file") files"
  log "OK $name in $(( finished - started ))s: database $(field DB_BYTES "$env_file") bytes, $(field FILES_NEW "$env_file") new / $(field FILES_TOTAL "$env_file") files, $snapshots snapshots kept, ${free}% volume free"
  exec 9>&-
  return 0
}

cmd_prune() {
  local plan action name reason
  plan="$(snapshot_names | awk -v now="$(date -u +%s)" -v recent_hours="$KEEP_RECENT_HOURS" \
    -v daily_days="$KEEP_DAILY_DAYS" -v monthly_months="$KEEP_MONTHLY_MONTHS" -f "$LIB/retention.awk")"
  while read -r action name reason; do
    if [ "$action" = delete ] && [[ "$name" =~ $NAME_RE ]]; then
      rm -rf -- "${SNAPSHOTS:?}/$name"
      log "retention: removed $name"
    fi
  done <<<"$plan"
  find "$SNAPSHOTS" -mindepth 1 -maxdepth 1 -name '.partial-*' -mmin +120 -exec rm -rf -- {} + 2>/dev/null || true
  if [ -f "$STATUS_DIR/backup.log" ] && [ "$(wc -l < "$STATUS_DIR/backup.log")" -gt 20000 ]; then
    tail -n 10000 "$STATUS_DIR/backup.log" > "$STATUS_DIR/backup.log.tmp" && mv -f "$STATUS_DIR/backup.log.tmp" "$STATUS_DIR/backup.log"
  fi
  : "$reason"
}

cmd_verify() {
  local name="${1:-latest}"
  if [ "$name" = latest ]; then name="$(latest_name || true)"; fi
  if ! [[ "$name" =~ $NAME_RE ]] || [ ! -d "$SNAPSHOTS/$name" ]; then die "no such backup: ${name:-none}"; fi
  cd "$SNAPSHOTS/$name"
  sha256sum -c --quiet SHA256SUMS
  sha256sum -c --quiet files.sha256
  gzip -t db.sql.gz
  gzip -dc db.sql.gz | tail -n 1 | grep -q '^-- Dump completed'
  echo "OK $name: SHA256SUMS, $(wc -l < files.sha256 | tr -d ' ') file checksums, database dump complete"
}

cmd_list() {
  local name env now
  now="$(date -u +%s)"
  printf '%-18s %-10s %12s %8s %8s %10s\n' SNAPSHOT TIER DB_BYTES FILES NEW AGE_MIN
  for name in $(snapshot_names); do
    env="$SNAPSHOTS/$name/backup.env"
    printf '%-18s %-10s %12s %8s %8s %10s\n' "$name" "$(field TIER "$env")" "$(field DB_BYTES "$env")" \
      "$(field FILES_TOTAL "$env")" "$(field FILES_NEW "$env")" "$(( (now - $(num "$(field DB_SNAPSHOT_AT "$env")")) / 60 ))"
  done
}

cmd_health() {
  local now last started
  now="$(date -u +%s)"
  last="$(num "$(last_success_field 3)")"
  if [ "$last" -gt 0 ] && [ $(( now - last )) -le "$MAX_AGE" ]; then
    echo "healthy: newest backup finished $(( now - last ))s ago"
    return 0
  fi
  started="$(stat -c %Y "$STATUS_DIR/.started" 2>/dev/null || echo 0)"
  if [ $(( now - started )) -le "$MAX_AGE" ]; then
    echo "starting: first backup pending"
    return 0
  fi
  echo "unhealthy: newest backup is $(( last > 0 ? now - last : -1 ))s old (limit ${MAX_AGE}s)"
  return 1
}

cmd_status() {
  local now last snapshot_at
  now="$(date -u +%s)"
  last="$(num "$(last_success_field 3)")"
  snapshot_at="$(num "$(last_success_field 2)")"
  echo "latest:        $(last_success_field 1)"
  echo "backup age:    $(( last > 0 ? now - last : -1 ))s (finished)"
  echo "data age/RPO:  $(( snapshot_at > 0 ? now - snapshot_at : -1 ))s (database state)"
  echo "snapshots:     $(snapshot_names | wc -l | tr -d ' ')"
  echo "volume free:   $(volume_free_percent)%"
  cmd_health || true
}

# Off-host copies are made from the Docker host (this container has no route out of tmc_internal);
# scripts/backup/offsite-copy.sh reports each one here so it reaches the backup log, the audit log
# and the "offsite" check of /wp-json/tmc/v1/health.
cmd_record_offsite() { # ok|failed name started finished message
  local result="${1:-}" name="${2:-}" started finished status env_file db_bytes=0 files_total=0
  case "$result" in
    ok) status=offsite-ok ;;
    failed) status=offsite-failed ;;
    *) die "usage: tmc-backup record-offsite ok|failed <name> <started> <finished> <message>" ;;
  esac
  if [ -n "$name" ] && ! [[ "$name" =~ $NAME_RE ]]; then die "invalid snapshot name: $name"; fi
  started="$(num "${3:-}")"; finished="$(num "${4:-}")"
  env_file="$SNAPSHOTS/$name/backup.env"
  if [ -n "$name" ] && [ -f "$env_file" ]; then
    db_bytes="$(field DB_BYTES "$env_file")"; files_total="$(field FILES_TOTAL "$env_file")"
  fi
  mkdir -p "$STATUS_DIR"
  db_cnf
  wait_for_db || die "database $DB_HOST unreachable — off-host copy not recorded"
  ensure_table >/dev/null 2>&1 || log "warning: could not create $TABLE"
  record "$status" "$name" offsite "$started" "$finished" "$db_bytes" "$files_total" 0 \
    "$(snapshot_names | wc -l | tr -d ' ')" "$(volume_free_percent)" "$([ "$result" = ok ] && echo 1 || echo 0)" "${5:-}"
  log "off-host copy $result${name:+: $name}${5:+ — $5}"
}

cmd_daemon() {
  local period now last
  [[ "$INTERVAL_MINUTES" =~ ^[0-9]+$ ]] && [ "$INTERVAL_MINUTES" -ge 1 ] || die "invalid TMC_BACKUP_INTERVAL_MINUTES"
  mkdir -p "$SNAPSHOTS" "$STATUS_DIR"
  touch "$STATUS_DIR/.started"
  period=$(( INTERVAL_MINUTES * 60 ))
  log "service started: a backup every ${INTERVAL_MINUTES} min; keep ${KEEP_RECENT_HOURS} h of backups, daily for ${KEEP_DAILY_DAYS} days, monthly for ${KEEP_MONTHLY_MONTHS} months"
  trap 'log "service stopping"; exit 0' TERM INT
  now="$(date -u +%s)"
  last="$(num "$(last_success_field 3)")"
  if [ $(( now - last )) -ge "$period" ]; then
    cmd_run startup || true   # first start, or restarted after a gap: do not wait for the next slot
  fi
  while true; do
    now="$(date -u +%s)"
    sleep $(( (now / period + 1) * period - now )) &
    wait $! || true
    cmd_run scheduled || true
  done
}

usage() { sed -n '2,30p' "$SELF" | sed 's/^# \{0,1\}//'; }

cmd="${1:-daemon}"
if [ "$#" -gt 0 ]; then shift; fi
case "$cmd" in
  daemon)     cmd_daemon ;;
  run)        cmd_run "${1:-manual}" ;;
  __snapshot) cmd__snapshot "$@" ;;
  prune)      mkdir -p "$SNAPSHOTS" "$STATUS_DIR"; cmd_prune ;;
  verify)     cmd_verify "${1:-latest}" ;;
  list)       cmd_list ;;
  latest)     latest_name ;;
  status)     cmd_status ;;
  health)     cmd_health ;;
  record-offsite) cmd_record_offsite "$@" ;;
  help|-h|--help) usage ;;
  *)          usage >&2; exit 2 ;;
esac
