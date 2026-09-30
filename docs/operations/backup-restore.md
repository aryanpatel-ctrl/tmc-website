# Backup and Restoration Procedures

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-OPS-03 | 0.1 | Draft; scheduled-backup details from W7 to be confirmed at integration | R-4.15-5, R-4.7-6, R-4.7-8, R-8.2-6 |

SOW §4.7 requires "scheduled backup of content, databases, and configurations, with a documented and
tested restoration procedure" and business continuity meeting **RPO 15 minutes** and **RTO 1 hour**,
demonstrated through periodic drills. This document is the documented procedure; the drill record in §6
is the test evidence.

**Commands** are run on the Docker host as the service account in the deployment directory
(`~/docker/tmc-website`), after loading the environment:

```bash
cd ~/docker/tmc-website
set -a; . ./.env; set +a
wp() { docker compose run --rm -T wpcli "$@" </dev/null; }
STAMP="$(date +%Y%m%d-%H%M%S)"
umask 077; mkdir -p backups
```

---

## 1. What is backed up

| # | Item | Location | Method | Frequency |
|---|---|---|---|---|
| B1 | Database (all sites, users, settings, revisions, audit log) | volume `db_data` | `mariadb-dump --single-transaction` (consistent, no locking of the sites) | Before every deployment (in place); scheduled per §2 (W7) |
| B2 | Uploads (media and documents of all sites) | volume `wp_html`, `wp-content/uploads/` | Archive / incremental copy | Scheduled per §2 (W7) |
| B3 | Environment configuration and secrets | `.env` | Encrypted copy in TMC's secret store / offline escrow | At creation and after every change |
| B4 | Audit HMAC key | `TMC_AUDIT_KEY` in `.env` | Offline escrow held by TMC IT | At creation and after rotation |
| B5 | Release record | `.release-history`, `.deployed` | Included in the configuration backup | After each deployment |
| B6 | Code, provisioning, documentation | Git repository | Git hosting + TMC mirror | Continuous |

WordPress core files in `wp_html` and the Redis cache are **not** backed up: core is reinstalled by the
image and provisioning, and the cache rebuilds itself.

## 2. Backup schedule and retention

**In place:** before every deployment `scripts/deploy.sh` writes
`backups/pre-deploy-<timestamp>-<commit>.sql.gz` (mode 600) and keeps the last 10.

**Scheduled backups (in place):** the `backup` service takes a complete snapshot (database dump,
uploads/plugins/language files, configuration; checksummed) every 15 minutes and copies it off the host
with `scripts/backup/offsite-copy.sh`. Retention (policy to be confirmed with TMC; details in
[Backup and DR](backup-and-dr.md)):

| Set | Frequency | Retention | Storage |
|---|---|---|---|
| Database + files + configuration | Every 15 minutes | 48 hours | `backup_data` volume on the host and the off-host copy in India |
| Same, first snapshot of each day | Daily | 30 days | As above |
| Same, first snapshot of each month | Monthly | 12 months | As above |
| Pre-deploy database dump + full snapshot | Each deployment | Last 10 dumps | Production host `backups/` and `backup_data` |

Backups are encrypted in transit and at rest, accessible only to TMC IT and the named infrastructure
administrator, and located in India (see [Data Residency Statement](../architecture/data-residency-statement.md)).

## 3. Taking a backup on demand

### 3.1 Before risky work

Deployments already take a database backup. For other risky work (bulk content import, domain change,
plugin upgrade outside a release) take both B1 and B2 manually as below.

### 3.2 Database

```bash
export MYSQL_PWD="$DB_PASSWORD"
docker exec -e MYSQL_PWD tmc-db mariadb-dump -u"$DB_USER" --single-transaction --quick "$DB_NAME" \
  | gzip > "backups/manual-$STAMP.sql.gz"
unset MYSQL_PWD
gzip -t "backups/manual-$STAMP.sql.gz" && ls -lh "backups/manual-$STAMP.sql.gz"
```

The password is passed through the environment, not on the command line, so it does not appear in the
process list.

### 3.3 Uploads

```bash
docker compose exec -T wordpress tar czf - -C /var/www/html wp-content/uploads \
  > "backups/uploads-$STAMP.tar.gz"
gzip -t "backups/uploads-$STAMP.tar.gz" && ls -lh "backups/uploads-$STAMP.tar.gz"
```

### 3.4 Configuration

```bash
tar czf - .env .release-history .deployed 2>/dev/null \
  | openssl enc -aes-256-cbc -pbkdf2 -salt -out "backups/config-$STAMP.tar.gz.enc"
```

