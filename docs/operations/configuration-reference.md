# Configuration Reference

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-OPS-02 | 0.1 | Draft; variables of parallel work streams to be added at integration | R-4.15-3, R-8.2-4 |

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
| `TMC_ENV` | `local`, `ci`, `server` | Scripts (messages); W7 environment logic (verify at integration) | Environment name | Yes |
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

### 1.1 Variables added by parallel work streams (verify at integration)

The names below are placeholders for the categories expected; the integrator replaces this table with
the actual names and defaults from the merged code.

| Work stream | Expected settings |
|---|---|
| W2 Security | MFA enforcement for privileged roles; administrative network allow-list (CIDR list); login rate-limit thresholds |
| W3 SEO/analytics | Search-engine visibility per environment; analytics endpoint/site ID (TMC-approved tool) |
| W4 Gateway | Per-endpoint base URLs and credentials for TMC applications; payment gateway merchant parameters |
| W7 Backup/DR/monitoring | Backup destination and credentials; backup schedule; retention; DR target; monitoring endpoint |
| Mail (TMC relay) | SMTP host, port, user, password, sender address (EOI query Q-23) |

## 2. Docker Compose

### 2.1 `docker-compose.yml` (base, all environments)

| Service | Key settings |
|---|---|
| `db` | `mariadb:11.4.13`; `--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --max-allowed-packet=64M`; volume `db_data`; network `tmc_internal`; health check `healthcheck.sh --connect --innodb_initialized` every 10 s |
| `redis` | `valkey/valkey:8.1.10-alpine` (Redis-compatible, BSD-3-Clause); `valkey-server --maxmemory 256mb --maxmemory-policy allkeys-lru --save ""` (no persistence); network `tmc_internal` |
| `wordpress` | Built from `./wordpress` as `tmc-wordpress:latest`; depends on healthy `db`; environment `*wp-env`; volumes `wp_html`, `src/mu-plugins` (ro), `src/themes/tmc` (ro); networks `tmc_internal`, `tmc_edge` |
| `wpcli` | `wordpress:cli-php8.3`, user `33:33`, profile `tools` (not started by `up`); mounts `scripts/` read-only at `/tmc-scripts` |
| `cron` | `wordpress:cli-php8.3`, user `33:33`; loop: for each site `wp cron event run --due-now`, then `sleep 60`; network `tmc_internal` only |

Networks: `tmc_internal` (`internal: true`), `tmc_edge` (default bridge with internet). Volumes:
`db_data`, `wp_html`.

### 2.2 WordPress constants (`WORDPRESS_CONFIG_EXTRA`)

| Constant | Value | Why |
|---|---|---|
| `WP_ENVIRONMENT_TYPE` | `staging` | Environment type reported to WordPress (Production value set by W7, verify at integration) |
| `DISALLOW_FILE_EDIT` | `true` | No theme/plugin editor in the admin |
| `WP_MEMORY_LIMIT` | `256M` | PHP memory for WordPress |
| `WP_AUTO_UPDATE_CORE` | `minor` | Minor core updates allowed; see [Patch Management §4](patch-management.md#4-how-updates-are-applied) for how updates are actually applied |
| `WP_REDIS_HOST`, `WP_REDIS_PORT`, `WP_CACHE_KEY_SALT` | `redis`, `6379`, `tmc_` | Object cache connection settings |
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
| `compose.server.yml` | `wordpress.networks: [tmc_internal, tmc_edge, homelab]`; external network `homelab` |
| Production/DR overrides | W7 (verify at integration) |

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
| Search-engine visibility (`blog_public`) | `0` (discouraged) | `install-network.sh` (Production handled by W3, verify at integration) |
| Active theme | `tmc` (network-enabled) | `setup.sh` |
| Plugin | Polylang `3.8.10`, network-active | `setup.sh` (`POLYLANG_VERSION`) |
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
TLS and the proxy server names at the same time. **Verify at integration** whether W3 redirects need
entries for the old domain.

## 6. CI/CD configuration

| Item | Value | File |
|---|---|---|
| Triggers | push to `main` and `feat/**`; pull requests; manual run with input `ref` | `.github/workflows/pipeline.yml` |
| Jobs | `lint` → `integration` → `deploy` (main or manual only) | same |
| Runners | `ubuntu-latest` (lint, integration); `[self-hosted, tmc-server]` (deploy) | same |
| Concurrency | One pipeline per ref (feature branches cancel older runs); deployments serialised in group `deploy-uat` | same |
| Permissions | `contents: read` | same |
| Pinned action | `actions/checkout@v5` | same |
| Reusable workflows from work streams | Wired by the integrator (verify at integration) | `.github/workflows/*.yml` |

## 7. Make targets (developer convenience)

| Target | Runs |
|---|---|
| `make setup` | `make-env.sh local`, `setup.sh`, `create-demo-users.sh` |
| `make up` / `down` / `restart` / `logs` | Compose start, stop, rebuild WordPress, follow logs |
| `make lint` / `test` / `smoke` / `check` | `lint.sh`, `run-tests.sh`, `smoke-test.sh`, all three |
| `make wp ARGS="…"` | WP-CLI against the main site |
| `make login` | Shows local admin and demo credentials (local only) |
