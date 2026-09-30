# Quality gates and the Go-Live acceptance report

Automated checks that every release of the six TMC websites must pass, and the report that
turns their results into the Go-Live acceptance checklist of SOW §7.1. Pass marks and their
justification: [thresholds.md](thresholds.md).

| Gate | Tool (pinned) | What it proves | RTM |
|---|---|---|---|
| E2E | Playwright 1.63.0: Chromium, Firefox, WebKit at 360, 768 and 1280 px | Templates, navigation, forms and listings work in every major browser engine at phone, tablet and desktop widths | R-4.14-2, R-4.14-3, R-4.9-2, R-4.9-3 |
| Visual regression | Playwright screenshots (Chromium, pinned container) | The approved look has not changed | R-5-1 |
| Accessibility | axe-core 4.13.0 (`@axe-core/playwright`) | No automatically detectable WCAG 2.0/2.1/2.2 A or AA failure on any template of any site | R-4.9-1 |
| Performance | Lighthouse CI 0.15.1 | Performance, accessibility, best-practice and SEO budgets per template, desktop and mobile | R-4.10-6, R-4.14-4 |
| HTML validity | Nu HTML Checker (vnu-jar 26.9.27, the W3C validator engine) | Valid HTML on every template of every site | R-4.8-6 |
| Links | `tests/links/crawl.js` | No broken internal links and no orphaned pages on any site, in English and Hindi | R-4.11-4, R-4.11-3 |
| Load | k6 2.3.0 (`grafana/k6` image) | Response times and error rate under simulated peak traffic on UAT | R-4.14-4, R-4.7-9 |
| Acceptance report | `tests/report/acceptance.js` | SOW §7.1 checklist per website: PASS / FAIL / MANUAL with evidence | R-7.1-1 |

All tools are open source (Apache-2.0, MPL-2.0, MIT, AGPL-3.0 for k6, which runs only as a
separate container and is not distributed with the websites). Versions are pinned in
`tests/package.json` and `tests/package-lock.json`, and the container images and GitHub Actions
are pinned in the workflows. The tools run only in CI or on a developer machine. Nothing from them
is loaded by the websites.

## Layout

```
tests/
  package.json, package-lock.json   pinned tooling (npm ci)
  playwright.config.js               suites e2e | visual | a11y (QUALITY_SUITE)
  lib/sites.js                       the six sites and ONE URL per template type (used by every tool)
  e2e/*.spec.js                      functional tests, tagged (@e2e + feature tag)
  visual/visual.spec.js              screenshot comparison; baselines in visual/baselines/*-linux.png
  a11y/axe.spec.js, report.js        axe scan of every template on every site + HTML/JSON report
  lighthouse/run.js, budgets.json    Lighthouse CI per template, desktop + mobile
  html/validate.js                   Nu HTML Checker
  links/crawl.js                     crawler: broken links, missing fragments, orphans (CSV + HTML)
  load/tmc-load.k6.js, report.js     k6 load test + report
  report/acceptance.js               Go-Live acceptance report per website + job summary
  ci/run-gate.sh                     one entry point per gate (used by CI; works locally)
  bin/check-syntax.js                static check of the tooling itself (npm run check)
  bin/failures.js, step-summary.js   failure list, GitHub job summary
scripts/published-urls.php           published URL inventory of a site, from the database (orphans)
.github/workflows/quality.yml        reusable workflow: all gates + acceptance report
.github/workflows/load-test.yml      manual load test against UAT
.github/actions/tmc-stack/           composite action: fresh stack + pinned tooling
```

