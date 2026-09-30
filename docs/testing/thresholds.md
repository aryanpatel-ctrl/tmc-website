# Quality thresholds and their justification

This file is the only place where the pass marks of the automated quality gates are set or
changed. Every change needs a dated entry in the change log at the end, with the reason. The
tender leaves performance thresholds and peak traffic "to be agreed with TMC" (SOW §4.7, §4.10;
see RTM, "Findings to raise with TMC", item 7). Until TMC confirms them, the values below are the
vendor's proposal.

## Accessibility (axe-core), R-4.9-1

| Setting | Value |
|---|---|
| Rule sets | `wcag2a`, `wcag2aa`, `wcag21a`, `wcag21aa`, `wcag22a`, `wcag22aa` |
| Pass mark | **0 violations** on every scanned page (any impact level) |
| Pages | every template type × every site, at 1280 px and 360 px, plus the high-contrast view of every template on the TMC site |

No rule is disabled. axe results marked "incomplete" (needs review) are counted in the report and
checked in the manual audit (docs/testing/quality-gates.md, "Manual accessibility audit").

## HTML validity (Nu HTML Checker), R-4.8-6

| Setting | Value |
|---|---|
| Pass mark | **0 errors** on every page |
| Warnings | reported, not failing |

Known warning: "Article lacks heading" on single pages and posts. The page title (`<h1>`) sits in
the page header above the `<article>` element, which is valid HTML. Moving the header into the
article would change every template; the change is noted for the design implementation
(Annexures A–C) instead.

## Lighthouse, R-4.10-6

Category scores (0–100). A page fails when any category is below its minimum.

| Form factor | Performance | Accessibility | Best practices | SEO |
|---|---|---|---|---|
| Desktop | ≥ 90 | 100 | ≥ 95 | ≥ 90 |
| Mobile (simulated slow 4G, 4× CPU slowdown) | ≥ 75 | 100 | ≥ 95 | ≥ 90 |

Metric limits, reported as warnings (Core Web Vitals "good" limits; mobile relaxed for the
simulated slow network):

| Metric | Desktop | Mobile |
|---|---|---|
| Largest Contentful Paint | ≤ 2.5 s | ≤ 4.0 s |
| Cumulative Layout Shift | ≤ 0.1 | ≤ 0.1 |
| Total Blocking Time | ≤ 200 ms | ≤ 600 ms |
| First Contentful Paint | ≤ 1.8 s | ≤ 3.0 s |

Justifications:

1. **Mobile performance 75, not 90.** Lighthouse's mobile preset simulates a slow 4G connection
   and a CPU four times slower than the test machine. The shared GitHub runners that CI uses vary in
   speed by about ±10 points from run to run. 75 keeps the gate stable without hiding a real
   regression. The reference machine measured 95–99 on mobile for every template, so the margin is
   wide.
2. **Performance is judged on the median of 3 runs** when a single run misses the budget
   (`LIGHTHOUSE_RECHECK_RUNS`). Accessibility, best-practice and SEO scores do not vary between
   runs, so they are never measured again.
3. **The "is-crawlable" audit is skipped outside production.** UAT, CI and development sites are
   deliberately hidden from search engines (`blog_public = 0` in `scripts/install-network.sh`, which
   prints `noindex`). Counting that audit would cost every page about 10 SEO points for a correct
   setting. With `TMC_ENV=production`, the audit runs.
4. **Templates measured.** Every template flagged `lighthouse` in `tests/lib/sites.js` is measured on
   the TMC site and on one unit site (TMH), desktop and mobile. On the other four sites only the
   English and Hindi home pages are measured: all six sites use the same theme and templates, and the
   home pages are where their content differs. `LIGHTHOUSE_SITES` can list more sites.

Budgets: `tests/lighthouse/budgets.json`.

## Links and orphaned pages, R-4.11-4

| Finding | Effect |
|---|---|
| Internal link or resource answering 4xx/5xx or not answering | **fails** |
| Link to a `#fragment` that does not exist on the target page | **fails** |
| URL that cannot be parsed | **fails** |
| Published page not reachable by following links from the home pages | **fails** (orphan) |
| External link not answering (HEAD then GET, 10 s timeout) | warning |
| Link that goes through a redirect | notice |

External links are warnings because other websites can block automated requests or be down
briefly. They are listed in `external.csv` for an editor to review before Go-Live.

## Visual regression, R-5-1

| Setting | Value |
|---|---|
| Pixel tolerance | `threshold 0.2` per pixel (anti-aliasing), at most 0.2 % of pixels different |
| Masked | dates and times (`time`), last-updated lines, footer meta, event dates, today's cell in the calendar |

## Load test, R-4.14-4 and R-4.7-9

The visitor mix and profiles are in `tests/load/tmc-load.k6.js`. Each visitor waits 1–5 seconds
between pages.

| Profile | Visitors | Duration | p95 | p99 | Failed requests |
|---|---|---|---|---|---|
| smoke | 2 | 1 min | < 1.5 s | < 3 s | < 1 % |
| average | 0 → 50 | 13 min | < 1.5 s | < 3 s | < 1 % |
| **peak** (Go-Live evidence) | 0 → 200 | 15 min | < 3 s | < 5 s | < 1 % |
| stress (finds the limit) | 0 → 600 | 17 min | < 3 s | < 5 s | < 5 % |

Also, on every profile: at least 99 % of response checks pass (status, complete page, no PHP error
output), and the p95 of HTML pages alone is under the p95 limit.

Justification: 200 visitors at once, each opening a page every 1–5 seconds, is about 65 page views
a second. That is four times the "average" profile, the margin needed for result days, recruitment
notices and tender closing dates. These figures must be replaced by the traffic figures TMC gives
(current analytics of tmc.gov.in). Only the **peak** profile counts as Go-Live evidence
(`tests/report/acceptance.js`).

## Capacity test connection model (CI gate)

The CI capacity gate (`scripts/perf/capacity-test.sh`, `scripts/perf/capacity.js`) sends load straight to
the WordPress container, so it opens **one connection per request** (`noConnectionReuse`). That is how
production and UAT behave: visitors keep their connections open to the reverse proxy (nginx), and the
proxy opens a short, fresh upstream connection to Apache for each request. Letting the load generator
hold idle keep-alive connections to Apache instead (6 sites × up to 400 virtual users) exhausts Apache's
worker pool with idle sockets — a condition real traffic never produces behind the proxy — and turns a
2 ms cached response into a 15 s queue. Measured on the integrated build (Docker Desktop, 30 s, 100
page views/s + 5 searches/s, default Apache prefork settings): cached pages p95 2 ms, uncached search
p95 71 ms, 100 % cache hits, 0 dropped requests. Keep-alive behaviour towards real clients is tested
through the proxy by the UAT load test (`tests/load/`).

## Change log

| Date | Change | Reason | Approved by |
|---|---|---|---|
| 2026-09-30 | Initial thresholds (this file) | Automated quality gates (SOW §4.9, §4.10, §4.14); values proposed until TMC sets its targets | — (awaiting TMC) |
| 2026-09-30 | Capacity gate uses one connection per request | Models the reverse proxy in front of Apache (see above); thresholds unchanged | — (awaiting TMC) |
