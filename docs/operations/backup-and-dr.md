# Backup, restore and disaster recovery

> **Scope.** Technical reference for the backup service, retention, off-host copy, restore and DR
> tooling as built in this repository. The formal operating procedure (roles, schedule, records) is
> [backup-restore.md](backup-restore.md).

Requirements: **R-4.7-6** (RPO 15 minutes, RTO 1 hour, demonstrated through periodic drills),
**R-4.7-8** (scheduled backup of content, databases and configuration; documented, tested restore),
**R-4.7-3** (separate DR environment). Everything below is built from this repository; nothing is
configured by hand on a server except the secrets named in each step.

| Objective | Target | How it is met | Evidence |
|---|---|---|---|
| RPO (data that can be lost) | ≤ 15 minutes | backup every 15 minutes on the clock; off-host copy 5 minutes after each | `/wp-json/tmc/v1/health` (`backup`, `offsite` ages), drill report |
| RTO (time to service restored) | ≤ 1 hour | scripted restore (`scripts/dr/restore.sh`) into a prepared host | DR drill report (timed, every step) |
| Restore tested | every drill | `scripts/dr/drill.sh`; CI workflow `.github/workflows/dr-drill.yml` monthly | drill report artefact (90 days) |

## 1. What is backed up

The `backup` service (`backup/Dockerfile`, `backup/tmc-backup.sh`) runs next to the database on the
internal network only. Every 15 minutes (:00, :15, :30, :45 UTC) it writes one **snapshot**:

| Part | How | Why it is consistent |
|---|---|---|
| Database (all six sites, all languages, audit log) | `mariadb-dump --single-transaction --quick --hex-blob --triggers` → gzip | one InnoDB transaction: a single point in time, no table locks, sites stay online. Checked with `gzip -t` and the dump's completion footer |
| Uploads, plugins, language packs | `rsync --link-dest` from the WordPress volume | taken **after** the dump, so every file the database refers to exists. Unchanged files are hard links to the previous snapshot: each snapshot is complete, only changed files use space (incremental) |
| Configuration | non-secret settings, deployed release (`.deployed`, release history), compose/proxy/Apache/PHP files, plugin inventory with versions, drop-in checksums, `wp-config.php` with every line that may hold a secret removed | lets a restore prove it uses the same release and configuration |
| Seal | SHA-256 of every file (`files.sha256`) and of the snapshot (`SHA256SUMS`), verified before the run is recorded | a damaged or altered snapshot is detected before it is restored |

**Never in a backup:** `.env`, passwords, WordPress salts, the audit-log HMAC key (`TMC_AUDIT_KEY`),
SSH keys. TMC IT keeps them in its secret store; they are supplied when a stack is rebuilt (section 4).
Code and theme are not backed up either: they are in Git and every release is tagged.

Layout of the backup volume (`backup_data`):

```
/backups/snapshots/20260930T101500Z/   db.sql.gz  files/{uploads,plugins,languages}/  files.sha256
                                       config/  backup.env  SHA256SUMS
/backups/latest -> snapshots/<newest>
/backups/status/  last-success  metrics.prom  backup.log
```

### Retention

| Age | Kept |
|---|---|
| up to 48 hours | every 15-minute snapshot (192) |
| up to 30 days | the first snapshot of each day (UTC) |
| up to 12 months | the first snapshot of each month |
| always | the newest snapshot, however old |

About 240 snapshots at any time; because of the hard links the volume holds each unchanged upload
once. Policy in `backup/retention.awk`, proven over 400 simulated days by
`scripts/tests/shell/backup-retention-test.sh`. Environment variables `TMC_BACKUP_KEEP_RECENT_HOURS`,
`TMC_BACKUP_KEEP_DAILY_DAYS`, `TMC_BACKUP_KEEP_MONTHLY_MONTHS` change it if TMC's policy differs.

### Records and alerts

Each run (success or failure) is a row in `tmc_tmc_backup_log`; the one-minute cron heartbeat copies
it into the tamper-evident audit log as `backup_completed` / `backup_failed`. Network Admin →
**Health & Backups** lists the last 20 runs. `/wp-json/tmc/v1/health` turns `backup` red (HTTP 503)
when the newest good backup is older than 30 minutes, so the uptime monitor alerts
([monitoring.md](monitoring.md)). `status/metrics.prom` exposes the same ages for Prometheus.

