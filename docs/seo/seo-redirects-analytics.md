# SEO, structured data, redirects and analytics

Requirements: R-4.10-2 (editor-managed metadata), R-4.10-3 (structured data), R-4.10-4 (sitemap and
crawl directives), R-4.10-5 and R-4.11-4 (redirects), R-4.10-7 (analytics and search console).

| Module | File | Test |
|---|---|---|
| Metadata, social tags, robots, sitemaps | `src/mu-plugins/tmc-core/seo.php` (+ `admin/seo.js`) | `scripts/tests/seo-test.php`, `scripts/smoke.d/seo.sh` |
| Structured data (JSON-LD) | `src/mu-plugins/tmc-core/seo-schema.php` | `scripts/tests/seo-test.php` |
| Redirect manager | `src/mu-plugins/tmc-core/redirects.php`, `src/themes/tmc/410.php`, migration `030-redirects-table` | `scripts/tests/redirects-test.php` |
| Analytics and search console | `src/mu-plugins/tmc-core/analytics.php` | `scripts/tests/analytics-test.php` |

## For editors: "Search and social sharing"

Every page, news item, tender, event, job opening, department and doctor profile has a
**Search and social sharing** box below the content. All fields are optional; empty fields use the
default shown.

| Field | Default when empty | Guidance |
|---|---|---|
| SEO title | The page title; the site name is added automatically | Under 60 characters |
| Meta description | The excerpt, otherwise the start of the text (home page: the site tagline) | 70–160 characters; the counter says whether the length is good |
| Social sharing image | Featured image, then the site's default social image | At least 1200 × 630 pixels |
| Canonical URL (advanced) | The page's own address | Only when the same text is published elsewhere as the original |
| Hide from search engines | Off | The page stays on the website but gets `noindex` and leaves the XML sitemap |

Changes to "hide from search engines" and the canonical URL are recorded in the audit log, because
they decide whether and where a page can be found.

Each page gets exactly one `<title>`, one meta description, one canonical link and one set of
Open Graph (`og:*`) and Twitter card tags. The X (Twitter) handle comes from the site's X link in
Appearance → Customize → TMC contact details.

## Structured data

One JSON-LD block per page (validate with the Schema.org validator or Google's Rich Results Test):

- **Organisation** on every page — the TMC site is a `GovernmentOrganization` + `MedicalOrganization`
  under the Department of Atomic Energy; each unit site is a `Hospital` whose parent organisation is
  TMC. Name, address (from TMC contact details), logo (site icon, otherwise the theme logo), phone,
  e-mail and social profiles.
- **WebSite** with a **SearchAction** (the site search in the page's language).
- **WebPage** / **CollectionPage** and a **BreadcrumbList** built from the same trail as the visible
  breadcrumbs, so the two always match.
- **Event** for events, **JobPosting** for job openings (`validThrough` = last date to apply) and
  **Physician** for doctor profiles with a designation.

Items marked as sample content (`_tmc_sample`) get no Event/JobPosting/Physician data and are
`noindex`: structured data must describe real things.

## Crawl directives and sitemaps

Search-engine visibility follows the environment, not a checkbox that can be forgotten:

| | Production (`WP_ENVIRONMENT_TYPE=production` **and** Settings → Reading "Search engine visibility" allowed) | Every other environment (local, CI, UAT) |
|---|---|---|
| `robots.txt` | Allows crawling except admin and search results; `Sitemap:` line | `Disallow: /` |
| Pages | Normal; `noindex` only for hidden pages, samples, search results and 404 | `noindex, nofollow` meta tag |
| HTTP header | — | `X-Robots-Tag: noindex, nofollow` |

XML sitemaps (`/wp-sitemap.xml`, per language) list pages, news, tenders, events, job openings,
departments, doctors and categories. Sample items and hidden pages are left out; the user sitemap is
switched off (it would disclose login names). Each entry carries its last-modified date.

**Go-live switch:** production needs `WP_ENVIRONMENT_TYPE` set to `production` (today
`docker-compose.yml` sets `staging` for every environment) and `blog_public = 1` (today
`scripts/install-network.sh` sets `0` on every site). Both are environment settings owned by the
production environment work (W7); nothing in this module needs to change.

## Redirect manager (Tools → Redirects)

Site Administrators manage the redirects of their own site:

- **Add / edit**: old address (a path such as `/old/page.html`, `/show.php?id=12`, or a full URL —
  only the path and query are used), new address (a path on this site or `https://…` for another
  site), type **301** (moved permanently), **302** (temporarily) or **410** (removed on purpose;
  visitors see a "This page has been removed" page), and a note.
- **Matching** ignores letter case and a trailing slash; the query string must match exactly.
- **Rules only act on addresses that do not exist** (404), so a rule can never hide a live page.
  Old addresses with a query string are also matched exactly on pages WordPress would otherwise
  answer with the home page.
- **Loops** (A → B → A, or a pattern that matches its own target) are refused. **Chains**
  (A → B → C) are shown as a warning and in the list; visitors are sent straight to the end of the
  chain in one redirect.
- **Hits and last hit** show which old addresses are still used (and which can be retired).
- **CSV import / export** — header `source,target,status` and optionally `regex,note`. Every line is
  validated like a manual entry; errors are listed by line number. "Replace" updates rules that
  already exist. Exported cells that look like spreadsheet formulas are neutralised.
- **Regular-expression rules** are off by default; only a Super Admin can allow them for a site.
  Patterns are matched case-insensitively against the lower-case path; `$1`… in the target insert
  captured groups.
- Every change, import, export and the regex switch is recorded in the audit log.

Lookups are one indexed database query per 404 (cached in the object cache once W7 enables it),
plus a hit-counter update when a rule applies. The content importer creates its 301s here.

## Analytics and search console (Network Admin → Settings → Analytics & Search)

Super Admins choose one provider for the network and fill in per-site values:

| Provider | What is added to pages | Data location |
|---|---|---|
| None (default) | Nothing | — |
| **Matomo** (recommended) | Matomo tracker from TMC's own Matomo server, cookieless (`disableCookies`), honours Do Not Track, image fallback without JavaScript. No consent banner is needed. | TMC-controlled server (India) |
| Google Analytics 4 | Google's `gtag.js` in consent mode "denied" (no analytics or advertising cookies, Google signals and ad personalisation off) | Google; may be outside India. Loads a third-party script: confirm with TMC before enabling. |

Per site: Matomo site ID, GA4 measurement ID, Google Search Console and Bing Webmaster verification
tokens (a pasted meta tag is accepted; the token is extracted), and the default social sharing
image. Verification tags appear on the home page only. Nothing is output until the values a
provider needs are filled in; logged-in editors are never tracked.

The Matomo address can instead come from the `TMC_MATOMO_URL` environment variable, so UAT and
production can use different analytics servers without a database change. In Matomo itself,
enable IP anonymisation and set data retention as agreed with TMC.

When analytics is active, the privacy policy page automatically gets a short **Website analytics**
section describing the provider, and the same text is offered in Settings → Privacy. The WordPress
dashboard of each site shows links to its Matomo dashboard (or Google Analytics), Search Console and
Bing Webmaster Tools, and the sitemap address to submit.

If a Content Security Policy is introduced (W2), allow the Matomo host in `script-src`, `img-src`
and `connect-src`; the inline tracker is printed with `wp_get_inline_script_tag()`, so a nonce can
be added through the `wp_inline_script_attributes` filter.
