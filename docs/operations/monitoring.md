# Monitoring and the monthly availability report

Requirements: **R-6-6** (SLA: availability 99.5 % monthly), **R-4.7-6** (backup age visible),
**R-6-5** (monthly support report: incidents, SLA).

## 1. Health endpoint

`GET /wp-json/tmc/v1/health` on any site — public (monitors need no login), never cached (not by the
page cache, not by browsers: `Cache-Control: no-store`), result reused for 10 seconds so a monitor
cannot load the server. It returns **booleans and ages only**: no versions, host names, paths, user
names or secrets (checked by `scripts/tests/health-test.php` and `scripts/smoke.d/health.sh`).

```json
{"status":"ok","time":"2026-09-30T10:00:00Z","checks":{
  "database":{"ok":true}, "redis":{"ok":true}, "object_cache":{"ok":true}, "page_cache":{"ok":true},
  "cron":{"ok":true,"age_seconds":41}, "backup":{"ok":true,"age_seconds":312}, "disk":{"ok":true},
  "offsite":{"ok":true,"age_seconds":1210}}}
```

HTTP **200** when every check passes, **503** when any fails — so a plain HTTP monitor alerts.

| Check | Fails when | Usual cause / first action |
|---|---|---|
| `database` | `SELECT 1` fails | `docker compose ps db`, `docker compose logs db` (the site itself is down too) |
| `redis` | no PONG from Redis | `docker compose ps redis`; the site keeps working without caches, slower |
| `object_cache` | WordPress is not using the Redis object cache | `docker compose run --rm wpcli redis status`; `scripts/setup-cache.sh` |
| `page_cache` | drop-in missing or Redis down | `docker compose run --rm wpcli tmc-cache status`; `scripts/setup-cache.sh` |
| `cron` | the one-minute heartbeat is older than 10 minutes | `docker compose ps cron`, `docker compose logs cron`: scheduled publishing and automatic expiry have stopped |
| `backup` | newest good backup finished more than 30 minutes ago | `docker compose exec backup tmc-backup status`, `docker compose logs backup` — RPO at risk |
| `disk` | less than 10 % free for WordPress files or the backup volume | free space before backups start failing |
| `offsite` (present once an off-host copy has been recorded) | data in the newest verified off-host copy is older than 45 minutes | `backups/offsite.log` on the host; network/SSH to the backup server |

Network Admin → **Health & Backups** shows the same checks, the last 20 backup runs and off-host
copies, and a button to purge the page cache.

