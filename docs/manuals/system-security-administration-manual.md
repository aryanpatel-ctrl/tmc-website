# System and Security Administration Manual

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-MAN-02 | 0.2 | Draft for TMC IT approval | R-8.2-6, R-4.15-6, R-4.16-2, R-4.8-2 to R-4.8-4, R-4.7-5 |

**Audience.** TMC IT infrastructure staff and the Vendor's Infrastructure/Security Specialist. This
manual covers operating the servers and containers, security administration, secrets, logs and the
technical side of incidents. CMS administration (users, roles, audit-log screen) is in the
[CMS Administrator Manual](cms-administrator-manual.md). Installation from nothing is in the
[Installation and Deployment Guide](../operations/installation-deployment.md).

**Command conventions.** Commands run on the Docker host as the service account, in the deployment
directory, after loading the environment:

```bash
cd ~/docker/tmc-website
set -a; . ./.env; set +a
wp() { docker compose run --rm -T wpcli "$@" </dev/null; }      # WP-CLI helper used below
```

Never paste secret values into tickets, chat or documents. Never edit files inside running containers:
changes are made in the repository and deployed through the pipeline.

---

## 1. System overview

| Container | Role | Network | Must be running |
|---|---|---|---|
| `tmc-wp` | Apache + PHP 8.3 + WordPress Multisite | `tmc_internal`, `tmc_edge`, `tmc_apps` (+ proxy network) | Yes |
| `tmc-db` | MariaDB 11.4 (all content, users, audit log) | `tmc_internal` | Yes |
| `tmc-redis` | Object cache (no persistence) | `tmc_internal` | Yes (sites work without it, slower) |
| `tmc-cron` | Runs due scheduled jobs on every site each minute | `tmc_internal` | Yes (scheduling and expiry records depend on it) |
| `tmc-backup` | Scheduled backups every 15 minutes with retention (`tmc-backup status`) | `tmc_internal` | Yes (RPO depends on it) |
| `tmc-apps-mock` | Demonstration stand-in for TMC's application back ends (Dev, CI, UAT only; not started on Production) | `tmc_apps` | Demonstration environments only |
| `wpcli` | On-demand WP-CLI for provisioning and maintenance | `tmc_internal`, `tmc_edge`, `tmc_apps` | No (started per command) |

On Production the same containers are named `tmc-prod-*` (`compose.prod.yml`).

Architecture and zones: [System Architecture](../architecture/system-architecture.md),
[Security Architecture](../architecture/security-architecture.md).

## 2. Access to servers

