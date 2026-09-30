# TMC Website Ecosystem

WordPress Multisite for the Tata Memorial Centre EOI (TMH/TMH/2026-27/CAP/EO/0009):
**one CMS, one codebase, one security framework, six websites** in English and Hindi,
built to GIGW 3.0 and WCAG 2.2 AA.

| # | Site | Local (dev) | UAT (hetser, Tailscale only) |
|---|------|-------------|------------------------------|
| 1 | Tata Memorial Centre (umbrella) | http://tmc.localhost | http://tmc.100-79-142-44.sslip.io |
| 2 | Tata Memorial Hospital, Mumbai | http://tmh.tmc.localhost | http://tmh.tmc.100-79-142-44.sslip.io |
| 3 | HBCH & RC, Visakhapatnam | http://hbchrcv.tmc.localhost | http://hbchrcv.tmc.100-79-142-44.sslip.io |
| 4 | MPMMCC & HBCH, Varanasi | http://mpmmcc.tmc.localhost | http://mpmmcc.tmc.100-79-142-44.sslip.io |
| 5 | HBCH & RC, Muzaffarpur | http://hbchrcmzp.tmc.localhost | http://hbchrcmzp.tmc.100-79-142-44.sslip.io |
| 6 | HBCH, New Chandigarh | http://hbchpunjab.tmc.localhost | http://hbchpunjab.tmc.100-79-142-44.sslip.io |

Hindi versions are under `/hi/` on every site.

## Workflow

```
 Mac (dev)                     GitHub (private repo)                     hetser (UAT)
 ─────────                     ─────────────────────                     ────────────
 edit code, make check  ──push──►  Pipeline
                                   1. Lint · operations · gateway · docs/licences
                                   2. in parallel, each on a fresh 6-site stack:
                                      integration (PHP suites + smoke test)
                                      security gate (gitleaks, Trivy, OWASP ZAP)
                                      quality gates (E2E ×3 browsers, visual,
                                        axe, HTML validity, Lighthouse, links,
                                        Go-Live acceptance report)
                                      DR drill · capacity test
                                   3. main only, all green ──────────►  self-hosted runner
                                                                          backup → sync → provision
                                                                          → proxy route → smoke test
```

- Work on a branch, open a pull request: CI runs on GitHub's machines only.
- Merge to `main`: the same checks run again, then the release is deployed to UAT.
- **Production:** push a tag `vX.Y.Z` on the commit UAT runs → *Release to production* (gate, re-test,
  manual approval). Environments, promotion and rollback: `docs/operations/environments.md`.
- **Rollback / redeploy:** Actions → *Pipeline* → *Run workflow* → enter an older commit or tag.
  Every deploy first saves a database backup in `~/docker/tmc-website/backups/` (last 10 kept).
- Release history on the server: `~/docker/tmc-website/.release-history`.

## Local development

Requires Docker Desktop. Port 80 must be free.

```bash
make setup     # first time: .env, build, install all six sites, demo accounts
make up        # start          make down   # stop (data kept)
make check     # everything CI runs: lint + tests + smoke test
make wp ARGS="site list"
make login     # local admin + demo passwords
```

Code you edit in `src/` is live immediately (bind-mounted). Content lives in the database and is
created by the idempotent seed scripts, so a fresh machine or CI ends up with the same sites.

## Layout

