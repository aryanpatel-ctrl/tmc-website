# Availability of one monitor over one period — used by scripts/sla/availability-report.sh.
#
# Input : "<unix seconds> <state>" per heartbeat, sorted by time; state U (up), D (down),
#         P (pending: failed, being re-checked) or M (maintenance window).
# Vars  : name, start, end (period [start, end) in unix seconds), max_gap (seconds one heartbeat
#         vouches for at most), maint (exclude|up|down), nodata (exclude|down), target (percent),
#         min_coverage (percent), offset (seconds east of UTC for printed times).
# Output: one "ROW|…" line for the summary table (heartbeat count = beats inside the period), then
#         "INC|…" lines, one per incident.
#
# Method: each heartbeat's state holds until the next heartbeat, but for at most max_gap seconds;
# time not vouched for by any heartbeat is "no data" (the monitor itself was not running). Pending
# counts as down (the site failed a check; conservative). Incidents are continuous down/pending
# stretches. Plain POSIX awk.

function add(state, a, b,    len) {
	if (a < start) a = start
	if (b > end) b = end
	if (b <= a) return
	len = b - a
	secs[state] += len
	if (state == "D" || state == "P") {
		if (open_inc && a <= inc_end) { if (b > inc_end) inc_end = b }
		else { close_inc(); open_inc = 1; inc_start = a; inc_end = b }
	} else {
		close_inc()
	}
}

function close_inc() {
	if (!open_inc) return
	n_inc++
	inc_s[n_inc] = inc_start; inc_e[n_inc] = inc_end
	open_inc = 0
}

function days_from_civil(y, m, d,    era, yoe, doy, doe) {
	y -= (m <= 2)
	era = int((y >= 0 ? y : y - 399) / 400)
	yoe = y - era * 400
	doy = int((153 * (m + (m > 2 ? -3 : 9)) + 2) / 5) + d - 1
	doe = yoe * 365 + int(yoe / 4) - int(yoe / 100) + doy
	return era * 146097 + doe - 719468
}

function stamp(t,    days, z, era, doe, yoe, y, doy, mp, m, d, rem) {
	t += offset
	days = int(t / 86400); if (t < 0 && days * 86400 != t) days--
	rem = t - days * 86400
	z = days + 719468
	era = int((z >= 0 ? z : z - 146096) / 146097)
	doe = z - era * 146097
	yoe = int((doe - int(doe / 1460) + int(doe / 36524) - int(doe / 146096)) / 365)
	y = yoe + era * 400
	doy = doe - (365 * yoe + int(yoe / 4) - int(yoe / 100))
	mp = int((5 * doy + 2) / 153)
	d = doy - int((153 * mp + 2) / 5) + 1
	m = mp + (mp < 10 ? 3 : -9)
	y += (m <= 2)
	return sprintf("%04d-%02d-%02d %02d:%02d:%02d", y, m, d, int(rem / 3600), int((rem % 3600) / 60), rem % 60)
}

function dur(s,    h, m) {
	s = int(s + 0.5)
	h = int(s / 3600); m = int((s % 3600) / 60)
	if (s % 60) return sprintf("%dh %02dm %02ds", h, m, s % 60)
	return sprintf("%dh %02dm", h, m)
}

BEGIN { n = 0; n_in = 0; open_inc = 0; n_inc = 0; secs["U"] = secs["D"] = secs["P"] = secs["M"] = 0 }

$1 ~ /^-?[0-9]+$/ && $2 ~ /^[UDPM]$/ { n++; t[n] = $1 + 0; st[n] = $2; if (t[n] >= start && t[n] < end) n_in++ }

END {
	for (i = 1; i <= n; i++) {
		b = t[i] + max_gap
		if (i < n && t[i + 1] < b) b = t[i + 1]
		add(st[i], t[i], b)
	}
	close_inc()

	total = end - start
	covered = secs["U"] + secs["D"] + secs["P"] + secs["M"]
	gap = total - covered
	down = secs["D"] + secs["P"]
	up = secs["U"]
	base = up + down
	if (maint == "up") { up += secs["M"]; base += secs["M"] }
	else if (maint == "down") { down += secs["M"]; base += secs["M"] }
	if (nodata == "down") { down += gap; base += gap }

	coverage = total > 0 ? 100 * covered / total : 0
	if (base <= 0) { avail = "n/a"; result = "INSUFFICIENT DATA" }
	else {
		pct = 100 * up / base
		avail = sprintf("%.3f %%", pct)
		if (coverage < min_coverage) result = "INSUFFICIENT DATA"
		else result = (pct >= target ? "PASS" : "FAIL")
	}
	printf "ROW|%s|%s|%s|%s|%s|%d|%s|%s|%.2f %%|%d\n", name, avail, result, dur(down), dur(secs["M"]), n_inc, dur(gap), dur(total), coverage, n_in
	for (i = 1; i <= n_inc; i++) {
		printf "INC|%s|%d|%s|%s|%s\n", name, i, stamp(inc_s[i]), stamp(inc_e[i]), dur(inc_e[i] - inc_s[i])
	}
}
