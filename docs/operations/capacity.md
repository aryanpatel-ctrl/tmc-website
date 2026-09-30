# Performance and capacity

Requirements: **R-4.7-9** (sustain peak load without degrading agreed thresholds; scale for more
units, languages and modules), **R-4.14-4** (performance and load testing against thresholds —
capacity part; browser-side performance budgets are covered by the Lighthouse quality gate, see
[Quality gates](../testing/quality-gates.md)).

## How the platform carries load

| Layer | What it does | Where |
|---|---|---|
| Full-page cache | anonymous page views (almost all public traffic) are served from Redis without running WordPress; status in the `X-TMC-Cache` header (`HIT`, `MISS`, `BYPASS`) | `src/mu-plugins/tmc-page-cache/`, `src/mu-plugins/tmc-core/page-cache.php` |
| Object cache | WordPress queries and options for logged-in editors, search, forms and cache misses come from Redis | Redis Object Cache plugin (GPL-3.0, pinned in `scripts/setup-cache.sh`), `object-cache-config.php` |
| Compression | Brotli (gzip fallback) for HTML, CSS, JS, JSON, SVG | `wordpress/apache-tmc.conf` |
| Browser caching | versioned theme/core assets 1 year (`immutable`), other static files 1 week, documents 1 day | `wordpress/apache-tmc.conf` |

### Page cache rules

Cached: `GET`/`HEAD` page views of the front controller by visitors without login, preview, password
or comment cookies, with no query string or only allow-listed parameters (`view`, `month`,
`department`, and `name` only when empty — see `tmc-page-cache/config.php`). Tracking parameters
(`utm_*`, `gclid`, `fbclid`, …) are served from the cache but never create entries.

Never cached: logged-in users, previews and the customizer, `POST`, search, 404 and other non-200
responses, password-protected content, feeds, REST API / `wp-json`, admin, login, cron, any response
that sets a cookie or sends `Cache-Control: private/no-store`, and **any page that created a nonce for
an anonymous visitor** (a form). Features that must stay dynamic call `tmc_page_cache_bypass( 'reason' )`
or send `nocache_headers()`.

Purged automatically, for the whole site (all languages) plus the TMC umbrella site: publish, update,
unpublish, trash, delete, meta and term changes of public content (including the automatic-expiry
flags), menus, widgets, customizer/theme settings, site options that change output, comments. Copies
published to other sites purge those sites (they are saved there). Plugin/theme changes, new sites
and deploys purge the whole network. Purging writes a new generation number (constant time, no key
scans); old entries expire by TTL (10 minutes, the upper bound for date-driven changes) or LRU.
Manual purge: Network Admin → Health & Backups, or `wp tmc-cache purge [--network]`.

Proven end to end by `scripts/tests/perf-test.php` (MISS → HIT, 304 revalidation, every bypass rule,
purge on each kind of change, across languages, umbrella and network copies) and on every smoke test
by `scripts/smoke.d/cache.sh` (HIT on repeat view, bypass rules, compression, cache headers).

## Capacity test

`scripts/perf/capacity-test.sh` runs [k6](https://k6.io) (pinned `grafana/k6:1.8.1`, AGPL-3.0, a test
tool only) in a container on the stack's own network, straight at the WordPress container. It uses an
**open model** (constant arrival rate): requests keep arriving at the target rate however slow the
server gets, so saturation shows as latency, errors and dropped iterations instead of hiding.

| Scenario | Default rate | Pages | Threshold (default) |
|---|---|---|---|
| Anonymous page views | 100 / s | 12 home pages (6 sites × English, Hindi) + 5 main listings, warmed once | p95 < 800 ms |
| Site search (uncached: PHP + database) | 5 / s | `/?s=<term>` on the umbrella site | p95 < 3000 ms |
| All requests | — | — | failed < 1 %; page-cache hit rate > 90 %; dropped iterations < 1 % |

```bash
scripts/perf/capacity-test.sh                                   # defaults, 2 minutes
scripts/perf/capacity-test.sh --rate 300 --search-rate 10 --duration 10m --out reports/capacity-300
```

CI: `.github/workflows/capacity.yml` (reusable; run by hand with other rates) provisions all six sites
and runs the test; the report is in the job summary and kept 90 days. The report states the host (CPU,
memory) and that the load generator shares it — results on a CI runner are a **lower bound** for a
dedicated server.

### Agreeing thresholds and sizing production

The tender leaves "agreed thresholds" to be set with TMC. Proposed, for the production host:

| Measure | Proposed threshold |
|---|---|
| Anonymous page view, server time, p95 at peak | < 500 ms |
| Uncached dynamic request (search, forms), p95 at peak | < 2 s |
| Errors at peak | < 0.5 % |
| Peak rate to sustain | 5 × the busiest hour seen in TMC's current analytics, per second, and not less than 100 page views/s |

Procedure: take the busiest hour from TMC's current web analytics, compute the peak rate, run the
capacity test on the production host **before go-live** (maintenance window, from a second machine
or with the defaults on the host itself) at that rate and at 2× it, and file both reports. If p95 or
errors exceed the thresholds: raise PHP/Apache workers and CPU (dynamic path), or Redis memory
(`--maxmemory` in `docker-compose.yml`, if the hit rate falls because entries are evicted).

### Scaling for more units, languages and modules

- **Units:** a new site is one more host name in the same cache and database; purges are per host, so
  adding sites does not slow others. Page-cache memory grows with pages × languages; Redis is capped
  at 256 MB with LRU eviction — watch the hit rate in the capacity report and raise the cap first.
- **Languages:** each language is separate URLs (`/hi/…`), cached separately and purged together
  with their site.
- **Modules:** a module whose pages are the same for every anonymous visitor is cached with no work;
  one that needs per-visitor output must call `tmc_page_cache_bypass()` (or use a nonce, which does it
  automatically) and is then measured by the dynamic scenario.
- **Beyond one host:** WordPress keeps no state on the web container except uploads; several web
  containers behind TMC's load balancer can share the database, Redis and an uploads volume on shared
  storage. The page cache already lives in Redis, so it is shared by all of them.
