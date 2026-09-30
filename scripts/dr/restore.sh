#!/usr/bin/env bash
# Restore a TMC backup into a target stack (tender §4.7: documented and tested restore, RTO 1 hour).
#
#   scripts/dr/restore.sh --target DIR [--backup NAME|latest]
#                         [--source-project NAME | --source-volume VOLUME | --source-dir DIR]
#                         [--force] [--skip-files] [--skip-setup]
#   scripts/dr/restore.sh --target DIR --dump FILE.sql.gz [--force]    # database only (e.g. a
#                                                                      # pre-deploy dump for rollback)
#
# DIR is a deployment directory: the code (the release that made the backup, or a newer one) and a
# .env holding that environment's own secrets — secrets are never in backups. Its compose project
# (COMPOSE_PROJECT_NAME in the .env, default tmc-website) is the stack that gets restored.
# The backup comes from the backup volume of --source-project (default tmc-website), from an
# explicit Docker volume, or from a directory (an off-site copy brought back: <dir>/snapshots/<name>).
#
# Steps, each timed ("TIMING <step> <seconds>" lines, used by drill.sh):
#   verify    checksums of the whole snapshot (tmc-backup verify)
#   database  start db + redis; refuse a non-empty database unless --force (then a safety dump is
#             written to DIR/backups/pre-restore-*.sql.gz); recreate the database; import the dump
#   files     uploads, plugins and languages into the target's WordPress volume
#   provision flush Redis; scripts/setup.sh (containers, pinned plugins, migrations, caches)
#   audit     "backup_restored" in the target's tamper-evident audit log
set -euo pipefail

usage() { sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
die() { echo "restore: $*" >&2; exit 1; }

TARGET="" BACKUP="latest" SOURCE_PROJECT="tmc-website" SOURCE_VOLUME="" SOURCE_DIR="" DUMP=""
FORCE=0 SKIP_FILES=0 SKIP_SETUP=0
while [ $# -gt 0 ]; do
  case "$1" in
    --target) TARGET="${2:?}"; shift 2 ;;
    --backup) BACKUP="${2:?}"; shift 2 ;;
    --source-project) SOURCE_PROJECT="${2:?}"; shift 2 ;;
    --source-volume) SOURCE_VOLUME="${2:?}"; shift 2 ;;
    --source-dir) SOURCE_DIR="${2:?}"; shift 2 ;;
    --dump) DUMP="${2:?}"; shift 2 ;;
    --force) FORCE=1; shift ;;
    --skip-files) SKIP_FILES=1; shift ;;
    --skip-setup) SKIP_SETUP=1; shift ;;
    -h|--help) usage 0 ;;
    *) echo "unknown option: $1" >&2; usage 2 ;;
  esac
done
[ -n "$TARGET" ] || usage 2
TARGET="$(cd "$TARGET" && pwd)"
[ -f "$TARGET/.env" ] && [ -f "$TARGET/docker-compose.yml" ] || die "$TARGET needs docker-compose.yml and .env"
command -v docker >/dev/null || die "docker is required"

tenv() { grep -E "^$1=" "$TARGET/.env" | tail -n 1 | cut -d= -f2- || true; }
# docker compose in the target directory, with only the target's .env deciding project and files.
dc() { (cd "$TARGET" && unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES && set -a && . ./.env && set +a && docker compose "$@"); }

DB_NAME="$(tenv DB_NAME)"; DB_USER="$(tenv DB_USER)"; DB_ROOT_PASSWORD="$(tenv DB_ROOT_PASSWORD)"; DOMAIN="$(tenv TMC_BASE_DOMAIN)"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ && "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || die "DB_NAME / DB_USER missing or invalid in $TARGET/.env"
[ -n "$DB_ROOT_PASSWORD" ] && [ -n "$DOMAIN" ] || die "DB_ROOT_PASSWORD / TMC_BASE_DOMAIN missing in $TARGET/.env"
PROJECT="$(dc config 2>/dev/null | awk '/^name:/ { print $2; exit }')"
[ -n "$PROJECT" ] || die "cannot resolve the compose project of $TARGET"
HELPER_IMAGE="tmc-backup:latest"

STEP="" STEP_T=0 T0="$(date +%s)"
step() { STEP="$1"; STEP_T="$(date +%s)"; echo "==> [$1] $2"; }
done_step() { echo "TIMING $STEP $(( $(date +%s) - STEP_T ))"; }
root_sql() { MYSQL_PWD="$DB_ROOT_PASSWORD" dc exec -T -e MYSQL_PWD db mariadb -uroot "$@"; }

step prepare "target $TARGET (project $PROJECT, $DOMAIN)"
if ! docker image inspect "$HELPER_IMAGE" >/dev/null 2>&1; then
  docker build -q -t "$HELPER_IMAGE" "$TARGET/backup" >/dev/null
fi
SRC=()
if [ -z "$DUMP" ]; then
  if [ -n "$SOURCE_DIR" ]; then
    SRC=(-v "$(cd "$SOURCE_DIR" && pwd):/backups:ro")
  else
    if [ -z "$SOURCE_VOLUME" ]; then
      SOURCE_VOLUME="$(docker volume ls -q --filter "label=com.docker.compose.project=$SOURCE_PROJECT" --filter "label=com.docker.compose.volume=backup_data" | head -n 1)"
    fi
    [ -n "$SOURCE_VOLUME" ] || die "no backup volume found for project $SOURCE_PROJECT (use --source-volume or --source-dir)"
    SRC=(-v "$SOURCE_VOLUME:/backups:ro")
  fi
fi
helper() { docker run --rm --user 0 --network none "${SRC[@]}" "$@"; }
done_step

