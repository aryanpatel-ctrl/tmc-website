# Content migration toolkit

Requirements: R-4.11-1 (migrate TMC-approved content), R-4.11-2 (map it to templates and content
types), R-4.11-4 (redirects for superseded URLs) and R-2-4 (accurate, complete migration).
The post-migration crawl for broken links and orphan pages (R-4.11-3/4) is part of the quality gates
([Quality gates](../testing/quality-gates.md#links-and-orphaned-pages)); the sign-off form (R-4.11-5) is in
[Content Migration Coordination](../operations/content-migration-coordination.md).

TMC supplies the content. We turn it into a **content inventory** (a CSV file, one row per page or
item per language) and load it with a WP-CLI script that is safe to run again and again.

| File | Purpose |
|---|---|
| `scripts/import/import-inventory.php` | Command-line entry point (WP-CLI `eval-file`) |
| `scripts/import/class-tmc-inventory-importer.php` | The importer |
| `docs/migration/content-inventory-template.csv` | Empty inventory with every column |
| `docs/migration/sample-inventory.csv`, `docs/migration/sample/` | A small SAMPLE inventory for demonstrations and training. Every row is marked `SAMPLE`; it is not TMC content. |
| `scripts/tests/import-test.php` | Automated test (dry run and real run on its own inventory) |

## What the importer does

For each row of the inventory that belongs to the site it runs on:

1. **Creates or updates** the page or item. A row is recognised again on later runs by its old URL,
   or (without one) by content type + language + path, so re-running never makes duplicates. A
   seeded placeholder page at the same address (the "content will be provided by TMC" pages) is
   filled in; any other existing page is left alone unless `force=1` is given.
2. **Maps** the row to its content type (`page`, `post`, `tmc_tender`, `tmc_event`, `tmc_job`,
   `tmc_department`, `tmc_doctor`, and any other public type), page template, parent page,
   structured fields (`field:…` columns), SEO fields and news/notice categories.
3. **Attaches documents** to the media library, once per file (files are recognised by checksum).
   Content types with a documents field (tenders, careers) get them in that field; pages and posts
   get a "Documents" list with file type and size.
4. **Links translations**: rows with the same `translation_key` (for example the English and Hindi
   versions of a page) are linked in Polylang, so the language switcher goes between them.
5. **Creates 301 redirects** from every `old_url` (and every old document URL) to the new address,
   in the site's redirect manager (Tools → Redirects), so bookmarks and search results keep working.
6. **Rewrites links** inside migrated content that point to old URLs of migrated items, so the new
   pages link to each other directly instead of through redirects.
7. **Protects editors' work**: an item changed in the CMS after it was imported is not overwritten
   by a later run (reported as `skipped (edited in CMS)`), unless `force=1` is given.
8. **Reports** one line per inventory row: action, new URL, redirect, translation and messages.

Content HTML is cleaned (`wp_kses_post`): scripts, frames, forms and styles from the old website
are removed and the report says so. Only the `<body>` of a full HTML file is used. Text that is not
UTF-8 is converted from Windows-1252 with a warning. Migrated HTML opens in the editor as a
"Classic" block that editors can convert to blocks.

Every run is recorded in the audit log (`content_import_dry_run` / `content_imported`, with the
checksum of the inventory file and the result counts); each created or changed item is recorded
by the normal content events.

## Inventory columns

UTF-8 CSV with a header row. Column names are case-insensitive; unknown columns are reported.
Only `site`, `language`, `type` and `title` are required.

| Column | Example | Notes |
|---|---|---|
| `site` | `tmh` | Which website: `tmc` (the umbrella site), `tmh`, `hbchrcv`, `mpmmcc`, `hbchrcmzp`, `hbchpunjab` (or the full host name). Rows for other sites are skipped, so one inventory can hold every site. |
| `language` | `en` / `hi` | A language configured in Polylang. |
| `old_url` | `/about/overview.aspx`, `https://tmc.gov.in/page.php?id=5` | Address on the old website. Only path and query string are used. Letter case and a trailing slash do not matter; the query string must match exactly. |
| `type` | `page` | Content type (see above). |
| `template` | `default` | Page template file or name (see the page editor's *Template* list). Unknown names fall back to the default template with a message. |
| `parent_path` | `about-us` or `patient-care/opd-schedule` | Pages only: path of the parent page in the same language. The parent must exist or be in the same inventory (rows are processed parents first, whatever their order). |
| `slug` | `overview` | Last part of the new URL. Use transliterated Latin letters for Hindi pages (R-4.10-1). Default: from the title. |
| `title` | `Overview` | |
| `date` | `2024-03-01`, `2024-03-01 10:30`, `01/03/2024` | Publication date (DD/MM/YYYY is day first). |
| `status` | `publish` | `publish` (default), `draft`, `pending` (goes to the review queue) or `private`. |
| `excerpt` | | Summary; also the default meta description. |
| `content_html_file` | `content/overview.html` | HTML file, relative to the import folder. |
| `content_html` | `<p>…</p>` | Inline HTML, used when there is no file. |
| `documents` | `files/a.pdf\|Annual report 2024\|/docs/ar2024.pdf; files/b.pdf` | `;`-separated. Each entry: file path, optional title, optional old URL (redirected to the new file). Files must be types the media library accepts. |
| `categories` | `news` | Posts only: category slugs in the row's language, `;`-separated. |
| `translation_key` | `about-overview` | Same value on the English and Hindi rows of one page. |
| `order` | `3` | Menu order (position among sibling pages / in listings). |
| `seo_title`, `seo_description`, `seo_noindex` | | SEO fields (see `docs/seo/seo-redirects-analytics.md`). `seo_noindex`: `1` to hide from search engines. |
| `field:<key>` | `field:tmc_closing_at` = `31/12/2026 17:00` | Structured fields of the content types: `tmc_ref_no`, `tmc_kind` (`tender`, `eoi`, `rfp`, `corrigendum`), `tmc_closing_at`, `tmc_opening_at`, `tmc_portal_url`, `tmc_start_at`, `tmc_end_at`, `tmc_venue`, `tmc_registration_url`, `tmc_vacancies`, `tmc_apply_url`, `tmc_hod`, `tmc_location`, `tmc_opd_days`, `tmc_phone`, `tmc_email`, `tmc_designation`, `tmc_department_ids` (department slugs, `;`-separated), `tmc_qualifications`, `tmc_specialisation`, `tmc_expires_at`. Dates as in `date`. Missing required fields are reported. |
| `sample` | `1` | Demonstration rows only: marks the item as sample content (left out of sitemaps and structured data; removable in one go). Leave the column out of real inventories. |

An empty cell in a column that is present clears that value on re-import; a column that is not in
the file leaves existing values alone.

## Procedure

Put the inventory, content files and documents in one folder outside the repository (content is
not committed), e.g. `~/tmc-migration/tmh/`:

```
inventory.csv
content/overview.html …
files/annual-report-2024.pdf …
```

1. **Back up** the database (`scripts/deploy.sh` does this on every deploy; on a workstation use
   `docker compose exec db mariadb-dump …`).
2. **Dry run** (the default — nothing is changed) and read the report:

   ```bash
   docker compose run --rm -v "$HOME/tmc-migration/tmh:/import" wpcli --url=tmh.${TMC_BASE_DOMAIN} \
     eval-file /tmc-scripts/import/import-inventory.php csv=/import/inventory.csv report=/import/report-dry-run.csv
   ```

   Fix every `error` row (unknown type, missing parent page, file not found or not allowed, bad
   date) and review the messages (missing required fields, slugs, template fallbacks).
3. **Import**:

   ```bash
   docker compose run --rm -v "$HOME/tmc-migration/tmh:/import" wpcli --url=tmh.${TMC_BASE_DOMAIN} \
     eval-file /tmc-scripts/import/import-inventory.php csv=/import/inventory.csv mode=apply report=/import/report.csv
   ```

   The script exits with status 2 if any row has an error, so it can be used in automation.
4. **Verify**: open a sample of pages in both languages, the menus and the sitemap; run the link
   and orphan-page crawl (`tests/links/crawl.js`, `npm run links` in `tests/`) and the smoke test. Old URLs can be checked in Tools → Redirects
   (hit counter) or with `curl -I https://<site>/<old-url>`.
5. **Sign-off**: attach `report.csv` to the migration confirmation (R-4.11-5).

Re-running the same inventory is safe: unchanged rows are reported as `unchanged`; corrected rows
update their item (unless an editor changed it since — then use `force=1` after checking).

Other options: `base=/import/other-folder` (where content and document paths start; default: the
CSV's folder), `author=<login>` (who new content is attributed to; default `WP_ADMIN_USER`).

### Undoing an import

Every imported item carries the meta `_tmc_import_source` (`<file>:<row>`); redirects created by the
importer have the note "Content migration: <file>". To remove an import on a test system:

```bash
make wp ARGS="post list --post_type=any --meta_key=_tmc_import_source --format=ids"
```

On production, restore the database backup taken in step 1 instead.

## Limits and notes

- Relative links such as `../page.html` cannot be resolved without the old site's folder structure;
  write them as root-relative (`/section/page.html`) or absolute URLs in the content files, or fix
  them after the crawl report.
- The importer does not follow redirects on the old website; list the final old URLs.
- Old URLs that are still live pages on the new site never redirect (the redirect manager only acts
  on addresses that would otherwise be "not found"); the report shows a warning for them.
- Images inside content keep their old addresses unless the image file is listed in the
  `documents` column with its old URL (then the `src` is rewritten to the media library copy).
  Otherwise upload images to the media library and fix them after the crawl report.
