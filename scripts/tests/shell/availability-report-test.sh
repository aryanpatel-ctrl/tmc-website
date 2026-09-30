#!/usr/bin/env bash
# Monthly availability report (scripts/sla/availability-report.sh) against synthetic heartbeats with
# known outages, maintenance and monitoring gaps — the expected figures are worked out by hand below.
# No containers needed: bash, awk, python3 (fixtures), sqlite3 (optional, for the Uptime Kuma path).
#
#   scripts/tests/shell/availability-report-test.sh
set -euo pipefail
cd "$(dirname "$0")/../../.."

REPORT=scripts/sla/availability-report.sh
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
FAILED=0
ok()   { printf '  PASS  %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; FAILED=1; }
expect() { # label, fixed string, file
  if grep -qF -- "$2" "$3"; then ok "$1"; else fail "$1 — expected: $2"; grep -E '^\| (tmc|tmh|hbch|Period|Target)' "$3" | head -8 | sed 's/^/        /'; fi
}

# September 2026 in IST: 2026-08-31 18:30 UTC to 2026-09-30 18:30 UTC = 30 days = 43 200 minutes.
python3 - "$WORK" <<'PY'
import sys, datetime as dt, sqlite3, os
work = sys.argv[1]
utc = dt.timezone.utc
ist = dt.timezone(dt.timedelta(hours=5, minutes=30))
start = dt.datetime(2026, 9, 1, tzinfo=ist)
end = dt.datetime(2026, 10, 1, tzinfo=ist)

def beats(state_of, skip=lambda t: False, until=end):
    t = start - dt.timedelta(minutes=1)          # one beat before the month: must be clipped away
    while t < until:
        if not skip(t):
            yield t, state_of(t)
        t += dt.timedelta(minutes=1)

def ist_between(t, a, b):
    return a <= t.astimezone(ist) < b

d = lambda day, h, m: dt.datetime(2026, 9, day, h, m, tzinfo=ist)

# tmc-home: pending 09:58-09:59, down 10:00-11:59 on the 10th (incident 2h 02m); maintenance
# 02:00-02:29 on the 15th (30 min); no heartbeats 00:00-00:59 on the 20th (58 min without data:
# the 23:59 beat vouches for 3 minutes); the beat before the month says "down" but lies outside it.
def a_state(t):
    if t < start: return 0
    if ist_between(t, d(10, 9, 58), d(10, 10, 0)): return 2
    if ist_between(t, d(10, 10, 0), d(10, 12, 0)): return 0
    if ist_between(t, d(15, 2, 0), d(15, 2, 30)): return 3
    return 1
a_skip = lambda t: ist_between(t, d(20, 0, 0), d(20, 1, 0))
a_rows = list(beats(a_state, a_skip))
with open(os.path.join(work, 'tmc-home.csv'), 'w') as f:      # Uptime Kuma style: UTC text, numeric status
    for t, s in a_rows:
        f.write(t.astimezone(utc).strftime('%Y-%m-%d %H:%M:%S.000') + f',{s},msg\n')

# tmh-home: ISO timestamps with Z, text statuses, a header line and one unreadable line;
# down 13:00-16:59 on the 5th (4 h) → below 99.5 %.
def b_state(t):
    return 'DOWN' if ist_between(t, d(5, 13, 0), d(5, 17, 0)) else 'up'
with open(os.path.join(work, 'tmh-home.csv'), 'w') as f:
    f.write('time,status,message\n')
    for i, (t, s) in enumerate(beats(b_state)):
        if t < start: continue
        f.write(t.astimezone(utc).strftime('%Y-%m-%dT%H:%M:%SZ') + f',{s}\n')
        if i == 100: f.write('not-a-time,up\n')

# hbchrcv-home: epoch milliseconds, always up, but the monitor stopped after 20 days.
with open(os.path.join(work, 'hbchrcv-home.csv'), 'w') as f:
    for t, s in beats(lambda t: 1, until=d(21, 0, 0)):
        if t < start: continue
        f.write(f'{int(t.timestamp() * 1000)},{s}\n')

# The same tmc-home data as an Uptime Kuma database (tables as Kuma creates them, reduced).
db = sqlite3.connect(os.path.join(work, 'kuma.db'))
db.execute('CREATE TABLE monitor (id INTEGER PRIMARY KEY, name VARCHAR(150), active BOOLEAN DEFAULT 1)')
db.execute('CREATE TABLE heartbeat (id INTEGER PRIMARY KEY, monitor_id INTEGER, status SMALLINT, msg TEXT, time DATETIME)')
db.executemany('INSERT INTO monitor (id, name, active) VALUES (?, ?, ?)', [(1, 'tmc-home', 1), (2, 'retired', 0)])
db.executemany('INSERT INTO heartbeat (monitor_id, status, msg, time) VALUES (?, ?, ?, ?)',
               [(1, s, '', t.astimezone(utc).strftime('%Y-%m-%d %H:%M:%S.000')) for t, s in a_rows]
               + [(2, 0, '', t.astimezone(utc).strftime('%Y-%m-%d %H:%M:%S.000')) for t, s in a_rows[:100]])
db.commit()
PY

NOW=1790793000   # 2026-09-30T18:30:00Z = 2026-10-01 00:00 IST: September has ended

TMC_REPORT_NOW=$NOW "$REPORT" --month 2026-09 "$WORK/tmc-home.csv" "$WORK/tmh-home.csv" "$WORK/hbchrcv-home.csv" \
  > "$WORK/report.md" 2> "$WORK/stderr"
R="$WORK/report.md"
expect "period is the IST calendar month" "| Period | 2026-09-01 00:00 to 2026-10-01 00:00 (UTC+05:30) |" "$R"
expect "allowed downtime at 99.5 % of 30 days = 3h 36m" "allowed downtime in this period: 3h 36m" "$R"
expect "tmc-home: 2h 02m down (pending counts), 30 min maintenance and 58 min without data excluded → 99.717 % PASS" \
  "| tmc-home | 99.717 % | **PASS** | 2h 02m | 1 | 0h 30m | 0h 58m | 99.87 % | 43140 |" "$R"
expect "tmc-home incident from the first failed check to recovery, in IST" "| tmc-home | 1 | 2026-09-10 09:58:00 | 2026-09-10 12:00:00 | 2h 02m |" "$R"
expect "tmh-home: 4 h outage → 99.444 % FAIL (ISO times, text statuses, header skipped)" \
  "| tmh-home | 99.444 % | **FAIL** | 4h 00m | 1 | 0h 00m | 0h 00m | 100.00 % | 43200 |" "$R"
expect "hbchrcv-home: monitor stopped after 20 days → INSUFFICIENT DATA, never a pass" "| hbchrcv-home | 100.000 % | **INSUFFICIENT DATA** |" "$R"
expect "overall line counts the monitors that do not pass" "| Overall | 2 monitor(s) below target or without enough data |" "$R"
if grep -q "1 unreadable line" "$WORK/stderr"; then ok "unreadable line reported, not counted as up"; else fail "unreadable line not reported"; fi
if grep -q '^| tmc-home | 2 |' "$R"; then fail "the down beat before the month must not make an incident"; else ok "the down beat before the month is clipped away (one incident only)"; fi

TMC_REPORT_NOW=$NOW "$REPORT" --month 2026-09 --no-data down "$WORK/tmc-home.csv" > "$WORK/nodata.md"
expect "--no-data down counts the 58 min gap: 42990 / 43170 → 99.583 %" "| tmc-home | 99.583 % | **PASS** | 3h 00m |" "$WORK/nodata.md"
TMC_REPORT_NOW=$NOW "$REPORT" --month 2026-09 --maintenance down "$WORK/tmc-home.csv" > "$WORK/maint.md"
expect "--maintenance down counts the 30 min window: 42990 / 43142 → 99.648 %" "| tmc-home | 99.648 % | **PASS** | 2h 32m |" "$WORK/maint.md"

TMC_REPORT_NOW=$(( 1788201000 + 10 * 86400 )) "$REPORT" --month 2026-09 "$WORK/tmc-home.csv" > "$WORK/mtd.md"
expect "a running month is reported to date" "# Monthly availability report — 2026-09 (month to date)" "$WORK/mtd.md"
expect "month to date: 10 days, allowed downtime 1h 12m" "allowed downtime in this period: 1h 12m" "$WORK/mtd.md"

if TMC_REPORT_NOW=$NOW "$REPORT" --month 2026-09 --strict "$WORK/tmh-home.csv" > /dev/null; then
  fail "--strict must exit non-zero when a monitor fails"
else
  ok "--strict exits non-zero when the target is missed"
fi
if "$REPORT" --month 2026-13 "$WORK/tmc-home.csv" > /dev/null 2>&1; then fail "invalid month accepted"; else ok "invalid month refused"; fi

if command -v sqlite3 >/dev/null; then
  TMC_REPORT_NOW=$NOW "$REPORT" --month 2026-09 --kuma-db "$WORK/kuma.db" > "$WORK/kuma.md"
  expect "Uptime Kuma database gives the same result as the export" \
    "| tmc-home | 99.717 % | **PASS** | 2h 02m | 1 | 0h 30m | 0h 58m | 99.87 % | 43140 |" "$WORK/kuma.md"
  if grep -q '^| retired |' "$WORK/kuma.md"; then fail "inactive monitor reported"; else ok "inactive monitors are left out"; fi
  if TMC_REPORT_NOW=$NOW "$REPORT" --month 2026-09 --kuma-db "$WORK/kuma.db" --monitor nope > /dev/null 2>&1; then
    fail "unknown --monitor accepted"
  else
    ok "unknown --monitor refused"
  fi
else
  echo "  skip  Uptime Kuma database path (sqlite3 not installed)"
fi

[ "$FAILED" -eq 0 ] && echo "==> availability report: all checks passed" || { echo "==> availability report FAILED"; exit 1; }