if [ -z "$DUMP" ]; then
  step verify "checksums"
  if [ "$BACKUP" = latest ]; then BACKUP="$(helper "$HELPER_IMAGE" latest)"; fi
  [[ "$BACKUP" =~ ^[0-9]{8}T[0-9]{6}Z$ ]] || die "invalid backup name: $BACKUP"
  helper "$HELPER_IMAGE" verify "$BACKUP"
  BACKUP_ENV="$(helper --entrypoint cat "$HELPER_IMAGE" "/backups/snapshots/$BACKUP/backup.env")"
  BACKUP_DOMAIN="$(sed -n 's/^TMC_BASE_DOMAIN=//p' <<<"$BACKUP_ENV")"
  if [ -n "$BACKUP_DOMAIN" ] && [ "$BACKUP_DOMAIN" != "$DOMAIN" ]; then
    die "backup is for $BACKUP_DOMAIN but the target serves $DOMAIN — set TMC_BASE_DOMAIN=$BACKUP_DOMAIN in $TARGET/.env"
  fi
  echo "   backup $BACKUP, database state of $(sed -n 's/^DB_SNAPSHOT_AT=//p' <<<"$BACKUP_ENV") (Unix time), release $(sed -n 's/^RELEASE=//p' <<<"$BACKUP_ENV")"
  done_step
else
  [ -f "$DUMP" ] || die "no such dump: $DUMP"
  gzip -t "$DUMP" || die "$DUMP is not a valid gzip file"
  BACKUP="$(basename "$DUMP")"
fi

step database "import into $DB_NAME"
dc stop wordpress cron backup >/dev/null 2>&1 || true   # no writes while the database is replaced
dc up -d db redis
for _ in $(seq 1 60); do
  if dc exec -T db healthcheck.sh --connect --innodb_initialized >/dev/null 2>&1; then break; fi
  sleep 2
done
tables="$(root_sql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'" | tr -d '[:space:]')"
if [ "${tables:-0}" != "0" ]; then
  [ "$FORCE" -eq 1 ] || die "target database $DB_NAME has $tables tables — refusing without --force"
  mkdir -p "$TARGET/backups" && chmod 700 "$TARGET/backups"
  safety="$TARGET/backups/pre-restore-$(date +%Y%m%d-%H%M%S).sql.gz"
  (umask 077; root_sql -e "SELECT 1" >/dev/null
   MYSQL_PWD="$DB_ROOT_PASSWORD" dc exec -T -e MYSQL_PWD db mariadb-dump -uroot --single-transaction --quick "$DB_NAME" | gzip > "$safety")
  echo "   safety dump of the current database: $safety"
fi
root_sql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%'; FLUSH PRIVILEGES;"
if [ -z "$DUMP" ]; then
  helper --entrypoint gzip "$HELPER_IMAGE" -dc "/backups/snapshots/$BACKUP/db.sql.gz" | root_sql "$DB_NAME"
else
  gzip -dc "$DUMP" | root_sql "$DB_NAME"
fi
blogs="$(root_sql -N "$DB_NAME" -e "SELECT COUNT(*) FROM tmc_blogs" 2>/dev/null | tr -d '[:space:]' || true)"
[ "${blogs:-0}" -ge 1 ] 2>/dev/null || die "imported database has no multisite sites (tmc_blogs) — wrong dump?"
echo "   $blogs sites restored"
done_step

if [ -z "$DUMP" ] && [ "$SKIP_FILES" -eq 0 ]; then
  step files "uploads, plugins, languages"
  dc up --no-start wordpress >/dev/null
  wp_volume="$(docker volume ls -q --filter "label=com.docker.compose.project=$PROJECT" --filter "label=com.docker.compose.volume=wp_html" | head -n 1)"
  [ -n "$wp_volume" ] || die "WordPress volume of $PROJECT not found"
  helper -v "$wp_volume:/var/www/html" -e "NAME=$BACKUP" --entrypoint bash "$HELPER_IMAGE" -c '
    set -euo pipefail
    for set in uploads plugins languages; do
      mkdir -p "/var/www/html/wp-content/$set"
      rsync -rlt --delete "/backups/snapshots/$NAME/files/$set/" "/var/www/html/wp-content/$set/"
      chown -R 33:33 "/var/www/html/wp-content/$set"
      chmod -R u=rwX,go=rX "/var/www/html/wp-content/$set"
    done
    chown 33:33 /var/www/html /var/www/html/wp-content
    echo "   $(find /var/www/html/wp-content/uploads -type f | wc -l) uploaded files restored"'
  done_step
fi

step provision "flush caches, scripts/setup.sh"
dc exec -T redis valkey-cli FLUSHALL >/dev/null   # object and page caches describe the old data
if [ "$SKIP_SETUP" -eq 0 ]; then
  (cd "$TARGET" && unset COMPOSE_FILE COMPOSE_PROJECT_NAME COMPOSE_PROFILES && ./scripts/setup.sh)
else
  dc up -d
fi
done_step

step audit "record the restore"
# shellcheck disable=SC2016 # PHP code
dc run --rm -T -e "TMC_RESTORED_FROM=$BACKUP" wpcli --url="$DOMAIN" eval \
  'tmc_audit( "backup_restored", array( "user_id" => 0, "user_login" => "restore.sh", "object_type" => "backup", "object_title" => getenv( "TMC_RESTORED_FROM" ) ) ); echo "   recorded in the audit log\n";' </dev/null
done_step

echo "TIMING total $(( $(date +%s) - T0 ))"
echo "==> restored $BACKUP into $PROJECT ($DOMAIN) in $(( $(date +%s) - T0 ))s"
