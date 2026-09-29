#!/usr/bin/env bash
# Demo accounts for the workflow walkthrough (one per role).
# Passwords are written only to demo-users.txt (chmod 600). Safe to re-run.
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a

wp() { docker compose run --rm -T wpcli "$@" </dev/null; }

umask 077
OUT=demo-users.txt
[ -f "$OUT" ] || printf "# TMC demo accounts — change or delete these after the demo\n%-16s %-22s %-48s %s\n" "USERNAME" "ROLE" "LOGIN URL" "PASSWORD" > "$OUT"

# site slug (empty = main TMC site) | username | WP role | display name
USERS=(
  "|tmcreviewer|editor|TMC Reviewer"
  "|tmceditor|contributor|TMC Content Editor"
  "tmh|tmhadmin|administrator|TMH Site Administrator"
  "tmh|tmhreviewer|editor|TMH Reviewer"
  "tmh|tmheditor|contributor|TMH Content Editor"
)
# Plain function instead of an associative array: macOS ships bash 3.2.
label() {
  case "$1" in
    administrator) echo "Site Administrator" ;;
    editor)        echo "Reviewer / Publisher" ;;
    contributor)   echo "Content Editor" ;;
  esac
}

for entry in "${USERS[@]}"; do
  IFS='|' read -r slug login role name <<<"$entry"
  url="${slug:+$slug.}${TMC_BASE_DOMAIN}"
  if wp user get "$login" --field=ID --url="$TMC_BASE_DOMAIN" >/dev/null 2>&1; then
    echo "==> $login exists"
    continue
  fi
  pass="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 16)"
  wp user create "$login" "$login@example.com" --role="$role" --display_name="$name" --user_pass="$pass" --url="$url" >/dev/null
  printf "%-16s %-22s %-48s %s\n" "$login" "$(label "$role")" "http://$url/wp-admin/" "$pass" >> "$OUT"
  echo "==> created $login ($(label "$role")) on $url"
done
chmod 600 "$OUT"