| Path | What |
|---|---|
| `src/themes/tmc/` | GIGW theme: design tokens (`theme.json`), templates, accessibility bar, mega-menu, blocks, Hindi UI strings |
| `src/mu-plugins/tmc-core/` | Roles, review workflow, audit log, content types, search, documents, security, SEO, redirects, analytics, application gateway, editorial governance, network publishing, page cache, health |
| `src/mu-plugins/tmc-page-cache/` | Full-page cache engine (loaded by the `advanced-cache.php` drop-in) |
| `scripts/migrations/` | Run-once data migrations per site (001 core, 010–011 search, 030 redirects, 040 gateway, 050 editorial, 060–061 quality) |
| `scripts/import/` | Content inventory importer for migration ([docs/migration/importer.md](docs/migration/importer.md)) |
| `security/` | Accepted-finding files of the security gate (ZAP rules, Trivy ignore list, gitleaks config) |
| `.github/workflows/` | `pipeline.yml` (CI/CD) calling `security.yml`, `quality.yml`, `dr-drill.yml`, `capacity.yml`, `ops-checks.yml`, `apps-gateway.yml`, `docs.yml`; `release.yml` (production), `load-test.yml`, `updates.yml` |
| `scripts/setup.sh` | Brings any environment to the expected state (runs on every deploy) |
| `scripts/seed-*.php`, `setup-languages.php` | Sites, languages, pages, menus, home sections |
| `scripts/deploy.sh` | Server deploy (backup → sync → provision → proxy → smoke test) |
| `scripts/tests/`, `smoke-test.sh`, `lint.sh` | Tests used locally and in CI |
| `tests/` | Quality gates: E2E, visual, axe, Lighthouse, HTML validity, links, k6, Go-Live acceptance report ([docs/testing/quality-gates.md](docs/testing/quality-gates.md)) |
| `docker-compose.yml` + `compose.local.yml` / `compose.server.yml` | Base stack + per-environment override (chosen by `COMPOSE_FILE` in `.env`) |
| `wordpress/` | Image: WordPress + PHP 8.3 + phpredis, hardened Apache/PHP config |
| `mock/tmc-apps/` | DEMO-only mock of the TMC application backends (isolated `tmc_apps` network) — see `docs/integration/gateway.md` |
| `nginx/tmc-website.conf` | UAT route in nginx-proxy-manager |
| `backup/`, `scripts/backup/`, `scripts/dr/` | 15-minute backups, off-host copy, restore, DR drill (`docs/operations/backup-and-dr.md`) |
| `scripts/sla/`, `scripts/perf/`, `scripts/ops/` | monthly availability report, capacity test, update check |
| `docs/operations/` | environments, backup/DR, monitoring, patching, capacity |
| `docs/` | Requirements (RTM), architecture, operations, manuals, training, testing, governance, proposal, EOI pack |
| `scripts/docs/`, `scripts/licenses.sh`, `scripts/reports/` | Documentation build (DOCX/PDF) and link check, licence inventory, defect closure report |

## Documentation

The [Documentation Register](docs/README.md) lists every document with its ID and RTM references.
Markdown in `docs/` is the master copy; `./scripts/docs/build-docs.sh` produces the editable (DOCX) and
portable (PDF) set in `dist/docs/`.

| Area | Start here |
|---|---|
| Specification | [Requirements Traceability Matrix](docs/requirements/RTM.md) · [Engineering conventions](docs/engineering/CONVENTIONS.md) |
| Architecture (M2) | [System](docs/architecture/system-architecture.md) · [Security](docs/architecture/security-architecture.md) · [Interfaces](docs/architecture/integration-interfaces.md) · [Data model](docs/architecture/data-model.md) · [Data residency](docs/architecture/data-residency-statement.md) · [Access control](docs/architecture/access-control-policy.md) · [Audit-log retention](docs/architecture/audit-log-retention-policy.md) |
| Operations | [Installation and deployment](docs/operations/installation-deployment.md) · [Configuration](docs/operations/configuration-reference.md) · [Backup and restore](docs/operations/backup-restore.md) · [Incident and support (SLA)](docs/operations/incident-support-model.md) · [Patch management](docs/operations/patch-management.md) · [Warranty and AMC](docs/operations/warranty-amc-plan.md) · [Handover checklist](docs/operations/handover-checklist.md) |
| Manuals | [CMS administrator](docs/manuals/cms-administrator-manual.md) · [System and security administration](docs/manuals/system-security-administration-manual.md) · [Content editor](docs/manuals/content-editor-manual.md) · [Quick reference cards](docs/manuals/quick-reference/README.md) |
| Training and testing | [Training plan](docs/training/training-plan.md) · [Test plan](docs/testing/test-plan.md) |
| Governance | [Execution plan M1–M6](docs/governance/execution-plan.md) · [RACI](docs/governance/raci-team-structure.md) · [Risk register](docs/governance/risk-register.md) · [Change requests](docs/governance/change-request-procedure.md) |
| Bid | [Technical proposal (SOW §12.1)](docs/proposal/README.md) · [EOI response pack](docs/eoi/README.md) · [Third-party licences](docs/THIRD-PARTY-LICENSES.md) |

