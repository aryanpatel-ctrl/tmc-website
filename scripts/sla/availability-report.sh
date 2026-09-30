#!/usr/bin/env bash
# Monthly availability report against the SLA (tender §6: availability 99.5 % per month), from
# uptime-monitor heartbeats. Markdown output for the monthly support report (R-6-5).
#
#   scripts/sla/availability-report.sh --month 2026-09 --kuma-db /path/to/kuma.db [--monitor NAME]…
#   scripts/sla/availability-report.sh --month 2026-09 tmc-home.csv tmh-home.csv …
#
# Inputs (see docs/operations/monitoring.md)
#   --kuma-db FILE     Uptime Kuma's SQLite database (a copy; opened read-only; needs sqlite3).
#                      Every active monitor is reported, or only those named with --monitor.
#   CSV files          one per monitor, "time,status" per heartbeat (a header line is allowed);
#                      the file name (without .csv) is the monitor name. Formats: heartbeats.awk.
# Options
#   --month YYYY-MM         calendar month to report (required)
#   --utc-offset +05:30     time zone of the month boundaries and printed times (default IST)
#   --target 99.5           availability target in percent
#   --max-gap 180           seconds one heartbeat vouches for at most (3 × a 60 s check interval)
#   --maintenance exclude   announced maintenance windows (status 3): exclude from the measured
#                           time (default, as agreed maintenance windows usually are), or count as up/down
#   --no-data exclude       time without heartbeats (monitor down): exclude (default) or count as down
#   --min-coverage 95       below this share of the month with heartbeats the result is INSUFFICIENT DATA
#   --out FILE              write the report there instead of standard output
#   --strict                exit 1 when any monitor is not PASS
#
# A month that has not ended yet is reported up to now ("month to date").
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"

usage() { sed -n '2,27p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
die() { echo "availability-report: $*" >&2; exit 2; }

MONTH="" OFFSET="+05:30" TARGET="99.5" MAX_GAP=180 MAINT="exclude" NODATA="exclude" MIN_COVERAGE=95
OUT="" STRICT=0 KUMA_DB="" NOW="${TMC_REPORT_NOW:-}"   # TMC_REPORT_NOW: fixed "now" for tests
MONITORS=() FILES=()
while [ $# -gt 0 ]; do
  case "$1" in
    --month) MONTH="${2:?}"; shift 2 ;;
    --utc-offset) OFFSET="${2:?}"; shift 2 ;;
    --target) TARGET="${2:?}"; shift 2 ;;
    --max-gap) MAX_GAP="${2:?}"; shift 2 ;;
    --maintenance) MAINT="${2:?}"; shift 2 ;;
    --no-data) NODATA="${2:?}"; shift 2 ;;
    --min-coverage) MIN_COVERAGE="${2:?}"; shift 2 ;;
    --out) OUT="${2:?}"; shift 2 ;;
    --strict) STRICT=1; shift ;;
    --kuma-db) KUMA_DB="${2:?}"; shift 2 ;;
    --monitor) MONITORS+=("${2:?}"); shift 2 ;;
    -h|--help) usage 0 ;;
    -*) echo "unknown option: $1" >&2; usage 2 ;;
    *) FILES+=("$1"); shift ;;
  esac
done

