# Configuration Reference

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-OPS-02 | 0.2 | Draft for TMC IT approval | R-4.15-3, R-8.2-4 |

Every setting of the ecosystem, where it is defined and how to change it. Live credential values are
never written in this document; they are handed over to TMC separately and securely (SOW §8.2).

**Rule:** configuration is changed in the repository (and deployed through the pipeline) or in `.env`
on the host. Settings are never changed by hand in a running container.

---

## 1. Environment file `.env`

Created by `scripts/make-env.sh local|ci|server` with random secrets (`openssl rand`), mode `600`, never
committed (`.gitignore`). `make-env.sh` never overwrites an existing `.env`.

| Variable | Example / default | Used by | Purpose | Change after install? |
|---|---|---|---|---|
| `TMC_ENV` | `local`, `ci`, `server` (UAT), `prod`, `dr` | Scripts; `deploy.sh` guard (`server` for UAT, `prod` for production); file-modification lock on servers; demo settings | Environment name | Yes |
| `COMPOSE_FILE` | `docker-compose.yml:compose.local.yml` | Docker Compose | Base file plus the environment override | Yes (then `docker compose up -d`) |
| `TMC_BASE_DOMAIN` | `tmc.localhost`; UAT `tmc.100-79-142-44.sslip.io` | `docker-compose.yml` (`DOMAIN_CURRENT_SITE`), all scripts | Base domain of the network; unit sites are `<slug>.<base>` | Only by the procedure in §5 |
| `DB_NAME` | `tmc_wp` | `db`, `wordpress`, `wpcli`, `cron` | Database schema | No |
| `DB_USER` | `tmc_wp` | as above | Database user of WordPress | No (MariaDB creates it on first start only) |
| `DB_PASSWORD` | 32 random characters | as above; `deploy.sh` (backup) | Password of `DB_USER` | By rotation procedure only ([System and Security Administration Manual §6](../manuals/system-security-administration-manual.md#6-rotating-secrets)) |
| `DB_ROOT_PASSWORD` | 32 random characters | `db` (first start) | MariaDB root password | By rotation procedure only |
| `WP_ADMIN_USER` | `tmcadmin` | `install-network.sh`, migration 001 (author of starter content) | First Super Admin login | Not after install (the account is then managed in the CMS) |
| `WP_ADMIN_PASSWORD` | 24 random characters | `install-network.sh` | First Super Admin password | Change in the CMS at first login; the `.env` value is then historical |
| `WP_ADMIN_EMAIL` | `admin@example.com` (placeholder) | `install-network.sh`, `create-demo-users.sh` | Network and site admin e-mail | Set to TMC IT's mailbox before install; later in *Network Admin → Settings* |
| `TMC_AUDIT_KEY` | 48 random characters | `wordpress`, `wpcli`, `cron` (environment) | HMAC key of the audit log chain | Only per [Audit Log Retention Policy §7](../architecture/audit-log-retention-policy.md#7-key-rotation) |

Variables used only transiently:

| Variable | Set by | Purpose |
|---|---|---|
| `TMC_MULTISITE=0` | `install-network.sh` for the one-time `core multisite-install` | Loads WordPress without the Multisite constants so the network can be created |
| `GITHUB_ACTOR`, `GITHUB_RUN_ID`, `GITHUB_WORKSPACE` | GitHub Actions | Recorded by `deploy.sh` in `.release-history` |

### 1.1 Feature settings

All optional: unset or empty means the default shown. They are passed to the containers by
`docker-compose.yml` (`x-wp-env`); change `.env`, then `docker compose up -d` to recreate the containers.

| Variable | Default | Purpose |
|---|---|---|
| `TMC_ENFORCE_MFA` | `1` | TOTP MFA for privileged roles. `0` only for automated test stacks, never on UAT or production |
| `TMC_MFA_GRACE_HOURS` | `0` | Hours a new privileged account may work before enrolling |
| `TMC_ADMIN_ALLOW_CIDRS` | private ranges, `100.64.0.0/10` (VPN) and loopback | Networks allowed to open `/wp-admin` and `/wp-login.php`; a list with no valid entry blocks everyone |
| `TMC_ADMIN_IDLE_MINUTES` / `TMC_ADMIN_SESSION_HOURS` | `30` / `12` | Inactivity sign-out; longest privileged session |
| `TMC_PASSWORD_MIN_LENGTH` | `12` | Minimum password length for privileged accounts |
| `TMC_LOGIN_MAX_ATTEMPTS`, `TMC_LOGIN_MAX_ATTEMPTS_IP`, `TMC_LOGIN_WINDOW`, `TMC_LOGIN_LOCKOUT_BASE`, `TMC_LOGIN_LOCKOUT_MAX` | `5`, `20`, `900`, `60`, `3600` | Login throttling (failures per account / per IP, window and lockout in seconds) |
| `TMC_APP_PASSWORD_ROLES` | empty (off) | Roles that may use WordPress application passwords |
| `TMC_HSTS_MAX_AGE` / `TMC_HSTS_INCLUDE_SUBDOMAINS` | one year / `0` | HSTS on HTTPS responses |
| `TMC_SECURITY_CONTACT` / `TMC_SECURITY_TXT_EXPIRES` | placeholder / 180 days ahead | `security.txt` contact (`mailto:` or `https:`; **TMC to confirm**) and expiry |
| `TMC_DISALLOW_FILE_MODS` | on for `TMC_ENV=server` | Blocks plugin/theme installs from the admin screens |
| `TMC_SUGGEST_RATE_LIMIT` | `120` | Search suggestions per minute per IP address |
| `TMC_MATOMO_URL` | empty | Matomo address (overrides the network setting; analytics stay off until configured) |
| `TMC_DEMO` / `TMC_APPS_MOCK_KEY` | `1` + generated key on local, CI and UAT; `0` on production | Demonstration data and the DEMO application mock |
| `TMC_APP_<SERVICE>_KEY` (`TMC_APP_APPOINTMENTS_KEY`, `TMC_APP_RESULTS_KEY`, `TMC_APP_FORMS_KEY`, `TMC_APP_PAYMENTS_KEY`) | the mock key in demo environments | API key of each registered TMC application service ([gateway](../integration/gateway.md)) |
| `TMC_PDFTOTEXT` | unset (`/usr/bin/pdftotext`, `/usr/local/bin/pdftotext`) | Path of the `pdftotext` program used to index the text of PDF documents for search ([search and documents](../features/search-and-documents.md)) |
| `TMC_WP_ENVIRONMENT` | `staging`; `production` on production | Sets `WP_ENVIRONMENT_TYPE` (robots, sitemaps, search-engine visibility) |
| `TMC_OFFSITE_TARGET`, `TMC_OFFSITE_SSH_KEY`, `TMC_OFFSITE_KNOWN_HOSTS`, `TMC_OFFSITE_PORT`, `TMC_OFFSITE_BWLIMIT`, `TMC_OFFSITE_PRUNE` | unset | Off-host backup copy ([backup and DR](backup-and-dr.md)) |
| `TMC_BACKUP_INTERVAL_MINUTES`, `TMC_BACKUP_KEEP_RECENT_HOURS`, `TMC_BACKUP_KEEP_DAILY_DAYS`, `TMC_BACKUP_KEEP_MONTHLY_MONTHS` | `15`, `48`, `30`, `12` | Backup schedule and retention |
| `COMPOSE_PROJECT_NAME`, `TMC_PROXY_ROUTE`, `TMC_HTTP_PORT`, `SMOKE_ORIGIN` | set by `make-env.sh prod`/`dr` | Production stack identity, proxy handling and smoke-test origin ([environments](environments.md)) |

Outbound mail through a TMC SMTP relay is not configured in this release (EOI query Q-23).

## 2. Docker Compose

### 2.1 `docker-compose.yml` (base, all environments)

| Service | Key settings |
|---|---|
| `db` | `mariadb:11.4.13`; `--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --max-allowed-packet=64M`; volume `db_data`; network `tmc_internal`; health check `healthcheck.sh --connect --innodb_initialized` every 10 s |
| `redis` | `valkey/valkey:8.1.10-alpine` (Redis-compatible, BSD-3-Clause); `valkey-server --maxmemory 256mb --maxmemory-policy allkeys-lru --save ""` (no persistence); network `tmc_internal` |
| `wordpress` | Built from `./wordpress` as `tmc-wordpress:latest`; depends on healthy `db`; environment `*wp-env`; volumes `wp_html`, `src/mu-plugins` (ro), `src/themes/tmc` (ro); networks `tmc_internal`, `tmc_edge`, `tmc_apps` |
| `wpcli` | `wordpress:cli-2.12.0-php8.3`, user `33:33`, profile `tools` (not started by `up`); mounts `scripts/` read-only at `/tmc-scripts`; networks `tmc_internal`, `tmc_edge`, `tmc_apps` |
| `cron` | `wordpress:cli-2.12.0-php8.3`, user `33:33`; loop: for each site `wp cron event run --due-now`, then `sleep 60`; network `tmc_internal` only |
| `tmc-apps-mock` | Demonstration only: `php:8.3.35-cli-alpine` stand-in for TMC's application back ends (appointments, results, online forms, payments); read-only, unprivileged user, network `tmc_apps` only; answers only requests carrying `TMC_APPS_MOCK_KEY`; not started on production (`compose.prod.yml`) |
| `backup` | Built from `./backup` as `tmc-backup:latest`; scheduled backups every `TMC_BACKUP_INTERVAL_MINUTES` with retention; volumes `wp_html` (read-only) and `backup_data`; network `tmc_internal` only; health check `tmc-backup health` ([backup and DR](backup-and-dr.md)) |

Networks: `tmc_internal` (`internal: true`), `tmc_edge` (default bridge with internet), `tmc_apps`
(`internal: true`; WordPress to TMC application back ends only). Volumes: `db_data`, `wp_html`,
`backup_data`.

### 2.2 WordPress constants (`WORDPRESS_CONFIG_EXTRA`)

| Constant | Value | Why |
|---|---|---|
| `WP_ENVIRONMENT_TYPE` | `${TMC_WP_ENVIRONMENT:-staging}` | Environment type reported to WordPress; `production` only on the production stack |
| `DISALLOW_FILE_EDIT` | `true` | No theme/plugin editor in the admin |
| `WP_MEMORY_LIMIT` | `256M` | PHP memory for WordPress |
| `WP_AUTO_UPDATE_CORE` | `minor` | Minor core updates allowed; see [Patch Management §4](patch-management.md#4-how-updates-are-applied) for how updates are actually applied |
| `WP_REDIS_HOST`, `WP_REDIS_PORT`, `WP_CACHE_KEY_SALT` | `tmc-valkey`, `6379`, `tmc_` | Object cache connection settings. `tmc-valkey` (cache) and `tmc-mariadb` (database, `WORDPRESS_DB_HOST`) are network aliases that exist only on `tmc_internal`; plain names such as `redis` or `db` could resolve to another project's container on a shared network |
| `DISABLE_WP_CRON` | `true` | Scheduled jobs run from the `cron` container, not on page views |
| HTTPS detection | `$_SERVER['HTTPS']='on'` when `X-Forwarded-Proto` contains `https` | Correct URLs behind the TLS-terminating proxy |
| `WP_ALLOW_MULTISITE`, `MULTISITE`, `SUBDOMAIN_INSTALL` | `true` | Multisite on subdomains |
| `DOMAIN_CURRENT_SITE` | `${TMC_BASE_DOMAIN}` | Network domain |
| `PATH_CURRENT_SITE`, `SITE_ID_CURRENT_SITE`, `BLOG_ID_CURRENT_SITE` | `/`, `1`, `1` | Network root |
| `WORDPRESS_TABLE_PREFIX` | `tmc_` | Table prefix |

### 2.3 Overrides

| File | Setting |
|---|---|
| `compose.local.yml` | `wordpress.ports: 127.0.0.1:80:80` |
| `compose.server.yml` | `wordpress.networks: [tmc_internal, tmc_edge, tmc_apps, homelab]`; external network `homelab` |
| `compose.prod.yml` | Production / real failover: project `tmc-prod`, own container names, WordPress on `127.0.0.1:${TMC_HTTP_PORT:-8080}`, DEMO mock not started |
| `compose.dr.yml` | DR drill: project `tmc-dr`, own container names, no published ports |

## 3. Web server and PHP (image `wordpress/`)

| File | Setting | Value |
|---|---|---|
| `Dockerfile` | Base image | `wordpress:7.1.2-php8.3-apache` (exact pin) |
| | Extensions / modules | `pecl install redis` (phpredis); Apache `remoteip`, `headers`, `expires` |
| `php.ini` (`zz-tmc.ini`) | `expose_php` | `Off` |
| | `memory_limit` | `256M` |
| | `upload_max_filesize`, `post_max_size` | `64M` (largest document an editor can upload) |
| | `max_execution_time` | `120` |
| | `max_input_vars` | `3000` (large menus) |
| `apache-tmc.conf` | `ServerTokens` / `ServerSignature` / `TraceEnable` | `Prod` / `Off` / `Off` |
| | `RemoteIPHeader` / trusted proxies | `X-Forwarded-For` from `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16` |
| | Headers | `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: geolocation=(), microphone=(), camera=()` |
| | Uploads | PHP execution denied under `wp-content/uploads` |
| | `xmlrpc.php` | Denied |
| `htaccess` | Rewrite rules | Standard WordPress Multisite (subdomain) rules |
| `nginx/tmc-website.conf` | UAT proxy route | Server names `tmc.100-79-142-44.sslip.io *.tmc.100-79-142-44.sslip.io`; `client_max_body_size 64m`; forwards `Host`, `X-Real-IP`, `X-Forwarded-For`, `X-Forwarded-Proto`; `proxy_read_timeout 120s` |

## 4. Application settings

### 4.1 Applied by provisioning (every site)

| Setting | Value | Source |
|---|---|---|
| Time zone | `Asia/Kolkata` | `install-network.sh` |
| Date / time format | `d/m/Y` / `h:i A` | `install-network.sh` |
| Permalinks | `/%postname%/` | `install-network.sh` |
| Search-engine visibility (`blog_public`) | `1` on production (`WP_ENVIRONMENT_TYPE=production`), `0` everywhere else | `install-network.sh` |
| Active theme | `tmc` (network-enabled) | `setup.sh` |
| Plugins | Polylang `3.8.10`, Two Factor `0.17.0`, network-active | `setup.sh` (`POLYLANG_VERSION`, `TWO_FACTOR_VERSION`) |
| Plugin | Redis Object Cache `3.0.0`, network-active | `setup-cache.sh` (`REDIS_CACHE_VERSION`) |
| Language packs | `hi_IN`, `en_GB` (core and plugins) | `setup.sh` |
| Languages | English `en` (`en_GB`, default, no URL prefix), Hindi `hi` (`hi_IN`, `/hi/`) | `setup-languages.php` |
| Polylang options | `force_lang=1` (language from directory), `hide_default=true`, `rewrite=true`, `browser=false`, `redirect_lang=true`, `media_support=false` | `setup-languages.php` |
| Home page, IA pages, menus, categories | Per site, EN + HI | `seed-home-pages.php`, `seed-site-structure.php` |

### 4.2 Code-level configuration (change by pull request)

| Setting | Location |
|---|---|
| Role labels and Content Editor capabilities (`TMC_ROLES_VERSION` re-applies them) | `src/mu-plugins/tmc-core/roles.php` |
| Content types, fields, required fields, help text | `tmc_content_types()`, `tmc_field_schema()` in `content-types.php` |
| Expiry job interval (5 minutes) and rules | `expiry.php` |
| Audited options | `TMC_AUDIT_WATCHED_OPTIONS`, `TMC_AUDIT_WATCHED_NETWORK_OPTIONS` in `audit-log.php` |
| Design tokens (palette, fonts, sizes, spacing; custom values disabled) | `src/themes/tmc/theme.json` |
| Menu locations: `primary`, `footer-quick`, `footer-policies` | `inc/setup.php` |
| Listing page sizes (tenders/jobs/events 20, doctors 24, departments 100) | `inc/content-views.php` |
| Site list for provisioning | `install-network.sh` (`SITES`), `setup.sh` and `smoke-test.sh` (`SITES`), `seed-site-structure.php` and `seed-home-pages.php` (per-site facts) |

### 4.3 Settings managed in the CMS by administrators

| Setting | Where | Who |
|---|---|---|
| Contact details and social links per site (`tmc_address`, `tmc_phone`, `tmc_email`, `tmc_facebook`, `tmc_x`, `tmc_youtube`, `tmc_instagram`, `tmc_linkedin`) | *Appearance → Customize → TMC contact details* | Site Administrator |
| Hindi translation of the address | *Languages → Translations*, group "TMC contact details" | Site Administrator |
| Menus | *Appearance → Menus* | Site Administrator |
| Site title and tagline | *Settings → General* (audited) | Site Administrator |
| Users and roles | *Users* (site) / *Network Admin → Users* | Site Administrator / Super Admin |
| Upload file types and maximum size (network) | *Network Admin → Settings* (audited) | Super Admin |
| Redirects (301/410) of a site, bulk import, hit log | *Tools → Redirects* | Site Administrator |
| Web analytics provider (none, Matomo or GA4), per-site analytics IDs, search-console verification tokens, default social-sharing image | *Network Admin → Settings → Analytics & Search* | Super Admin |
| TMC application services (gateway endpoints, enabled actions) | *Network Admin → Settings → TMC applications* | Super Admin |
| Documents and media of all sites (overview, PDF text indexing status) | *Network Admin → Media & documents* | Super Admin |
| Health checks, recent backups, page-cache purge | *Network Admin → Health & Backups* | Super Admin |

## 5. Changing the base domain

Needed when moving from a temporary domain to the confirmed one. Rehearse on UAT first.

```bash
wp() { docker compose run --rm -T wpcli "$@" </dev/null; }
OLD=tmc.100-79-142-44.sslip.io      # current TMC_BASE_DOMAIN
NEW=tmc.gov.in
# first take an on-demand database backup: backup-restore.md §3.2
wp --url="$OLD" search-replace "$OLD" "$NEW" --network --all-tables --precise --skip-columns=guid --dry-run
wp --url="$OLD" search-replace "$OLD" "$NEW" --network --all-tables --precise --skip-columns=guid
sed -i "s/^TMC_BASE_DOMAIN=.*/TMC_BASE_DOMAIN=$NEW/" .env
docker compose up -d                 # recreates containers with the new DOMAIN_CURRENT_SITE
wp --url="$NEW" cache flush
./scripts/smoke-test.sh
```

The search-replace also updates the domains in the network tables (`tmc_blogs`, `tmc_site`). Update DNS,
TLS and the proxy server names at the same time. The redirect manager works on paths within a site, so
redirect the old domain to the new one at the reverse proxy (301, path kept).

## 6. CI/CD configuration

| Item | Value | File |
|---|---|---|
| Triggers | push to `main` and `feat/**`; pull requests; manual run with input `ref` | `.github/workflows/pipeline.yml` |
| Jobs | `lint`, `ops`, `gateway`, `docs` → `integration`, `security`, `quality`, `dr-drill`, `capacity` (in parallel, each on a fresh stack) → `deploy` (main or manual only; needs every other job) | same |
| Runners | `ubuntu-latest` (all checks); `[self-hosted, tmc-server]` (deploy) | same |
| Concurrency | One pipeline per ref (feature branches cancel older runs); deployments serialised in group `deploy-uat` | same |
| Permissions | `contents: read` | same |
| Pinned action | `actions/checkout@v5` (the gate workflows pin actions by commit SHA) | same |
| Reusable workflows | `ops-checks.yml`, `apps-gateway.yml`, `docs.yml`, `security.yml`, `quality.yml`, `dr-drill.yml`, `capacity.yml` | `.github/workflows/` |
| Standalone workflows | `release.yml` (production, `v*` tags), `load-test.yml` (manual, UAT), `updates.yml` (weekly), `dr-drill.yml` (also monthly) | same |

## 7. Make targets (developer convenience)

| Target | Runs |
|---|---|
| `make setup` | `make-env.sh local`, `setup.sh`, `create-demo-users.sh` |
| `make up` / `down` / `restart` / `logs` | Compose start, stop, rebuild WordPress, follow logs |
| `make lint` / `test` / `smoke` / `check` | `lint.sh`, `run-tests.sh`, `smoke-test.sh`, all three |
| `make wp ARGS="…"` | WP-CLI against the main site |
| `make login` | Shows local admin and demo credentials (local only) |
