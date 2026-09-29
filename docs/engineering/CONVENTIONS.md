# Engineering conventions

Rules for everyone (people and agents) building the TMC website ecosystem. The specification is
[`docs/requirements/RTM.md`](../requirements/RTM.md); every change should move a requirement ID to ✅.

## Principles

1. **Government-grade by default** — GIGW 3.0, WCAG 2.2 AA, OWASP Top 10, no data leaves India, no vendor branding.
2. **TMC owns everything** — GPL/OSI-licensed components only, no premium/"pro" plugins, no SaaS lock-in, no external CDNs at runtime. Pin every third-party version.
3. **Reproducible** — every environment is built from this repo by `scripts/setup.sh`. Never change a server by hand; data changes ship as migrations.
4. **Tested** — a feature is not done until a test proves it (PHP test suite, smoke check, or CI job).

## Where code goes

| What | Where | Notes |
|---|---|---|
| Data model, security, integrations, anything theme-independent | `src/mu-plugins/tmc-core/<feature>.php` | Auto-loaded. Declare functions/hooks only; prefix `tmc_`. |
| Admin-only JS/CSS for a module | `src/mu-plugins/tmc-core/admin/` | Enqueue only on the screens that need it. |
| Presentation (templates, template parts) | `src/themes/tmc/*.php`, `template-parts/` | Follow the template hierarchy. |
| Theme PHP helpers | `src/themes/tmc/inc/<feature>.php` | Auto-loaded. |
| Front-end styles for a feature | `src/themes/tmc/assets/css/features/<feature>.css` | Auto-enqueued after `main.css`. Use the design tokens (`var(--c-primary)` …) — no hard-coded brand colours. Include high-contrast overrides. |
| Front-end JS for a feature | `src/themes/tmc/assets/js/features/<feature>.js` | Auto-enqueued, deferred. Vanilla JS, no jQuery on the front end, progressive enhancement (must work without JS). |
| Dynamic blocks | register in `inc/<feature>.php`; editor side in `assets/js/blocks-editor.js` or a feature editor script | Server-rendered. |
| Data changes to existing sites | `scripts/migrations/NNN-<name>.php` | Run once per site, return `true`; see numbering below. |
| Tests (PHP, WP-CLI) | `scripts/tests/<feature>-test.php` | Auto-run by CI and `make test`. Create your own fixtures, clean up after. |
| HTTP smoke checks | `scripts/smoke.d/<feature>.sh` | Call `check HOST PATH STATUS [must-contain…]`. |
| New containers | `docker-compose.yml` | Keep DB-facing services on `tmc_internal` only. |
| CI jobs | `.github/workflows/<feature>.yml` as a reusable workflow (`on: workflow_call`) | The integrator wires it into `pipeline.yml`. |
| Documentation | `docs/<area>/…md` | Markdown; plain English; no marketing language. |

### Migration numbers (avoid collisions)

| Range | Workstream |
|---|---|
| 001–009 | core (done: 001 content types) |
| 010–019 | W1 search and document library |
| 020–029 | W2 security |
| 030–039 | W3 SEO, redirects, analytics, migration toolkit |
| 040–049 | W4 application gateway, maps, social |
| 050–059 | W5 editorial platform |
| 060–069 | W6 quality gates |
| 070–079 | W7 performance, backup/DR |

## Coding standards

- **PHP 8.3**, WordPress coding standards style (tabs, `array()`, Yoda not required). Every output escaped (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`); every input sanitised; nonces + capability checks on every write; `$wpdb->prepare` for every query with input.
- **Strings**: English first. Wrap user-facing theme text in `__( '…', 'tmc' )` (theme) so Hindi can be added later in `languages/hi_IN.l10n.php`. Admin-only strings in the mu-plugin may be plain English.
- **Accessibility**: semantic HTML, labels for every control, visible focus, 44×44 px targets, `aria-*` only when native semantics are not enough, announce dynamic results (`role="status"`), respect `prefers-reduced-motion`.
- **Performance**: no render-blocking third-party resources; lazy-load images; keep feature CSS/JS small; cache expensive queries (`wp_cache_*`, object cache is Redis).
- **Security**: never store patient/clinical data; secrets only from environment (`getenv`) — never in code or the database; log security-relevant actions with `tmc_audit()`.
- **Multisite**: remember every site has its own tables; network-wide data uses `get_site_option` / `$wpdb->base_prefix`.
- **Polylang**: new public post types are translatable via the `pll_get_post_types` filter (see `content-types.php`).

## Verifying your work

The full stack cannot run in parallel on one machine (port 80, fixed container names), so **CI is the
test environment for feature branches**:

```bash
./scripts/lint.sh                       # fast, local, no stack needed
git push origin feat/<workstream>       # CI builds all six sites and runs every test + smoke check
gh run watch "$(gh run list --branch feat/<workstream> --limit 1 --json databaseId --jq '.[0].databaseId')" --exit-status
gh run view <id> --log-failed            # read failures
```

Branches `feat/**` get full CI; only `main` deploys.

## Git

- Branch: `feat/<workstream-slug>`; small, focused commits; message = what and why.
- End every commit message with the co-author trailer used in this repo.
- Never commit `.env`, passwords, `demo-users.txt`, backups or generated reports.