- Access to Production and DR hosts only through the TMC VPN/bastion with MFA, using a personal SSH key;
  password authentication is disabled. Vendor accounts are enabled per ticket and time-bound
  ([Access Control Policy §5](../architecture/access-control-policy.md#5-vendor-access)).
- Work as the service account that owns `~/docker/tmc-website` (e.g. `sudo -iu tmcdeploy`); do not run
  the stack as root.
- Record the ticket number of every session in the session log.

## 3. Daily health checks

```bash
docker compose ps                                    # every long-running container "running"/"healthy"
curl -s https://<tmc domain>/wp-json/tmc/v1/health   # "status":"ok" (HTTP 200); 503 names the failing check
docker compose exec backup tmc-backup status         # newest backup and its age (RPO 15 minutes)
./scripts/smoke-test.sh                              # every site, both languages → "smoke test passed"
docker compose logs --since 24h wordpress | grep -E "PHP (Fatal|Warning)" | tail -20
df -h / /var/lib/docker                              # keep at least 20 % free
tail -3 .release-history                             # which release is live
```

Continuous monitoring, alerting and the uptime measurement used for the 99.5 % SLA are described in
[Monitoring](../operations/monitoring.md): Uptime Kuma on a separate host polls every home page and
the health endpoint, and `scripts/sla/availability-report.sh` produces the monthly availability
report. The checks above remain the manual fallback; *Network Admin → Health & Backups* shows the same
health checks and the recent backups in the CMS.

## 4. Operating the containers

| Task | Command | Notes |
|---|---|---|
| Status | `docker compose ps` | |
| Logs of the web container | `docker compose logs --tail=200 wordpress` | Includes Apache access/error logs, PHP errors and `TMC-AUDIT` lines |
| Logs of the scheduler | `docker compose logs --tail=50 cron` | |
| Start everything after a host reboot | `docker compose up -d` | Containers restart automatically (`restart: unless-stopped`) |
| Recreate one container after an `.env` change | `docker compose up -d wordpress cron` | Applies the new environment |
| Full provisioning (idempotent) | `./scripts/setup.sh` | Normally run by the pipeline |
| List sites | `wp --url="$TMC_BASE_DOMAIN" site list --fields=blog_id,url` | |
| Scheduled events on a site | `wp --url="tmh.$TMC_BASE_DOMAIN" cron event list --fields=hook,next_run_relative` | `tmc_expire_content` runs every 5 minutes |
| Run due events now (diagnosis) | `wp --url="tmh.$TMC_BASE_DOMAIN" cron event run --due-now` | |
| Flush the object cache | `wp --url="$TMC_BASE_DOMAIN" cache flush` | Safe; needed after a database restore |
| Applied migrations on a site | `wp --url="tmh.$TMC_BASE_DOMAIN" option get tmc_migrations --format=json` | |

`docker compose down` stops the websites; use it only in a planned maintenance window. Never use
`docker compose down -v`: it deletes the database and uploads volumes.

## 5. Security administration

### 5.1 CMS administrative access

| Control | How to administer | Status |
|---|---|---|
| Multi-factor authentication for Super Admin, Site Administrator, Reviewer / Publisher | Enrolment at first login (TOTP + backup codes, Two Factor plugin 0.17.0); reset for a user who lost their device after identity verification (§6). `TMC_ENFORCE_MFA` must stay unset (on) | In place |
| Administrative network allow-list (`/wp-admin`, `/wp-login.php`) | `TMC_ADMIN_ALLOW_CIDRS` in `.env` (comma-separated ranges; unset = private ranges and the VPN range only; an invalid list blocks everyone), recreate `wordpress`; plus the reverse proxy / firewall rule (TMC) | In place (application); TMC (perimeter) |
| Login rate limiting / lockout | `TMC_LOGIN_MAX_ATTEMPTS` (5 per account), `TMC_LOGIN_MAX_ATTEMPTS_IP` (20 per address), `TMC_LOGIN_WINDOW` (900 s), `TMC_LOGIN_LOCKOUT_BASE` (60 s, doubling) and `TMC_LOGIN_LOCKOUT_MAX` (3600 s); lockouts are audit events `login_lockout` | In place |
| Session timeout | `TMC_ADMIN_IDLE_MINUTES` (30) and `TMC_ADMIN_SESSION_HOURS` (12, privileged accounts) | In place |
| Role assignments | CMS Administrator Manual §4 | In place |
| File editing in the admin disabled | `DISALLOW_FILE_EDIT` (`docker-compose.yml`) | In place |
| Theme and plugin code read-only at runtime | Read-only bind mounts | In place |

### 5.2 Web server hardening (in place)

Defined in `wordpress/apache-tmc.conf` and `wordpress/php.ini`; verify after each release:

```bash
curl -sI "https://$TMC_BASE_DOMAIN/" | grep -iE "server:|x-content-type-options|x-frame-options|referrer-policy|permissions-policy"
curl -s -o /dev/null -w "%{http_code}\n" "https://$TMC_BASE_DOMAIN/xmlrpc.php"   # expect 403
```

Expected: `Server: Apache` (no version), the four security headers present, `xmlrpc.php` refused.
Every page also carries a `Content-Security-Policy` with a per-request nonce and, over HTTPS,
`Strict-Transport-Security` (`tmc-core/security-headers.php`); `scripts/smoke.d/security.sh` checks all
of them after every deployment.

### 5.3 Reviewing security events

| Source | How | Frequency |
|---|---|---|
| CMS audit log | *Network Admin → Audit Log*: filter by `login_failed`, `user_role_changed`, `super_admin_granted`, `plugin_activated`, `network_setting_changed` | Monthly (weekly during the first three months after each Go-Live) |
| Web server log | `docker compose logs --since 24h wordpress \| grep -c " 403 "` and review of unusual paths | Weekly |
| Host authentication | `journalctl -u ssh --since yesterday` (or `/var/log/auth.log`) | Weekly |
| Container image and dependency scans | CI security gate reports of the latest release (`security-image-reports` from Trivy, `security-secrets-report`, `security-dast-reports`; `.github/workflows/security.yml`) and open Dependabot pull requests | Each release |

Brute-force pattern: many `login_failed` entries for one username or from one IP in the audit log.
Action: confirm the lockout is working (`login_lockout` entries in the audit log), block the source at the firewall if it persists, and
inform the account holder.

### 5.4 Log locations and retention

| Log | Location | Retention |
|---|---|---|
| CMS audit log | Table `tmc_tmc_audit_log` (all sites) | Whole contract, see [Audit Log Retention Policy](../architecture/audit-log-retention-policy.md) |
| Web container | Docker log of `tmc-wp`; to be shipped to TMC's central log store from the production host (TMC infrastructure) | 180 days minimum (CERT-In Directions, 28 April 2022) |
| Proxy / WAF | TMC infrastructure | 180 days minimum |
| Host | journald / `/var/log` | 180 days minimum |
| Deployments | `.release-history` on each server; GitHub Actions history | Life of the system |

The host clock must be synchronised (NTP) for log correlation.

## 6. Rotating secrets

All secrets live in `.env` on each host (mode 600) and in TMC's offline escrow. Rotate **annually**, when
a person who knew a secret leaves, at the end of the contract, and immediately on suspicion of
compromise. Record each rotation (date, secret name, reason, ticket — never the value). Take an on-demand
database backup first ([Backup and Restoration §3.2](../operations/backup-restore.md#32-database)).

Generate a new value (alphanumeric, same generator as `scripts/make-env.sh`):

```bash
NEW="$(openssl rand -base64 64 | tr -dc 'A-Za-z0-9' | head -c 32)"
```

### 6.1 `DB_PASSWORD` (WordPress database user)

```bash
# 1. change the password inside MariaDB (root credentials passed via the environment, not the command line)
docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db \
  mariadb -uroot -e "ALTER USER '${DB_USER}'@'%' IDENTIFIED BY '${NEW}'; FLUSH PRIVILEGES;"
# 2. store it in .env
sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${NEW}/" .env
# 3. recreate the containers that connect to the database, so they read the new value
docker compose up -d wordpress cron
# 4. verify
./scripts/smoke-test.sh
unset NEW
```

The official WordPress image reads the database password from the environment on every request, so no
file inside the container needs editing. Update the escrow copy of `.env`.

### 6.2 `DB_ROOT_PASSWORD` (MariaDB root)

`MARIADB_ROOT_PASSWORD` is used by MariaDB only when the database is first created, so changing `.env`
alone has no effect:

```bash
docker compose exec -T -e MYSQL_PWD="$DB_ROOT_PASSWORD" db mariadb -uroot -e \
  "ALTER USER 'root'@'localhost' IDENTIFIED BY '${NEW}'; ALTER USER IF EXISTS 'root'@'%' IDENTIFIED BY '${NEW}'; FLUSH PRIVILEGES;"
sed -i "s/^DB_ROOT_PASSWORD=.*/DB_ROOT_PASSWORD=${NEW}/" .env
unset NEW
```

### 6.3 Bootstrap Super Admin (`WP_ADMIN_PASSWORD`)

The `.env` value is used only by the first network installation. After installation the account is an
ordinary CMS account: change its password in *Users → Profile* (or remove Super Admin rights from it once
named TMC IT accounts exist). Update or blank the `.env` value afterwards so it is not mistaken for a
live credential.

### 6.4 WordPress authentication keys and salts

Rotating them signs every user out (use after an incident or suspected session theft):

```bash
wp --url="$TMC_BASE_DOMAIN" config shuffle-salts
```

The keys are stored in `wp-config.php` in the `wp_html` volume (generated by the WordPress image at first
start). The `.env` file is not involved.

### 6.5 `TMC_AUDIT_KEY` (audit-log HMAC key)

Do **not** rotate routinely: entries signed with the old key can then no longer be verified with the new
one. Rotate only on suspicion of compromise, following the
[Audit Log Retention Policy §7](../architecture/audit-log-retention-policy.md#7-key-rotation) (verify,
export and archive with the old key, change, recreate `wordpress` and `cron`, record).

### 6.6 Other credentials

| Credential | Procedure |
|---|---|
| TLS certificates and keys | TMC infrastructure (perimeter/reverse proxy); renew before expiry |
| MFA secret of a user | After verifying the person's identity: `wp user meta delete <login> _two_factor_totp_key` and `wp user meta delete <login> _two_factor_backup_codes` (run with `--url=<site>`); at the next sign-in the user must enrol again. Each removal is written to the audit log (`mfa_totp_removed`, `mfa_backup_codes_removed`) |
| Integration endpoint credentials | Change at the TMC application side and in `TMC_APP_<SERVICE>_KEY` in `.env` ([gateway spec](../integration/gateway.md)); recreate `wordpress` |
| Backup storage (off-host copy) | Replace the key in `TMC_OFFSITE_SSH_KEY` and the host key in `TMC_OFFSITE_KNOWN_HOSTS` ([backup and DR](../operations/backup-and-dr.md)) |
| Monitoring, log store | [Monitoring](../operations/monitoring.md); the log store is TMC infrastructure |
| Self-hosted runner registration | Remove and re-register the runner from the repository settings |

## 7. Backups, restores and DR

See [Backup and Restoration Procedures](../operations/backup-restore.md): what is backed up, schedule,
on-demand backup commands, restore of the database and uploads, rebuild from nothing, and the quarterly
DR drill record (RPO 15 minutes, RTO 1 hour).

## 8. Patching and releases

See [Patch Management Procedure](../operations/patch-management.md) (component inventory, timelines,
emergency patches) and [Installation and Deployment Guide §4–5](../operations/installation-deployment.md#4-routine-deployment-every-release)
(pipeline deployment and rollback).

## 9. Security incident: technical steps

Follow the [Incident and Support Model §6](../operations/incident-support-model.md#6-security-incidents)
for roles, reporting (CERT-In within 6 hours, by TMC) and communication. Technical steps:

1. **Contain.** Put the affected site(s) behind a maintenance page at the reverse proxy (TMC), disable
   suspected accounts (*Network Admin → Users*), block attacking addresses at the firewall. Do not stop
   the database before evidence is collected.
2. **Preserve evidence** into a dated, access-restricted directory:

   ```bash
   E="evidence/$(date +%Y%m%d-%H%M%S)"; mkdir -p "$E"; chmod 700 evidence "$E"
   docker compose ps > "$E/containers.txt"
   docker compose logs --no-color --timestamps wordpress > "$E/wordpress.log"
   docker compose logs --no-color --timestamps cron > "$E/cron.log"
   export MYSQL_PWD="$DB_PASSWORD"
   docker exec -e MYSQL_PWD tmc-db mariadb-dump -u"$DB_USER" --single-transaction --quick "$DB_NAME" | gzip > "$E/db.sql.gz"
   unset MYSQL_PWD
   cp .release-history "$E/"
   sha256sum "$E"/* > "$E/SHA256SUMS"
   ```

   Also: *Network Admin → Audit Log → Verify integrity* (screenshot) and **Export CSV** into the same
   directory; request proxy/WAF and host logs from TMC infrastructure.
3. **Eradicate.** Identify the entry point. Because all code is deployed from the repository and code
   directories are read-only in the container, rebuild rather than repair: redeploy the last known-good
   release through the pipeline, or rebuild the host from nothing
   ([Backup and Restoration §4.3](../operations/backup-restore.md#43-rebuild-an-environment-from-nothing-new-host-or-dr-activation)).
4. **Recover.** Restore content from the last backup before the compromise if content was altered;
   rotate all secrets (§6), including WordPress salts; force password resets for affected users.
5. **Verify** with the smoke test, audit-log integrity check and a targeted re-test of the exploited
   weakness; lift the maintenance page with TMC IT's approval.

## 10. Segregation validation

Before each Go-Live and annually, run the segregation tests S-1 to S-6 of the
[Security Architecture §3.1](../architecture/security-architecture.md#31-segregation-validation-test-performed-at-m2-and-before-each-go-live)
with TMC IT and record the results in the Go-Live acceptance pack.

## 11. Periodic tasks

| Frequency | Task | Reference |
|---|---|---|
| Daily | Health checks (§3); backup job status | §3; [Monitoring](../operations/monitoring.md); [Backup and DR](../operations/backup-and-dr.md) |
| Weekly | Security event review (§5.3); vulnerability sources | §5.3, Patch Management §3 |
| Monthly | Maintenance release; vendor access review; disk usage trend | Patch Management §4 |
| Quarterly | DR drill; audit-log anchor; restore test | Backup and Restoration §5–6 |
| Annually | Secret rotation (§6); segregation tests (§10); VAPT support; annual security review | §6, §10 |
