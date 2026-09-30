# Patching and upgrades

Requirement: **R-6-3** — upgrades of core, plugins, frameworks and the database; **security patches
within 30 days, critical ones on priority**. Every change goes through the same pipeline as a
feature ([environments.md](environments.md)): pull request → CI → UAT → release tag → approval →
production.

## Deadlines

The tender sets 30 days, and "on priority" for critical patches. The tighter times below are the
proposed commitment, subject to TMC's approval.

| Severity (vendor advisory / CVSS v3) | Examples | Patched on production within |
|---|---|---|
| **Critical** (CVSS ≥ 9.0, or actively exploited, or CERT-In advisory marked critical) | unauthenticated remote code execution or SQL injection in WordPress, a plugin, PHP, Apache | **72 hours** from publication; emergency release with the same gates |
| High (7.0–8.9) | authenticated privilege escalation, stored XSS | 14 days |
| Medium / Low | information disclosure, hardening | 30 days |
| Non-security | feature releases, minor versions | next planned release (at least monthly) |

If a fix is not available in time: apply a mitigation (disable the feature or plugin, block the path
at the proxy or in `wordpress/apache-tmc.conf`, restrict access) within the same deadline and record
it in the monthly support report (R-6-5). The clock starts when the advisory is published, not when
we notice it — hence the automated checks below.

## Where each component is pinned and how updates arrive

| Component | Pinned in | How updates are found |
|---|---|---|
| GitHub Actions | `.github/workflows/*.yml` (`@v5` …) | Dependabot PR (weekly, Monday 04:00 IST) |
| WordPress + PHP 8.3 + Apache image | `wordpress/Dockerfile` (`FROM wordpress:php8.3-apache`) | Dependabot PR; `updates.yml` compares the WordPress release inside the image with wordpress.org. Minor WordPress releases (security) also apply automatically (`WP_AUTO_UPDATE_CORE=minor`) |
| MariaDB | `docker-compose.yml` (`mariadb:11.4`, LTS) **and** `backup/Dockerfile` — keep both on the same release | Dependabot PRs (minor/patch); majors are planned (below) |
| Redis | `docker-compose.yml` (`redis:7-alpine`) | Dependabot PR (minor/patch) |
| WP-CLI image | `docker-compose.yml` (`wordpress:cli-php8.3`) | Dependabot PR |
| Polylang | `scripts/setup.sh` (`POLYLANG_VERSION`) | `.github/workflows/updates.yml` weekly → tracking issue "Updates available for pinned WordPress components" |
| Redis Object Cache | `scripts/setup-cache.sh` (`REDIS_CACHE_VERSION`) | same |
| Any plugin added later | `NAME_VERSION="x.y.z"` in a provisioning script (the name is the wordpress.org slug in capitals, `-` as `_`) | picked up by `scripts/ops/check-updates.sh` automatically |
| k6 (test tool only) | `scripts/perf/capacity-test.sh`, `.github/workflows/ops-checks.yml` | checked by hand quarterly |
| Uptime Kuma (monitoring host) | its `compose.yml` ([monitoring.md](monitoring.md)) | checked by hand monthly |

Also watch: WordPress security releases (wordpress.org/news, category Security), CERT-In advisories,
the plugins' changelogs, PHP and MariaDB security announcements. Dependabot **security** alerts and
PRs are switched on in the repository settings (Settings → Code security) and do not wait for the
weekly schedule.

Run the plugin/core check by hand at any time: `scripts/ops/check-updates.sh` (exit 3 = updates available).

## Applying an update

1. Branch `deps/<component>-<version>`; change the pin (or merge the Dependabot PR branch). Read the
   component's changelog for breaking changes and note them in the PR.
2. CI builds all six sites from nothing with the new version and runs every test suite and smoke
   check. For a database, Redis or PHP change, also run the DR drill workflow (Actions → *DR drill*):
   restoring an older backup onto the new version must work.
3. Merge → UAT deploy (backup first; smoke test after). Check the sites and the Health & Backups screen.
4. Tag and release to production (approval). For a critical fix this is the same day; the gates are
   not skipped, they take about 30 minutes.
5. Record it: the release tag message names the component, old → new version and the advisory ID;
   the monthly support report lists patches applied with dates.

Rollback: [environments.md → Rollback](environments.md#rollback). A plugin downgrade is a pin change
like any other; a database major version cannot be downgraded in place — restore the pre-deploy
backup instead.

## Major upgrades (planned, not automatic)

MariaDB and Redis major versions, a new PHP minor version and a new WordPress major version are
planned changes: announce a maintenance window to TMC, take a manual backup
(`docker compose exec backup tmc-backup run manual`), run the DR drill on the new version in CI, test
on UAT for at least one week, then release. MariaDB: move between LTS releases (11.4 → next LTS) and
update `backup/Dockerfile` in the same pull request, so dumps are always made by the server's own
`mariadb-dump`.