The passphrase is held by TMC IT. Store the encrypted file off the host.

## 4. Restore procedures

Every restore is carried out against an approved incident or change ticket, by or in the presence of TMC
IT for Production. Record start and end times for the RTO measurement.

### 4.1 Restore the database on an existing environment

```bash
# 1. safety copy of the current state
export MYSQL_PWD="$DB_PASSWORD"
docker exec -e MYSQL_PWD tmc-db mariadb-dump -u"$DB_USER" --single-transaction --quick "$DB_NAME" \
  | gzip > "backups/before-restore-$STAMP.sql.gz"
# 2. restore the chosen backup
gunzip -c backups/<backup-file>.sql.gz | docker exec -i -e MYSQL_PWD tmc-db mariadb -u"$DB_USER" "$DB_NAME"
unset MYSQL_PWD
# 3. clear the object cache and check
wp --url="$TMC_BASE_DOMAIN" cache flush
./scripts/smoke-test.sh
```

After a database restore:

- content and settings are as at the backup time; changes made after it must be re-entered by editors
  (the post-backup `TMC-AUDIT` lines in the container log show what was changed);
- the audit log is also as at the backup time. Run *Verify integrity* (it must pass), then record the
  restore in the audit trail of the incident ticket, including the backup file name and the number of
  audit entries lost from the database (they remain in the shipped container log).

### 4.2 Restore uploads

```bash
docker compose exec -T wordpress tar xzf - -C /var/www/html < backups/uploads-<timestamp>.tar.gz
docker compose exec -T wordpress chown -R www-data:www-data /var/www/html/wp-content/uploads
```

### 4.3 Rebuild an environment from nothing (new host or DR activation)

| Step | Action | Target time |
|---|---|---|
| 1 | Provision host per [Installation Guide §1](installation-deployment.md#1-prerequisites) (pre-built for DR) | 0 min (DR host kept ready) |
| 2 | Place the **same** `.env` (same `DB_*`, `TMC_BASE_DOMAIN`, `TMC_AUDIT_KEY`) from the configuration backup | 5 min |
| 3 | Check out the release recorded in `.release-history` and run `./scripts/setup.sh` | 15 min |
| 4 | Restore the latest database backup (§4.1 step 2) | 10 min |
| 5 | Restore the latest uploads backup (§4.2) | 10 min |
| 6 | `wp cache flush`, `./scripts/smoke-test.sh`, *Verify integrity* of the audit log | 5 min |
| 7 | Switch DNS / proxy to the recovered environment (TMC infrastructure) | 10 min |
| | **Total** | **≤ 55 min (RTO 60 min)** |

Running `setup.sh` on an empty database installs a fresh network; therefore on a rebuilt host restore
the database **before** allowing editors in, and verify that the restored `tmc_migrations` option lists
all migrations of the deployed release (`wp option get tmc_migrations` per site).

Automation of these steps: `scripts/backup/offsite-copy.sh` (continuous off-host copy),
`scripts/dr/restore.sh` (restore a snapshot or a dump into a stack), `scripts/dr/drill.sh` with
`compose.dr.yml` (timed drill in an isolated project; run in CI by `.github/workflows/dr-drill.yml` on
every change and monthly). See [Backup and DR](backup-and-dr.md).

## 5. Verification of backups

| Check | Frequency | How |
|---|---|---|
| Backup job succeeded | Daily | Monitoring alert on missing/failed backup (W7) |
| Archive integrity | Each backup | `gzip -t` (compressed dumps) |
| Restorability | Monthly on UAT; quarterly DR drill | Restore the latest Production backup to UAT or DR and run the smoke test |
| Audit chain after restore | Each restore | *Network Admin → Audit Log → Verify integrity* |

## 6. Disaster recovery drill record

Performed at least quarterly and before Go-Live; the report is attached to the
[Quarterly Security and Performance Review](templates/quarterly-security-performance-review.md).

| Field | Value |
|---|---|
| Drill date and participants | |
| Scenario (e.g. loss of Production host at hh:mm) | |
| Backup set used (file names, timestamps) | |
| Time of last successful backup before the incident | |
| Data loss window (incident time − last backup time) | **RPO achieved:** ___ min (target ≤ 15) |
| Restore start → service verified | **RTO achieved:** ___ min (target ≤ 60) |
| Smoke test result | |
| Audit log integrity after restore | |
| Issues found and corrective actions (with owners, dates) | |
| Signed: Infrastructure/Security Specialist / TMC IT | |
