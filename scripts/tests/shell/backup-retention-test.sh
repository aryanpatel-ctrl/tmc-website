#!/usr/bin/env bash
# Backup retention policy (backup/retention.awk) against an independent Python implementation, over
# 400 days of 15-minute snapshots, plus edge cases. No containers needed: bash, awk, python3.
#
#   scripts/tests/shell/backup-retention-test.sh
set -euo pipefail
cd "$(dirname "$0")/../../.."

AWK_FILE=backup/retention.awk
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
FAILED=0
ok()   { printf '  PASS  %s\n' "$1"; }
fail() { printf '  FAIL  %s\n' "$1"; FAILED=1; }

NOW=1790769600   # 2026-09-30T12:00:00Z (fixed, so the test is deterministic)

python3 - "$NOW" > "$WORK/names" <<'PY'
import sys, datetime as dt
now = dt.datetime.fromtimestamp(int(sys.argv[1]), dt.timezone.utc)
t = now - dt.timedelta(days=400)
t = t.replace(minute=(t.minute // 15) * 15, second=0, microsecond=0)
while t <= now:
    print(t.strftime('%Y%m%dT%H%M%SZ'))
    t += dt.timedelta(minutes=15)
PY

python3 - "$NOW" "$WORK/names" > "$WORK/expected" <<'PY'
import sys, datetime as dt
now = int(sys.argv[1])
now_dt = dt.datetime.fromtimestamp(now, dt.timezone.utc)
names = [l.strip() for l in open(sys.argv[2]) if l.strip()]
seen_day, seen_month = set(), set()
for i, n in enumerate(names):
    t = dt.datetime.strptime(n, '%Y%m%dT%H%M%SZ').replace(tzinfo=dt.timezone.utc)
    age = now - int(t.timestamp())
    first_day, first_month = n[:8] not in seen_day, n[:6] not in seen_month
    seen_day.add(n[:8]); seen_month.add(n[:6])
    month_age = (now_dt.year * 12 + now_dt.month) - (t.year * 12 + t.month)
    if i == len(names) - 1: r = 'latest'
    elif age < 0: r = 'future'
    elif age < 48 * 3600: r = 'recent'
    elif first_day and age < 30 * 86400: r = 'daily'
    elif first_month and month_age < 12: r = 'monthly'
    else: r = ''
    print(f'keep {n} {r}' if r else f'delete {n}')
PY

awk -v now="$NOW" -f "$AWK_FILE" < "$WORK/names" > "$WORK/actual"

if diff -q "$WORK/expected" "$WORK/actual" >/dev/null; then
  ok "awk plan identical to independent implementation ($(wc -l < "$WORK/names" | tr -d ' ') snapshots over 400 days)"
else
  fail "awk plan differs from the reference:"; diff "$WORK/expected" "$WORK/actual" | head -20
fi

count() { grep -c " $1\$" "$WORK/actual" || true; }
recent="$(count recent)"; daily="$(count daily)"; monthly="$(count monthly)"
kept="$(grep -c '^keep ' "$WORK/actual")"
[ "$recent" -ge 191 ] && [ "$recent" -le 192 ] && ok "every 15-minute snapshot of the last 48 h kept ($recent)" || fail "recent kept: $recent (want 191-192)"
[ "$daily" -ge 27 ] && [ "$daily" -le 29 ] && ok "one snapshot per day for the rest of the last 30 days ($daily)" || fail "daily kept: $daily (want 27-29)"
[ "$monthly" -ge 10 ] && [ "$monthly" -le 11 ] && ok "one snapshot per month for the last 12 months ($monthly)" || fail "monthly kept: $monthly (want 10-11)"
[ "$kept" -lt 250 ] && ok "storage bounded: $kept of $(wc -l < "$WORK/names" | tr -d ' ') snapshots kept" || fail "too many kept: $kept"
tail -n 1 "$WORK/actual" | grep -q ' latest$' && ok "newest snapshot always kept" || fail "newest snapshot not kept"
grep -q '^keep 20251001T000000Z monthly$' "$WORK/actual" && ok "first snapshot of the month 12 months ago kept (2025-10-01)" || fail "2025-10-01 monthly snapshot missing"
grep -q '^delete 20250901T000000Z$' "$WORK/actual" && ok "13-month-old monthly snapshot removed (2025-09-01)" || fail "2025-09-01 should be removed"
grep -q '^keep 20260901T000000Z daily$' "$WORK/actual" && ok "day 29 kept as daily (2026-09-01)" || fail "2026-09-01 daily missing"
grep -q '^delete 20260915T001500Z$' "$WORK/actual" && ok "non-first snapshot of an older day removed" || fail "2026-09-15 00:15 should be removed"

# Edge cases: invalid names are never touched, a single snapshot is kept, future dates are kept.
printf '%s\n' 20200101T000000Z lost+found .partial-20260930T120000Z 20261231T000000Z | sort | awk -v now="$NOW" -f "$AWK_FILE" > "$WORK/edge"
grep -q '^delete 20200101T000000Z$' "$WORK/edge" && ok "old snapshot removed when a newer one exists" || fail "edge: old snapshot"
grep -q '^keep 20261231T000000Z latest$' "$WORK/edge" && ok "future-dated snapshot kept" || fail "edge: future snapshot"
! grep -qE 'lost\+found|partial' "$WORK/edge" && ok "names that are not snapshots are ignored (never deleted)" || fail "edge: foreign names"
echo 20200101T000000Z | awk -v now="$NOW" -f "$AWK_FILE" | grep -q '^keep 20200101T000000Z latest$' && ok "the only snapshot is kept however old" || fail "edge: single snapshot"
awk -f "$AWK_FILE" </dev/null 2>/dev/null && fail "missing -v now must fail" || ok "refuses to run without the current time"

[ "$FAILED" -eq 0 ] && echo "==> backup retention: all checks passed" || { echo "==> backup retention FAILED"; exit 1; }