## Features (tender mapping)

| Tender | Implementation |
|---|---|
| §4.1 six sites, common CMS | WordPress Multisite, subdomains |
| §4.3 templates, code-free editing | Theme templates + locked (`contentOnly`) section patterns; page-template picker with seven locked starter templates; living component library; approved-blocks governance ([docs/editorial/editorial-platform.md](docs/editorial/editorial-platform.md)) |
| §4.6 centralised publishing | TMC publishes news, notices and events once to selected unit sites (read-only synced copies that follow the original) |
| §4.6 document library, §4.12 search | Document types and dates, `/documents/` library with filters, network media overview; ranked site search including text inside PDFs, facets, accessible suggestions combobox ([docs/features/search-and-documents.md](docs/features/search-and-documents.md)) |
| §4.6 roles + review workflow | Content Editor → Reviewer / Publisher → Site Admin → Super Admin; review queue, return-with-note |
| §4.6 version history, audit trail | Revisions + HMAC-chained audit log with integrity check and CSV export |
| §4.8 segregation | DB/cache on an internal network with no internet and no host ports |
| §4.8 secured admin access, OWASP | TOTP two-factor mandatory for privileged roles, admin network allow-list, login lockout, no user enumeration, nonce-based CSP, security.txt; CI security gate (gitleaks, Trivy, OWASP ZAP) — see `docs/security/` |
| §4.4, §4.12 TMC applications | Allow-listed server-side gateway (`/wp-json/tmc/v1/apps/…`) + appointment, results, online form and donation front ends; nothing stored ([spec](docs/integration/gateway.md)) |
| §4.9 accessibility | Skip link, text size, high contrast, keyboard mega-menu, focus ring, pause for moving content |
| §4.10 SEO | Clean URLs, hreflang, editor metadata, JSON-LD, environment-aware robots and sitemaps, redirect manager (301/302/410, CSV), Matomo (cookieless) / GA4 (consent denied) off until configured ([docs/seo/seo-redirects-analytics.md](docs/seo/seo-redirects-analytics.md)) |
| §4.11 content migration | CSV inventory importer (dry run, idempotent, redirects, report); link and orphan crawler |
| §4.9, §4.14, §7.1 quality | Every template on every site: axe WCAG 2.2 AA, W3C HTML validity, Lighthouse budgets, E2E in Chromium/Firefox/WebKit at 360/768/1280 px, visual regression, link crawl; Go-Live acceptance report per site ([docs/testing/quality-gates.md](docs/testing/quality-gates.md)) |
| §4.13 multilingual | Polylang: English + Hindi, more languages without code changes |
| §4.7 environments, promotion, rollback | Dev (local) → CI → UAT → Production (tag + approval) → DR; backup per deploy |
| §4.7 RPO 15 min / RTO 1 h, backups | backup every 15 min (DB + files + config, checksummed, retention), off-host copy, timed DR drill in CI |
| §4.7 peak load | Redis object cache + full-page cache with purge on change, Brotli, static caching; k6 capacity test |
| §6 SLA 99.5 %, patching | `/wp-json/tmc/v1/health` for Uptime Kuma, monthly availability report; Dependabot + update check |