Metrics for Prometheus (optional, for node_exporter's textfile collector): the backup volume's
`status/metrics.prom` (`tmc_backup_last_success_timestamp_seconds`, …) and the deployment directory's
`backups/offsite.prom`.

## 2. Uptime Kuma

[Uptime Kuma](https://github.com/louislam/uptime-kuma) (MIT licence, self-hosted) runs **outside the
production host** — on TMC's monitoring server or the DR host, in India — otherwise a host failure
would silence its own monitoring. Pin the version (`louislam/uptime-kuma:2.5.5` at the time of
writing; updates follow [patching.md](patching.md)) and keep its data in a directory so the monthly
report can read it:

```yaml
# compose.yml on the monitoring host
services:
  uptime-kuma:
    image: louislam/uptime-kuma:2.5.5
    restart: unless-stopped
    volumes: ["./kuma-data:/app/data"]
    ports: ["127.0.0.1:3001:3001"]   # publish through TMC's reverse proxy with TLS; admin login + 2FA
```

First start: choose **SQLite** as the database. Settings → Monitor History: keep **400 days** (a full
year of monthly reports can be regenerated). Settings → Notifications: TMC's SMTP relay and SMS
gateway, applied to every monitor below. Settings → General: time zone Asia/Kolkata.

### Monitors to add (production; repeat for UAT with the UAT host names if wanted)

Names matter: the availability report selects monitors by name.

| Name | Type | URL | Keyword / check | Interval | Retries | Notes |
|---|---|---|---|---|---|---|
| `prod tmc home` | HTTP(s) – Keyword | `https://<tmc domain>/` | `Skip to main content` | 60 s | 2 | **SLA monitor.** The keyword proves the theme rendered (not an error page). Certificate expiry notification on |
| `prod tmh home` | HTTP(s) – Keyword | `https://tmh.<tmc domain>/` | `Skip to main content` | 60 s | 2 | SLA monitor |
| `prod hbchrcv home` | HTTP(s) – Keyword | `https://hbchrcv.<tmc domain>/` | `Skip to main content` | 60 s | 2 | SLA monitor |
| `prod mpmmcc home` | HTTP(s) – Keyword | `https://mpmmcc.<tmc domain>/` | `Skip to main content` | 60 s | 2 | SLA monitor |
| `prod hbchrcmzp home` | HTTP(s) – Keyword | `https://hbchrcmzp.<tmc domain>/` | `Skip to main content` | 60 s | 2 | SLA monitor |
| `prod hbchpunjab home` | HTTP(s) – Keyword | `https://hbchpunjab.<tmc domain>/` | `Skip to main content` | 60 s | 2 | SLA monitor |
| `prod tmc hindi` | HTTP(s) – Keyword | `https://<tmc domain>/hi/` | `lang="hi-IN"` | 120 s | 2 | second language up |
| `prod health` | HTTP(s) – Keyword | `https://<tmc domain>/wp-json/tmc/v1/health` | `"status":"ok"` | 60 s | 1 | operations: any check red → alert |
| `prod backup` | HTTP(s) – Json Query | same URL | query `checks.backup.ok`, expected value `true` | 300 s | 0 | accepted status codes `200-299, 503` so it reports only its own check |
| `prod offsite copy` | HTTP(s) – Json Query | same URL | query `checks.offsite.ok`, expected value `true` | 300 s | 0 | as above; add once the off-host copy is set up |
| `prod cron` | HTTP(s) – Json Query | same URL | query `checks.cron.ok`, expected value `true` | 300 s | 0 | as above |
| `prod certificate` | (on `prod tmc home`) | — | Certificate Expiry Notification: 21, 14, 7 days | — | — | TLS renewals are TMC's proxy's job |

"Retries 2" means a failed check is re-tried twice (status *pending*) before Kuma alerts; the
availability report still counts pending time as down, so retries delay alerts but never hide
downtime.

**Maintenance windows:** announce planned work in Kuma (Maintenance → schedule, linked to the SLA
monitors) after TMC has agreed the window. Heartbeats in the window get status *maintenance*; the
report lists them separately and excludes them from the SLA base.

## 3. Monthly availability report

`scripts/sla/availability-report.sh` computes, per SLA monitor, availability = up ÷ (up + down) for
a calendar month in IST, compares it with 99.5 %, lists every incident (first failed check → recovery)
and shows maintenance and monitoring gaps separately. Months with less than 95 % heartbeat coverage
are **INSUFFICIENT DATA**, never a pass. Method and edge cases are tested by
`scripts/tests/shell/availability-report-test.sh`.

On the monitoring host, on the 1st of each month (needs `sqlite3`, `bash`, `awk`):

```bash
cd /srv/uptime-kuma
sqlite3 kuma-data/kuma.db ".backup 'kuma-report.db'"        # consistent copy while Kuma keeps running
month="$(date -d 'last month' +%Y-%m)"
/path/to/repo/scripts/sla/availability-report.sh --month "$month" --kuma-db kuma-report.db \
  --monitor "prod tmc home" --monitor "prod tmh home" --monitor "prod hbchrcv home" \
  --monitor "prod mpmmcc home" --monitor "prod hbchrcmzp home" --monitor "prod hbchpunjab home" \
  --out "availability-$month.md"
rm kuma-report.db
```

Attach `availability-<month>.md` to the monthly support report (R-6-5). Options: `--no-data down`
counts monitoring gaps as downtime (stricter), `--maintenance down` ignores maintenance windows,
`--target` changes the threshold, `--strict` makes the script exit 1 when a monitor misses the target
(for automation).

Other monitoring tools work too: export one CSV per monitor with `time,status` (Unix time or ISO 8601;
status `1/up`, `0/down`, `2/pending`, `3/maintenance`) and pass the files instead of `--kuma-db`.