### Commands

```bash
docker compose exec backup tmc-backup status          # newest backup, age, RPO, snapshots, free space
docker compose exec backup tmc-backup list            # every snapshot with size and age
docker compose exec backup tmc-backup run manual      # a backup now (also taken before every deploy)
docker compose exec backup tmc-backup verify latest   # re-check every checksum of a snapshot
docker compose logs backup                             # the service log
```

## 2. Off-host copy (TMC's backup target in India)

A backup on the same host does not survive the loss of that host. `scripts/backup/offsite-copy.sh`
runs **on the Docker host** (the backup container has no route out of the internal network) and
copies the snapshots to TMC's backup server, keeping the hard links, skipping a snapshot still being
written, and proving the newest snapshot arrived intact with a byte-for-byte checksum comparison.
The target must be in India (R-4.7-1) and owned by TMC.

One-time setup on the production host (and UAT if wanted):

1. On TMC's backup server create a dedicated account and directory, e.g. `tmcbackup:/srv/tmc-backups`.
   Restrict the key to that directory and to rsync (`rrsync` ships with rsync):
   `command="rrsync /srv/tmc-backups",restrict ssh-ed25519 AAAA… tmc-prod-offsite`.
   With `rrsync` the path in `TMC_OFFSITE_TARGET` is relative to that directory (use `…:/`).
2. On the production host: `ssh-keygen -t ed25519 -N '' -f ~/.ssh/tmc_offsite` (chmod 600) and record
   the backup server's host key: `ssh-keyscan -t ed25519 backup.tmc.example > ~/.ssh/tmc_offsite_known_hosts`
   — then **compare the fingerprint** with the one TMC IT reads from the server console.
3. Add to the deployment's `.env` (never to Git):
   ```
   TMC_OFFSITE_TARGET=tmcbackup@backup.tmc.example:/
   TMC_OFFSITE_SSH_KEY=/home/deploy/.ssh/tmc_offsite
   TMC_OFFSITE_KNOWN_HOSTS=/home/deploy/.ssh/tmc_offsite_known_hosts
   # optional: TMC_OFFSITE_PORT=22  TMC_OFFSITE_BWLIMIT=20000  TMC_OFFSITE_PRUNE=1
   ```
   A mounted file system works too: `TMC_OFFSITE_TARGET=/mnt/tmc-backups` (NFS/SAN share).
4. Schedule it (crontab of the deploy user):
   ```
   5,20,35,50 * * * *  cd /home/deploy/docker/tmc-website-prod && scripts/backup/offsite-copy.sh >> backups/offsite.log 2>&1
   30 2 * * 0          cd /home/deploy/docker/tmc-website-prod && scripts/backup/offsite-copy.sh --full-check >> backups/offsite.log 2>&1
   ```
   The weekly `--full-check` compares every file of every snapshot by checksum and re-sends any that
   differ (repairs a damaged copy). `--dry-run` shows what would be sent.

Results: each run is recorded (`backup_offsite_copied` / `backup_offsite_failed` in the audit log), the
health endpoint gains an `offsite` check (red when the newest verified copy holds data older than 45
minutes), and `backups/offsite.status` / `backups/offsite.prom` hold the last result. With
`TMC_OFFSITE_PRUNE=1` (default) the target keeps the same retention as the host; set `0` if the
target applies its own (e.g. immutable storage with its own lifecycle — recommended against
ransomware, since a compromised production host could otherwise delete remote snapshots too).

## 3. Restore

