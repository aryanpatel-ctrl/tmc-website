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
                                   1. Lint (PHP, JSON, JS, shell)
                                   2. Integration: build all 6 sites,
                                      run workflow tests + smoke test
                                   3. main only ─────────────────────►  self-hosted runner
                                                                          backup DB → sync → provision
                                                                          → proxy route → smoke test
```

- Work on a branch, open a pull request: CI runs on GitHub's machines only.
- Merge to `main`: the same checks run again, then the release is deployed to UAT.
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
| `src/mu-plugins/tmc-core/` | Roles, review workflow, tamper-evident audit log |
| `scripts/setup.sh` | Brings any environment to the expected state (runs on every deploy) |
| `scripts/seed-*.php`, `setup-languages.php` | Sites, languages, pages, menus, home sections |
| `scripts/deploy.sh` | Server deploy (backup → sync → provision → proxy → smoke test) |
| `scripts/tests/`, `smoke-test.sh`, `lint.sh` | Tests used locally and in CI |
| `docker-compose.yml` + `compose.local.yml` / `compose.server.yml` | Base stack + per-environment override (chosen by `COMPOSE_FILE` in `.env`) |
| `wordpress/` | Image: WordPress + PHP 8.3 + phpredis, hardened Apache/PHP config |
| `mock/tmc-apps/` | DEMO-only mock of the TMC application backends (isolated `tmc_apps` network) — see `docs/integration/gateway.md` |
| `nginx/tmc-website.conf` | UAT route in nginx-proxy-manager |

## Features (tender mapping)

| Tender | Implementation |
|---|---|
| §4.1 six sites, common CMS | WordPress Multisite, subdomains |
| §4.3 templates, code-free editing | Theme templates + locked (`contentOnly`) section patterns |
| §4.6 roles + review workflow | Content Editor → Reviewer / Publisher → Site Admin → Super Admin; review queue, return-with-note |
| §4.6 version history, audit trail | Revisions + HMAC-chained audit log with integrity check and CSV export |
| §4.8 segregation | DB/cache on an internal network with no internet and no host ports |
| §4.8 secured admin access, OWASP | TOTP two-factor mandatory for privileged roles, admin network allow-list, login lockout, no user enumeration, nonce-based CSP, security.txt; CI security gate (gitleaks, Trivy, OWASP ZAP) — see `docs/security/` |
| §4.4, §4.12 TMC applications | Allow-listed server-side gateway (`/wp-json/tmc/v1/apps/…`) + appointment, results, online form and donation front ends; nothing stored ([spec](docs/integration/gateway.md)) |
| §4.9 accessibility | Skip link, text size, high contrast, keyboard mega-menu, focus ring, pause for moving content |
| §4.10 SEO | Clean URLs, hreflang, sitemap, per-page last-updated |
| §4.13 multilingual | Polylang: English + Hindi, more languages without code changes |
| §4.7 environments, promotion, rollback | Dev (local) → CI → UAT via this pipeline; DB backup per deploy |
