# Site search and document library

Requirements: **R-4.12-5** (full-text search with auto-suggestions and paginated results across web
content and documents) and **R-4.6-6** (central management of media and document libraries).

## What visitors get

| Feature | Where | Notes |
|---|---|---|
| Full-text search | `/?s=…` on every site (`/hi/?s=…` in Hindi) | Pages, news/notices, tenders, events, careers, departments, doctors and documents, in the current language. Documents are shared by all languages. |
| Words inside documents | same | Text of PDFs (and DOCX, XLSX, PPTX, ODT/ODS/ODP, TXT, CSV) is extracted on upload. |
| Relevance | same | Every word must match. Items whose **title** contains the phrase or the words come first, then text matches, then newer items. |
| Filter by type | facet links with counts + "Show" select | Plain links/GET form; works without JavaScript. |
| Result details | each result | Content type, date; for documents also document type, file format and size (GIGW). Matched words are highlighted (`<mark>`, escaped). |
| Pagination | 10 per page | Standard WordPress pagination (`/page/2/?s=…`). |
| Suggestions | header search box and search page | ARIA 1.2 combobox (see *Accessibility*). Progressive enhancement: without JavaScript the box is a normal search form. |
| Documents library | `/documents/` (English) | Filter by document type and year, file format and size, pagination. Linked from the footer quick links. |
| Documents block | block **Documents** (`tmc/documents`) | For any page: a fixed list (optionally one type, with "View all") or the full library mode. |

## What editors and administrators do

- **Document type and date** — in the media modal or the attachment screen, each document has
  *Document type* (Annual report, Circular, Office order, Form, Policy, Tender document, Result,
  Other) and *Document date* (date of issue; used by the year filter; empty = upload date). New
  uploads default to *Other* (or *Tender document* when uploaded to, or added to, a tender), so every
  document appears in the library. The *Search text* line shows how much text was extracted.
- **Media → Library (list view)** has a *Document type* column and filter. Site Administrators can
  rename or add types under **Media → Document types**.
- **Network Admin → Media & documents** (Super Admins) — all six websites on one screen:
  - *Overview*: documents, images, other files and disk space per site with totals; PDF text status;
    links to each site's media library, document types and Documents page.
  - *Recent uploads*: the 25 newest uploads on all sites, with *Edit* and *View file* links.
  - *Find a file*: search all sites by title or file name (all files, documents only or images only).
  - *Extract text now*: re-reads up to 20 PDFs per click with pdftotext (see *Operations*). Recorded
    in the audit log as `documents_text_extracted`.

## How it works

```
upload ─► document-text.php ─► {prefix}tmc_document_text   (text, method)
             pdftotext (poppler-utils, no shell, 60 s limit, 500 pages)
             or built-in PDF reader / zip reader for Office & OpenDocument
save / publish / unpublish / language / delete / attach ─► search-index.php
             ─► {prefix}tmc_search_index   (public items only: clean title + text, language, date)
/?s=…  ─► posts_pre_query answers the main query from the index ─► theme search.php
/wp-json/tmc/v1/suggest ─► titles from the index (cached, rate limited)
```

| File | Role |
|---|---|
| `src/mu-plugins/tmc-core/document-text.php` | Extraction, text table, `tmc_site_installed()` guard |
| `src/mu-plugins/tmc-core/search-index.php` | Index table, hooks, ranking query, snippets, highlighting, main-query integration, WP-CLI, daily reconcile |
| `src/mu-plugins/tmc-core/search-suggest.php` | REST endpoint, rate limiter |
| `src/mu-plugins/tmc-core/documents.php` | `tmc_doc_type` taxonomy, document date, media fields, library filter, public listing query, Documents page provisioning |
| `src/mu-plugins/tmc-core/media-overview.php` | Network Admin screen |
| `src/themes/tmc/inc/search.php`, `search.php`, `template-parts/search-result.php` | Results page, facets, translated type labels |
| `src/themes/tmc/inc/documents.php`, `assets/js/documents-editor.js` | `tmc/documents` block |
| `src/themes/tmc/assets/js/features/search.js`, `assets/css/features/{search,documents}.css` | Combobox, styles incl. high contrast |
| `scripts/migrations/010-search-index.php`, `011-documents-page.php` | Existing sites: tables, types, dates, text, index; Documents page + menu link |