Every result goes to `$QUALITY_OUT/<suite>/` (default `tests/results/<suite>/`, not committed):
`summary.json` (or Playwright's `results.json`), `report.html` or `html-report/`, and CSV files
for links. The acceptance report reads them from the same place.

## Running in CI

`.github/workflows/quality.yml` is a reusable workflow (`on: workflow_call`). It also has a
manual trigger, used to update the visual baselines.

1. **Quality tooling check**: `npm run check` (syntax, JSON, every Playwright suite loads),
   `k6 inspect` for every load profile, `bash -n` of the gate script.
2. **Six gates in parallel**, each on its own fresh stack built by `.github/actions/tmc-stack`
   (`scripts/make-env.sh ci` → `scripts/setup.sh` → `scripts/create-demo-users.sh`, `/etc/hosts`
   entries for the six host names, pinned Node, `npm ci`, Playwright browsers, Java). Each gate
   runs `tests/ci/run-gate.sh <gate>`, writes its section of the job summary, and uploads the
   artifact `quality-<gate>`. A failing gate does not stop the others.
3. **Go-Live acceptance report**: always runs. It merges all gate results, builds the report
   for each website, adds the summary table to the job summary, uploads everything as the
   **`quality-reports`** artifact (kept 90 days), and fails if any gate failed.

Open `acceptance/index.html` in the `quality-reports` artifact for the reports per website. Each
report links to the detailed reports (Playwright HTML report, axe report, Lighthouse reports,
validator report, link report).

Wiring into `pipeline.yml` (the integrator's step):

```yaml
  quality:
    name: Quality gates
    needs: lint
    uses: ./.github/workflows/quality.yml
    with:
      ref: ${{ inputs.ref || github.sha }}
```

and add `quality` to `needs:` of the `deploy` job, so a release reaches UAT only when every gate
has passed.

## Running on a developer machine

The local stack must be up (`make setup` / `make up`). The tools only read from the websites.

```bash
cd tests
npm ci                                  # pinned tooling
npx playwright install chromium firefox webkit
npm run check                           # static check of the tooling

npm run e2e                             # all browsers and widths
QUALITY_BROWSERS=chromium npm run e2e   # one browser
npx playwright test --grep @tenders     # one feature (QUALITY_SUITE defaults to e2e)
QUALITY_LOCALHOST_PROXY=1 npm run e2e   # macOS: WebKit cannot resolve *.localhost without it

npm run a11y && npm run a11y:report     # → results/a11y/report.html
npm run html                            # needs Java 11+
npm run lighthouse                      # LIGHTHOUSE_TEMPLATES=home,tenders to limit
npm run links                           # LINKS_EXTERNAL=0 to skip external links
npm run report                          # → results/acceptance/index.html
```

Or run a gate exactly as CI does: `tests/ci/run-gate.sh a11y`. The `links` gate also reads the
published URL inventory with WP-CLI. The `visual` gate uses the pinned Playwright container.

`TMC_BASE_DOMAIN` and `TMC_SCHEME` point the tools at another environment. For example,
`TMC_BASE_DOMAIN=tmc.100-79-142-44.sslip.io npm run links` crawls UAT. Without a database
inventory, the crawler then takes the published URLs from the REST API or the XML sitemap.

## Pages under test: one URL per template type

`tests/lib/sites.js` lists the six sites and one representative URL for each template type: home
(EN/HI), page with section navigation, child page, Hindi page, policy page, sitemap (EN/HI), news
list and item, tender list (current, archive, Hindi) and single tender, careers list and job, event
list, calendar and single event, department list and single, Find a Doctor and doctor profile,
search results and 404. Flags choose which tools use a page (`lighthouse`, `visual`). **A new
template is added in this one place** and is picked up by E2E (`templates.spec.js`), axe, the
validator, Lighthouse and visual regression.

The single-item URLs point to the clearly labelled **sample** content that `setup.sh` seeds on every
environment. Before Go-Live, when the samples are removed, point these templates at real content
with a JSON file: `QUALITY_URLS_FILE=urls.json`, containing `{ "tender-single": "/tenders/<slug>/", … }`.

## E2E tests and tags

Every test has `@e2e` plus one feature tag: `@home`, `@nav`, `@prefs`, `@i18n`, `@search`,
`@tenders`, `@events`, `@doctors`, `@404`, `@responsive`, and `@no-js` for the checks run without
JavaScript. Tests that concern one website also have `@site-<id>` (the acceptance report counts them
for that website). Tests of shared templates run on the TMC site and count for every website,
because all six use the same templates.

| Spec | Covers |
|---|---|
| `home.spec.js` | all six home pages (EN + HI): landmarks, one `h1`, skip link moves focus to `<main>`, network links to all six sites (no `/hi/hi/`), no JS errors, no sideways scrolling |
| `navigation.spec.js` | main menu disclosure pattern: Enter/Space opens, Escape closes and returns focus, one submenu open, mobile "Menu" button; current section, breadcrumbs; every submenu link reachable without JavaScript |
| `preferences.spec.js` | text size and high contrast: applied, `aria-pressed`, remembered across reloads and pages; largest text size without sideways scrolling |
| `language.spec.js` | English ⇄ Hindi switch goes to the translation of the same page, sets `lang` |
| `search.spec.js` | header search on every width, result count announced (`role="status"`), no-result message |
| `tenders.spec.js` | current list, archive, single tender with key facts, document type/size, PDF download |
| `events.spec.js` | upcoming/past, month calendar (table on wide screens, agenda on phones), month navigation, `.ics` |
| `doctors.spec.js` | Find a Doctor: filter by department (with and without JavaScript), by name, no-match case |
| `not-found.spec.js` | real 404 status, search and a way home |
| `templates.spec.js` | every template type: status, one `h1`, `<main>`, one meta description, no JS errors, no sideways scrolling |

A new feature adds `tests/e2e/<feature>.spec.js` with its own tag. Nothing else needs registering.

## Visual regression baselines

Baselines are screenshots of the approved look. They are made **inside the pinned Playwright
container of the CI job** (`mcr.microsoft.com/playwright:v1.63.0-noble`), so fonts and rendering
are the same on every run. Only `*-linux.png` files are committed; screenshots made on a Mac are
ignored by git.

What is captured: the full page of every template flagged `visual`, on one unit site
(`VISUAL_SITE`, default `hbchrcv`, which has only sample content with dates relative to the set-up
time), plus the first screen of the home page of all six sites, at 1280 and 360 px. Dates and times
are masked, animations are off and reduced motion is requested.

Until a baseline is approved, its test is **skipped and reported as "baseline missing"**. The
acceptance report shows visual regression as PENDING. It is never counted as a pass.

**Update procedure** (the first time, and after every approved design change):

1. Merge the design change to the branch whose look is to be approved.
2. Actions → **Quality gates** → Run workflow → choose the branch, tick **update-baselines**.
3. Download the artifact **`visual-baselines`** and review every image against the approved Figma
   design (Annexure C). Also review the `quality-visual` HTML report of the previous run, which
   shows the differences.
4. Copy the approved images to `tests/visual/baselines/` and commit them in a separate commit,
   "Approve visual baselines: <reason>", naming who approved them (design sign-off, R-5-1).
5. From then on, any unapproved visual difference fails the gate. When a difference is intended,
   repeat from step 2. Do not raise the tolerance to make a change pass.

## Accessibility scan

Every template on every site, at 1280 and 360 px, plus the high-contrast view of every template on
the TMC site: 350 scans. Any violation fails the gate. `a11y/report.js` writes `report.html`
(violations grouped by rule, with elements and pages) and `summary.json` (per site).

### Manual accessibility audit

Automated tools find only part of all WCAG failures. Before Go-Live, and after a major design
change, a tester completes this checklist for each website and signs it. The acceptance report
lists it as MANUAL.

- [ ] Keyboard only: every function reachable, visible focus everywhere, no keyboard trap, logical order (2.1.1, 2.1.2, 2.4.3, 2.4.7, 2.4.11)
- [ ] Screen readers: NVDA + Firefox (Windows), TalkBack + Chrome (Android), VoiceOver + Safari (iOS/macOS): landmarks, headings, menu, forms, tables, calendar, dynamic result counts
- [ ] Zoom to 200 % and reflow at 320 CSS px (400 %) without loss of content (1.4.4, 1.4.10)
- [ ] Text spacing override (1.4.12); content on hover or focus (1.4.13)
- [ ] Alternative text is meaningful (not only present) for every image; captions/transcripts for media (1.1.1, 1.2.x)
- [ ] Link text and headings make sense out of context, in English and Hindi (2.4.4, 2.4.6)
- [ ] Documents (PDF) are tagged and accessible, or an accessible alternative is offered (GIGW)
- [ ] Target size at least 24 × 24 CSS px, 44 × 44 for primary controls (2.5.8)
- [ ] Error identification and suggestions on every form (3.3.1, 3.3.3); no redundant entry (3.3.7); accessible authentication (3.3.8)
- [ ] Consistent help and navigation across pages (3.2.3, 3.2.6)
- [ ] Moving content can be paused (2.2.2); no flashing (2.3.1)
- [ ] GIGW 3.0 specifics: screen reader access page, accessibility statement, sitemap, last-updated date, bilingual switch, contact and feedback

## Lighthouse

`lighthouse/run.js` runs `lhci collect` and `lhci upload --target=filesystem` for each page and
form factor, using Playwright's pinned Chromium. It judges the representative run against
`budgets.json`. Full Lighthouse HTML reports are kept for every page. See thresholds.md for the
median-of-3 re-measurement and the `is-crawlable` exception outside production.

## HTML validity

`html/validate.js` fetches every template of every site as served, including markup added by
WordPress and Polylang, and validates all pages in one run of the Nu HTML Checker. Errors fail the
gate. Warnings are listed in the report.

## Links and orphaned pages

`links/crawl.js` starts at `/` and `/hi/` of every site and follows every link on every page of
the network. It checks pages, stylesheets, scripts, images and documents, and parses HTML pages for
further links. It records redirect chains and checks that `#fragment` targets exist. External links
are checked with HEAD (then GET) and a 10-second timeout, and reported as warnings. Search results,
filter combinations and calendar months are checked but not expanded, so the crawl is finite.
`wp-admin`, `wp-login.php` and `xmlrpc.php` are never requested.

**Orphans**: every published URL of each site (from the database via `scripts/published-urls.php`,
which uses `tmc_published_urls()` in `mu-plugins/tmc-core/quality.php`; otherwise the REST API or
the XML sitemap) that the crawl never reached. Output: `broken.csv`, `orphans.csv`,
`external.csv` (spreadsheet-safe), `report.html`, `summary.json`.

## Load test

`tests/load/tmc-load.k6.js` simulates visitors browsing all six sites in both languages. The mix is
weighted by likely traffic: home pages, content pages, tenders, events, careers, doctors, search,
404 and the stylesheet. Visitors wait 1–5 seconds between pages. Every response is checked for the
expected status, a complete page and no PHP error output.

It runs **only by hand, only from `main`, only against UAT**. It never runs in pull-request CI and
never against production. To run it: Actions → **Load test (UAT)** → Run workflow → choose a
profile. Run `smoke` first, then `peak` for Go-Live evidence. The job runs on the UAT server's
self-hosted runner, because UAT is reachable only over Tailscale. It waits for any deployment in
progress (same concurrency group). Because the load generator shares the machine with the websites,
the measured times are conservative. For certification-grade figures, run the same script from a
separate machine:

```bash
docker run --rm --network host -v "$PWD/tests:/work" -w /work grafana/k6:2.3.0 run \
  -e TMC_BASE_DOMAIN=<domain> -e LOAD_PROFILE=peak -e LOAD_SUMMARY=results/load/k6-summary.json \
  load/tmc-load.k6.js
node tests/load/report.js tests/results/load/k6-summary.json
```

The artifact `quality-load` contains `load/summary.json` and `report.html`. To add the result to
the Go-Live report, copy `load/` into the unpacked `quality-reports` bundle and run
`QUALITY_OUT=<bundle> node tests/report/acceptance.js`. Only a passing **peak** run counts.

## Go-Live acceptance report (SOW §7.1)

`tests/report/acceptance.js` writes `acceptance/<site>.html` for each website, plus `index.html`,
`summary.json` and `summary.md` (the job summary). Checklist items, in SOW order:

| Item | Source | Status |
|---|---|---|
| Design conformance (Annexures A–C) | visual regression result as evidence | MANUAL (TMC design sign-off) |
| Visual regression | Playwright visual suite | PASS / FAIL / PENDING (no approved baselines) |
| Zero broken links, no orphans | link crawler | PASS / FAIL |
| WCAG 2.2 AA, automated | axe | PASS / FAIL |
| WCAG 2.2 AA / GIGW, manual audit | checklist above | MANUAL |
| Responsive, major browsers, functional | E2E | PASS / FAIL |
| Performance per template | Lighthouse | PASS / FAIL |
| Load test | k6 peak profile (UAT) | PASS / FAIL / MANUAL (not in this bundle) |
| W3C HTML validity | Nu HTML Checker | PASS / FAIL |
| VAPT closed, segregation evidence | external | MANUAL |
| Safe-to-Host / STQC | external | MANUAL |
| Content migration confirmed | published URL inventory + link scan as evidence | MANUAL (TMC sign-off) |
| Documentation accepted | docs/ | MANUAL |

A gate that did not run is shown as NOT RUN and is never counted as passed. The automated verdict
is NOT READY (a FAIL), INCOMPLETE (NOT RUN or PENDING), or READY FOR MANUAL SIGN-OFF. Each report
ends with a sign-off table for the vendor and TMC.

## Defects found by the gates and fixed

| Found by | Defect | Fix |
|---|---|---|
| axe (color-contrast, WCAG 1.4.3) | "(ICS)" hint in the "Add to calendar" button was grey on blue (below 4.5:1) | `assets/css/features/quality-fixes.css` |
| E2E search @ 360 px | Header search box hidden below 768 px: no search on phones | `quality-fixes.css` (own row under the site name) |
| E2E Find a Doctor | Name filter used the reserved WordPress query variable `name`: every name search gave 404 or a redirect | field renamed `doctor_name` (`archive-tmc_doctor.php`, `inc/content-views.php`) |
| Visual regression | Search results and listings with equal dates or relevance came back in a different order on each request | `tmc_stable_orderby()` adds ID as the final sort key (`mu-plugins/tmc-core/quality.php`) |
| Link crawler (orphans) | WordPress "Hello world!" post and "Sample Page" published on all six sites | migration `060-remove-default-content` |
| Link crawler (broken) | Footer "Privacy Policy" on every English page linked to WordPress's draft privacy page (404) | migration 060 removes the draft; the seed creates the TMC Privacy Policy page and rebuilds the footer menus |
| Link crawler (broken) | TMC network block on Hindi pages linked to `/hi/hi/` for the current site | `tmc_network_sites()` uses each site's raw home address (`inc/template-tags.php`) |
| Lighthouse SEO | No meta description on any page | `inc/meta-description.php` (fallback, steps aside when an SEO module prints one) |
| Lighthouse SEO (link-text) | "Read more" on the unit home pages | "More about us": seed + migration `061-descriptive-link-text` |

Regression checks for these fixes: `scripts/tests/quality-gates-test.php` (WP-CLI, in the
integration test) and `scripts/smoke.d/quality-gates.sh`.

## Adding to the gates

- **New template**: one entry in `tests/lib/sites.js`. Set `lighthouse: true` and/or
  `visual: true` if it should be measured or screenshotted, then approve its visual baseline.
- **New feature test**: `tests/e2e/<feature>.spec.js` with `{ tag: ['@e2e', '@<feature>'] }`.
- **New site**: one entry in `SITES` in `tests/lib/sites.js`, and in `tests/load/tmc-load.k6.js`.
- **Changing a pass mark**: `tests/lighthouse/budgets.json` or the k6 thresholds, **plus** a dated
  entry in [thresholds.md](thresholds.md).