`scripts/dr/restore.sh` restores one snapshot into a **target stack**: a deployment directory with
the code (the release in the snapshot's `config/release.txt`, or newer) and that environment's own
`.env`. Every step is timed (`TIMING` lines).

```bash
# newest backup of the production stack into the production directory (after an incident)
scripts/dr/restore.sh --target ~/docker/tmc-website-prod --source-project tmc-prod --force

# a chosen point in time
docker compose exec backup tmc-backup list
scripts/dr/restore.sh --target ~/docker/tmc-website-prod --source-project tmc-prod --backup 20260930T101500Z --force

# from the off-host copy (primary host lost): copy the snapshot directory back, then
scripts/dr/restore.sh --target ~/docker/tmc-website-prod --source-dir /mnt/restore/tmc-backups

# database only, from a pre-deploy dump (rollback of a release, see environments.md)
scripts/dr/restore.sh --target ~/docker/tmc-website --dump backups/pre-deploy-20260930-101500-abc1234.sql.gz --force
```

What it does: verifies every checksum → stops WordPress, cron and backup (no writes during the
restore) → refuses a non-empty database unless `--force`, and then first saves a safety dump to
`backups/pre-restore-*.sql.gz` → recreates and imports the database → restores uploads, plugins and
language packs → flushes Redis (object and page caches describe the old data) → runs
`scripts/setup.sh` (containers, pinned plugins, migrations, caches) → records `backup_restored` in the
audit log. The site domain in the backup must match the target's `TMC_BASE_DOMAIN`.

After a restore: check `/wp-json/tmc/v1/health`, run `scripts/smoke-test.sh`, and verify the audit
chain (Network Admin → Audit log → Verify). The chain verifies only with the same `TMC_AUDIT_KEY` as
the environment that wrote it — which is why that key belongs in the secret store.

## 4. Disaster recovery (loss of the production host)

The DR environment is the production configuration (`compose.prod.yml`) on a second TMC host in
India, restored from the off-host copy. Target: service back within **1 hour**.

| # | Step | Command / action | Typical time |
|---|---|---|---|
| 1 | Declare the incident, note the time | incident log (R-6-5) | — |
| 2 | Prepare the DR host (Docker installed beforehand) | `git clone` this repository; `git checkout <release tag in use>` | 2 min |
| 3 | Environment file | `TMC_BASE_DOMAIN=<production domain> scripts/make-env.sh dr`, then replace `DB_*` choices if wanted and **set `TMC_AUDIT_KEY` from the secret store** | 2 min |
| 4 | Bring back the newest off-host copy | mount the backup share, or `rsync -a` its `snapshots/<newest>/` to `/srv/restore/snapshots/<newest>/` (each snapshot is complete on its own) | depends on size |
| 5 | Restore | `scripts/dr/restore.sh --target . --source-dir /srv/restore` | 10–20 min |
| 6 | Check | `/wp-json/tmc/v1/health`, `scripts/smoke-test.sh`, audit chain | 2 min |
| 7 | Switch traffic | TMC's DNS / load balancer to the DR host | per TMC |
| 8 | Protect the new primary | enable its backup schedule and off-host copy (section 2) | 5 min |

Data lost = the time between the newest off-host copy and the incident (≤ 15 min + 5 min copy delay
in normal operation; the `offsite` health check shows it at any moment).

## 5. DR drill (proves RPO and RTO)

`scripts/dr/drill.sh` restores the newest backup into an **isolated** compose project (`tmc-dr`: own
project name, volumes, networks and container names; no published ports — refused otherwise), runs
the full smoke test from inside that network, checks that named content exists, verifies the audit
chain and the health endpoint, measures **RPO** (drill start − database state of the backup) and
**RTO** (drill start → every smoke check passes), and writes a Markdown report. The protected stack
is only read.

```bash
scripts/dr/drill.sh                                   # newest backup of this checkout's stack
scripts/dr/drill.sh --source-dir /mnt/tmc-backups     # from the off-host copy (as in a real disaster)
scripts/dr/drill.sh --keep                            # leave the DR stack running for inspection
```

In CI, `.github/workflows/dr-drill.yml` does the whole chain on a clean machine every month (and when
the integrator calls it): provision six sites → create labelled test content (a post and an uploaded
file) → backup → off-host copy (twice: the second run copies nothing and still verifies) → drill **from
the copy** → the test content must be present on the restored site. The report is in the job summary
and kept as an artefact for 90 days. Schedule: a drill on the UAT host each quarter as well, with the
report filed in the quarterly review (R-6-8).

## 6. Rollback of a release

Every deploy first writes a database dump (`backups/pre-deploy-*.sql.gz`, last 10) and a full
snapshot (tier `pre-deploy`). Code rollback and the database decision are described in
[environments.md](environments.md#rollback).
