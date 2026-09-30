# Retention plan for TMC backup snapshots (see backup/tmc-backup.sh).
#
# Input : snapshot names, one per line, in ascending order: YYYYMMDDTHHMMSSZ (UTC).
# Output: "keep <name> <reason>" or "delete <name>" for every valid name. Other lines are ignored
#         (never deleted).
#
# Policy (tender §4.7 RPO 15 minutes; defaults, overridable with -v):
#   recent_hours=48     keep every snapshot younger than 48 hours (the 15-minute points)
#   daily_days=30       keep the first snapshot of each UTC day for 30 days
#   monthly_months=12   keep the first snapshot of each UTC month for 12 months (this month + 11)
#   The newest snapshot is always kept, and so is anything dated in the future (clock skew).
#
#   ls snapshots | sort | awk -v now="$(date -u +%s)" -f retention.awk
#
# Plain POSIX awk (no gawk extensions): dates are converted with the days-from-civil algorithm.

function days_from_civil(y, m, d,    era, yoe, doy, doe) {
	y -= (m <= 2)
	era = int((y >= 0 ? y : y - 399) / 400)
	yoe = y - era * 400
	doy = int((153 * (m + (m > 2 ? -3 : 9)) + 2) / 5) + d - 1
	doe = yoe * 365 + int(yoe / 4) - int(yoe / 100) + doy
	return era * 146097 + doe - 719468
}

function civil_year_month(days,    z, era, doe, yoe, y, doy, mp, m) {
	z = days + 719468
	era = int((z >= 0 ? z : z - 146096) / 146097)
	doe = z - era * 146097
	yoe = int((doe - int(doe / 1460) + int(doe / 36524) - int(doe / 146096)) / 365)
	y = yoe + era * 400
	doy = doe - (365 * yoe + int(yoe / 4) - int(yoe / 100))
	mp = int((5 * doy + 2) / 153)
	m = mp + (mp < 10 ? 3 : -9)
	y += (m <= 2)
	return y * 12 + (m - 1)
}

function epoch_of(name) {
	return days_from_civil(substr(name, 1, 4) + 0, substr(name, 5, 2) + 0, substr(name, 7, 2) + 0) * 86400 \
		+ (substr(name, 10, 2) + 0) * 3600 + (substr(name, 12, 2) + 0) * 60 + (substr(name, 14, 2) + 0)
}

BEGIN {
	if (now == "") { print "retention.awk: -v now=<unix time> is required" > "/dev/stderr"; exit 2 }
	if (recent_hours == "") recent_hours = 48
	if (daily_days == "") daily_days = 30
	if (monthly_months == "") monthly_months = 12
	now += 0
	now_month = civil_year_month(int(now / 86400))
	n = 0
}

length($0) == 16 && $0 ~ /^[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z$/ {
	names[++n] = $0
}

END {
	for (i = 1; i <= n; i++) {
		name = names[i]
		t = epoch_of(name)
		age = now - t
		day = substr(name, 1, 8)
		month_key = substr(name, 1, 6)
		first_of_day = !(day in seen_day)
		first_of_month = !(month_key in seen_month)
		seen_day[day] = 1
		seen_month[month_key] = 1
		month_age = now_month - ((substr(name, 1, 4) + 0) * 12 + (substr(name, 5, 2) + 0) - 1)

		reason = ""
		if (i == n) reason = "latest"
		else if (age < 0) reason = "future"
		else if (age < recent_hours * 3600) reason = "recent"
		else if (first_of_day && age < daily_days * 86400) reason = "daily"
		else if (first_of_month && month_age < monthly_months) reason = "monthly"

		if (reason == "") print "delete " name
		else print "keep " name " " reason
	}
}