**What is indexed**: published, non-password-protected items of every public post type that is not
excluded from search, plus document attachments that are unattached or attached to such an item.
Drafts, pending, private and protected content — and documents of unpublished pages — are never in
the index, so neither results nor suggestions can reveal them. Rows are updated by hooks and
reconciled daily (`tmc_search_reconcile`, run by the cron container).

**Ranking** is computed in SQL (`LIKE` on the index table): exact title 100, phrase in title 40, each
word in title 20, phrase in text 8, each word in text 2; ties by date. Results, facet counts and
suggestions are cached in the object cache and invalidated whenever the index changes.

## Suggestion API

`GET /wp-json/tmc/v1/suggest?q=<text>&lang=<en|hi>`

- `q` 2–100 characters (else `400`, codes `tmc_query_too_short` / `tmc_query_too_long`).
- `lang` defaults to the site default language; unknown values fall back to it.
- Response: `{"query": "…", "count": n, "items": [{"title": "…", "url": "…", "type": "Page"}]}` —
  up to 8 items; titles containing every word; titles starting with the text first. No IDs, authors
  or text.
- Rate limit: 120 requests per minute per client IP (env `TMC_SUGGEST_RATE_LIMIT` or filter
  `tmc_suggest_rate_limit`); `429` with `Retry-After` when exceeded. Uses Redis when the persistent
  object cache is enabled, otherwise a transient. Behind the reverse proxy the client IP comes from
  `mod_remoteip`.
- Headers: `X-TMC-Cache: hit|miss`; anonymous responses `Cache-Control: public, max-age=300`.

## Accessibility

- Search page: labelled controls, results count in a `role="status"` region, facet links with
  `aria-current`, headings per result, 44 px targets, high-contrast and forced-colours styles.
- Combobox (ARIA 1.2 pattern): `role="combobox"`, `aria-expanded`, `aria-controls`,
  `aria-autocomplete="list"`, `aria-activedescendant`; `role="listbox"`/`role="option"` with
  `aria-selected`; a polite live region announces the number of suggestions.
  Keys: **Down/Up** move (wrapping), **Enter** opens the highlighted suggestion or searches,
  **Escape** closes (again: clears), **Tab** closes. Focus never leaves the text box.
- Documents: table with caption and `scope`, file format and size in visible text and in the link
  name for screen readers; filters are a labelled GET form.

## Operations

- **pdftotext** is installed in the WordPress image (`poppler-utils=25.03.0-5*`). WP-CLI containers
  (`wordpress:cli`) do not have it, so migrations use the built-in reader (method `basic`), which
  handles PDFs with simple text encoding. After deploying, a Super Admin can click **Extract text
  now** until nothing is pending, or run it for one site from a container that has pdftotext.
- PHP must allow `proc_open` for pdftotext (if a hardening profile disables it, only the built-in
  reader is used).
- Commands (per site):
  ```bash
  docker compose run --rm -T wpcli --url=<site> tmc-search rebuild          # rebuild the index
  docker compose run --rm -T wpcli --url=<site> tmc-search extract [--all]  # extract text again
  ```
- Scanned PDFs have no text layer; the *Search text* line says so. They need OCR before upload.
  Legacy `.doc/.xls/.ppt/.rtf` files are searchable by title, caption, description and file name.
- Scale: the `LIKE` index comfortably covers thousands of items per site; with tens of thousands of
  large documents, add a FULLTEXT index to `tmc_search_index` (the query layer is in one function,
  `tmc_search_query()`).

## Tests

- `scripts/tests/search-test.php` (60 checks, TMH site): extraction (plain and compressed PDFs),
  search by a word that exists only inside a PDF, ranking, facets, type filter, language, drafts /
  private / protected / unpublished exclusion, documents of draft pages, structured fields,
  pagination through `WP_Query`, suggestion endpoint (fields, cache, 400s, 429), document types and
  dates, listing filters, block output, network overview, rebuild, clean-up.
- `scripts/smoke.d/search.sh`: results page, PDF result, type filter, no-results help, Hindi search,
  suggestion endpoint (200/400), Documents page and filter.