[[ "$MONTH" =~ ^([0-9]{4})-(0[1-9]|1[0-2])$ ]] || die "--month YYYY-MM is required"
YEAR=$((10#${BASH_REMATCH[1]})); MON=$((10#${BASH_REMATCH[2]}))
[[ "$OFFSET" =~ ^([+-])([0-9]{2}):([0-9]{2})$ ]] || die "--utc-offset must look like +05:30"
OFFSET_SECONDS=$(( (10#${BASH_REMATCH[2]} * 3600 + 10#${BASH_REMATCH[3]} * 60) * (${BASH_REMATCH[1]}1) ))
[[ "$TARGET" =~ ^[0-9]{1,3}(\.[0-9]+)?$ ]] || die "--target must be a percentage, e.g. 99.5"
[[ "$MIN_COVERAGE" =~ ^[0-9]{1,3}(\.[0-9]+)?$ ]] || die "--min-coverage must be a percentage"
[[ "$MAX_GAP" =~ ^[0-9]+$ ]] && [ "$MAX_GAP" -gt 0 ] || die "--max-gap must be a number of seconds"
case "$MAINT" in exclude|up|down) ;; *) die "--maintenance must be exclude, up or down" ;; esac
case "$NODATA" in exclude|down) ;; *) die "--no-data must be exclude or down" ;; esac
[ -n "$KUMA_DB" ] || [ "${#FILES[@]}" -gt 0 ] || die "give --kuma-db FILE or one CSV file per monitor"
[ -z "$NOW" ] || [[ "$NOW" =~ ^[0-9]+$ ]] || die "TMC_REPORT_NOW must be Unix seconds"

# Period [START, END) in Unix seconds: the month's boundaries in the chosen time zone.
days_from_civil() { # y m d → days since 1970-01-01
  local y=$1 m=$2 d=$3 era yoe doy doe
  y=$(( y - (m <= 2 ? 1 : 0) ))
  era=$(( (y >= 0 ? y : y - 399) / 400 ))
  yoe=$(( y - era * 400 ))
  doy=$(( (153 * (m + (m > 2 ? -3 : 9)) + 2) / 5 + d - 1 ))
  doe=$(( yoe * 365 + yoe / 4 - yoe / 100 + doy ))
  echo $(( era * 146097 + doe - 719468 ))
}
START=$(( $(days_from_civil "$YEAR" "$MON" 1) * 86400 - OFFSET_SECONDS ))
if [ "$MON" -eq 12 ]; then NEXT_Y=$(( YEAR + 1 )); NEXT_M=1; else NEXT_Y=$YEAR; NEXT_M=$(( MON + 1 )); fi
END=$(( $(days_from_civil "$NEXT_Y" "$NEXT_M" 1) * 86400 - OFFSET_SECONDS ))
NOW="${NOW:-$(date -u +%s)}"
[ "$NOW" -gt "$START" ] || die "$MONTH has not started yet"
PARTIAL=0
if [ "$NOW" -lt "$END" ]; then END="$NOW"; PARTIAL=1; fi

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
NAMES=()   # monitor names, in report order; heartbeats in $WORK/<index>.hb

if [ -n "$KUMA_DB" ]; then
  command -v sqlite3 >/dev/null || die "sqlite3 is needed to read $KUMA_DB"
  [ -r "$KUMA_DB" ] || die "cannot read $KUMA_DB"
  q() { sqlite3 -readonly -batch -noheader -separator $'\t' "$KUMA_DB" "$1"; }
  while IFS=$'\t' read -r id name; do
    [ -n "$id" ] || continue
    if [ "${#MONITORS[@]}" -gt 0 ]; then
      wanted=0
      for m in "${MONITORS[@]}"; do if [ "$m" = "$name" ]; then wanted=1; fi; done
      [ "$wanted" -eq 1 ] || continue
    fi
    [[ "$id" =~ ^[0-9]+$ ]] || continue
    i="${#NAMES[@]}"; NAMES+=("$name")
    # Heartbeat times are stored in UTC ("YYYY-MM-DD HH:MM:SS.sss"). One max-gap before the period
    # is included, so the state at the start of the month is known.
    q "SELECT CAST(strftime('%s', time) AS INTEGER) || ',' || status FROM heartbeat
       WHERE monitor_id = $id AND time >= datetime($(( START - MAX_GAP )), 'unixepoch') AND time < datetime($END, 'unixepoch')
       ORDER BY time" | awk -f "$HERE/heartbeats.awk" | sort -n -k1,1 > "$WORK/$i.hb"
  done < <(q "SELECT id, name FROM monitor WHERE active = 1 ORDER BY id")
  for m in ${MONITORS[@]+"${MONITORS[@]}"}; do
    found=0
    for n in ${NAMES[@]+"${NAMES[@]}"}; do if [ "$n" = "$m" ]; then found=1; fi; done
    [ "$found" -eq 1 ] || die "no active monitor named '$m' in $KUMA_DB"
  done
fi
for f in ${FILES[@]+"${FILES[@]}"}; do
  [ -r "$f" ] || die "cannot read $f"
  i="${#NAMES[@]}"; name="$(basename "$f")"; NAMES+=("${name%.csv}")
  awk -f "$HERE/heartbeats.awk" "$f" | sort -n -k1,1 > "$WORK/$i.hb"
done
[ "${#NAMES[@]}" -gt 0 ] || die "no monitors to report"

: > "$WORK/results"
for i in "${!NAMES[@]}"; do
  awk -v name="${NAMES[$i]//|//}" -v start="$START" -v end="$END" -v max_gap="$MAX_GAP" -v maint="$MAINT" \
    -v nodata="$NODATA" -v target="$TARGET" -v min_coverage="$MIN_COVERAGE" -v offset="$OFFSET_SECONDS" \
    -f "$HERE/availability.awk" "$WORK/$i.hb" >> "$WORK/results"
done

fmt_time() { # unix seconds → "YYYY-MM-DD HH:MM" in the report's time zone
  local t=$(( $1 + OFFSET_SECONDS ))
  date -u -d "@$t" '+%Y-%m-%d %H:%M' 2>/dev/null || date -u -r "$t" '+%Y-%m-%d %H:%M'
}
allowed="$(awk -v t="$TARGET" -v s="$(( END - START ))" 'BEGIN { a = int((100 - t) / 100 * s + 0.5); printf "%dh %02dm", int(a / 3600), int((a % 3600) / 60) }')"
failing="$(awk -F'|' '$1 == "ROW" && $4 != "PASS"' "$WORK/results" | wc -l | tr -d ' ')"

report() {
  echo "# Monthly availability report — $MONTH$([ "$PARTIAL" -eq 1 ] && echo ' (month to date)')"
  echo
  echo "| | |"
  echo "|---|---|"
  echo "| Period | $(fmt_time "$START") to $(fmt_time "$END") (UTC$OFFSET) |"
  echo "| Target | availability ≥ $TARGET % per month (tender §6 SLA) — allowed downtime in this period: $allowed |"
  echo "| Overall | $([ "$failing" -eq 0 ] && echo "every monitor meets the target" || echo "$failing monitor(s) below target or without enough data") |"
  echo "| Source | $([ -n "$KUMA_DB" ] && echo "Uptime Kuma database $(basename "$KUMA_DB")" || echo "monitor exports: ${FILES[*]+${FILES[*]##*/}}") |"
  echo "| Generated | $(date -u '+%Y-%m-%d %H:%M UTC') by scripts/sla/availability-report.sh |"
  echo
  echo "## Summary"
  echo
  echo "| Monitor | Availability | Result | Downtime counted | Incidents | Maintenance ($MAINT) | No data ($NODATA) | Coverage | Heartbeats |"
  echo "|---|---|---|---|---|---|---|---|---|"
  awk -F'|' '$1 == "ROW" { printf "| %s | %s | **%s** | %s | %s | %s | %s | %s | %s |\n", $2, $3, $4, $5, $7, $6, $8, $10, $11 }' "$WORK/results"
  echo
  echo "## Incidents"
  echo
  if grep -q '^INC|' "$WORK/results"; then
    echo "Continuous periods in which a monitor reported the site down or failing (times UTC$OFFSET)."
    echo
    echo "| Monitor | # | Start | End | Duration |"
    echo "|---|---|---|---|---|"
    awk -F'|' '$1 == "INC" { printf "| %s | %s | %s | %s | %s |\n", $2, $3, $4, $5, $6 }' "$WORK/results"
  else
    echo "No incidents in this period."
  fi
  echo
  echo "## Method"
  echo
  echo "- Availability = time up ÷ (time up + time down) × 100, per monitor, over the period above."
  echo "- Each heartbeat's result holds until the next heartbeat, for at most $MAX_GAP s. \"Pending\" (a failed check being re-tried) counts as down."
  echo "- Announced maintenance windows: **$MAINT**$([ "$MAINT" = exclude ] && echo " — removed from the measured time; each window must have been agreed with TMC in advance")."
  echo "- Time without heartbeats (monitoring itself unavailable): **$NODATA**. Below $MIN_COVERAGE % coverage the result is INSUFFICIENT DATA."
  echo "- Monitors, check intervals and where the data comes from: docs/operations/monitoring.md."
}

if [ -n "$OUT" ]; then report > "$OUT"; echo "report written to $OUT" >&2; else report; fi
if [ "$STRICT" -eq 1 ] && [ "$failing" -ne 0 ]; then exit 1; fi
exit 0
