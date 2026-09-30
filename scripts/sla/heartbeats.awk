# Normalise a monitor export to "<unix seconds> <state>" lines — used by availability-report.sh.
#
# Input: CSV, one heartbeat per line: time,status[,anything else]. A header line is allowed.
#   time    Unix seconds or milliseconds; or "YYYY-MM-DD HH:MM[:SS[.fff]]" / "YYYY-MM-DDTHH:MM[:SS[.fff]]"
#           with an optional "Z" or "+05:30"-style offset. No offset = UTC (Uptime Kuma stores UTC).
#   status  1/up, 0/down, 2/pending, 3/maintenance (Uptime Kuma's codes and names; any case).
# Lines that cannot be read are counted and reported on stderr (never silently treated as "up").

function days_from_civil(y, m, d,    era, yoe, doy, doe) {
	y -= (m <= 2)
	era = int((y >= 0 ? y : y - 399) / 400)
	yoe = y - era * 400
	doy = int((153 * (m + (m > 2 ? -3 : 9)) + 2) / 5) + d - 1
	doe = yoe * 365 + int(yoe / 4) - int(yoe / 100) + doy
	return era * 146097 + doe - 719468
}

function parse_time(s,    v, z, sign, off, sec) {
	if (s ~ /^[0-9]+(\.[0-9]+)?$/) {
		v = int(s)
		if (v > 100000000000) v = int(v / 1000)   # milliseconds
		return v
	}
	if (s !~ /^[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9][T ][0-9][0-9]:[0-9][0-9]/) return ""
	sec = (substr(s, 17, 1) == ":") ? substr(s, 18, 2) + 0 : 0
	off = 0
	if (match(s, /[+-][0-9][0-9]:?[0-9][0-9]$/)) {
		z = substr(s, RSTART)
		sign = (substr(z, 1, 1) == "-") ? -1 : 1
		gsub(/[^0-9]/, "", z)
		off = sign * ((substr(z, 1, 2) + 0) * 3600 + (substr(z, 3, 2) + 0) * 60)
	}
	return days_from_civil(substr(s, 1, 4) + 0, substr(s, 6, 2) + 0, substr(s, 9, 2) + 0) * 86400 \
		+ (substr(s, 12, 2) + 0) * 3600 + (substr(s, 15, 2) + 0) * 60 + sec - off
}

function state_of(s) {
	s = tolower(s)
	if (s == "1" || s == "up") return "U"
	if (s == "0" || s == "down") return "D"
	if (s == "2" || s == "pending") return "P"
	if (s == "3" || s == "maintenance") return "M"
	return ""
}

BEGIN { FS = ","; bad = 0 }

{
	sub(/\r$/, "")
	if ($0 ~ /^[[:space:]]*$/) next
	time = $1; status = $2
	gsub(/^[[:space:]"]+|[[:space:]"]+$/, "", time)
	gsub(/^[[:space:]"]+|[[:space:]"]+$/, "", status)
	epoch = parse_time(time)
	state = state_of(status)
	if (epoch == "" || state == "") {
		if (FNR == 1) next   # header
		bad++
		next
	}
	print epoch, state
}

END { if (bad) printf "heartbeats.awk: %d unreadable line(s) in %s ignored\n", bad, FILENAME > "/dev/stderr" }
