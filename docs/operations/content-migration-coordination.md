# Content Migration Coordination: Inventory and Sign-off

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-OPS-09 | 0.1 | Draft for TMC IT approval; importer column names verified at integration (W3) | R-4.11-5, R-4.11-1 to R-4.11-4, R-2-4, R-7.1-1 |

SOW §4.11: "TMC shall provide the approved content for migration. The Vendor shall not be responsible for
auditing, validating, or restructuring the content provided." The Vendor shall "coordinate with TMC and
unit-level content coordinators for receipt of content and post-migration confirmation". This document
defines that coordination: who provides what, in which form, how it is tracked, and how each unit
confirms the result. The technical method (importer, template mapping, redirects, link scan) is described
in the [Content Migration Methodology](../proposal/04-content-migration-methodology.md).

## 1. Roles

| Role | Organisation | Responsibility |
|---|---|---|
| Content owner (per unit) | TMC unit | Approves the content of that unit's website in English and Hindi |
| Unit content coordinator (one per unit) | TMC unit | Single point of contact; delivers the content package; answers queries; signs the post-migration confirmation |
| TMC IT nodal officer | TMC | Approves the inventory format and the migration schedule; final acceptance |
| Migration lead | Vendor (Backend/CMS Developer) | Receives packages, runs the import, produces the verification reports |
| QA engineer | Vendor | Runs the post-migration checks, broken-link and orphan scans |

## 2. Content package (what each unit provides)

| # | Item | Format | Notes |
|---|---|---|---|
| P1 | Content inventory (§3) | Spreadsheet (CSV/XLSX) from the template [content-inventory-template.csv](templates/content-inventory-template.csv) | One row per page, document or item |
| P2 | Page text | Word/ODT/HTML files or the existing web page URL, named by inventory ID | English and Hindi supplied by TMC (SOW §4.13) |
| P3 | Documents | PDF (text-searchable, not scanned images, for accessibility and search), named by inventory ID | Accessible PDF recommended (GIGW) |
| P4 | Images | JPEG/PNG/WebP, with alternative text in the inventory | Rights to publish confirmed by the unit |
| P5 | Structured items | Tenders, job openings, events, doctors, departments in the inventory columns for their content type | Maps to the content types in [Data Model §3](../architecture/data-model.md#3-content-types-and-fields) |
| P6 | Old-URL list | Every URL of the existing website that must keep working | Basis for redirects (SOW §4.11) |

## 3. Content inventory columns

| Column | Meaning | Example |
|---|---|---|
| `inventory_id` | Unique ID per unit | `TMH-0123` |
| `site` | Target site slug | `tmh` |
| `language` | `en` or `hi` | `en` |
| `translation_of` | `inventory_id` of the other-language version | `TMH-0122` |
| `content_type` | `page`, `post` (news/notice), `tmc_tender`, `tmc_job`, `tmc_event`, `tmc_department`, `tmc_doctor`, `document` | `page` |
| `template` | Page template from Annexure B (W5 names, verify at integration) | `standard` |
| `parent_id` | `inventory_id` of the parent page in the IA | `TMH-0100` |
| `menu_order` | Position among siblings | `3` |
| `title` | Title | `Patient Guide` |
| `slug` | Desired URL segment (lower case, hyphens) | `patient-guide` |
| `body_file` | File containing the body text | `TMH-0123.docx` |
| `summary` | Excerpt / meta description | |
| `documents` | Document file names, separated by `;` | `TMH-0123-guide.pdf` |
| `image`, `image_alt` | Featured image and its alternative text | |
| `category` | For posts: `news` or `notices` | |
| `expires_at` | For time-bound posts (`YYYY-MM-DD HH:MM`, IST) | |
| `ref_no`, `closing_at`, `opening_at`, `portal_url` | Tender fields | |
| `start_at`, `end_at`, `venue`, `registration_url` | Event fields | |
| `designation`, `departments`, `qualifications`, `specialisation`, `opd_days` | Doctor fields | |
| `old_url` | URL on the existing website (for the redirect) | `https://…/patient-guide.html` |
| `owner`, `approved_on` | Content owner and approval date | |
| `status` | `received`, `queried`, `imported`, `verified`, `confirmed` | |

## 4. Schedule and tracking

| Step | Who | Timing (relative to the site's Go-Live date G) |
|---|---|---|
| Inventory template and IA (Annexure B) shared with the unit | Vendor | G − 60 days |
| Content package delivered | Unit coordinator | G − 45 days |
| Completeness check; queries raised (missing files, missing translations, broken references) | Vendor | Within 3 business days of receipt |
| Import to UAT; verification report | Vendor | G − 30 days |
| Unit review on UAT; corrections by the unit | Unit | G − 20 days |
| Final import to Production (content freeze on the old site from here) | Vendor | G − 7 days |
| Broken-link and orphan scan clean; post-migration confirmation signed | Vendor + unit | Before G |

A migration tracker (one row per inventory item with its status) is kept in the project tracker and
reported in the monthly progress report.

## 5. Query log

| # | Inventory ID | Query | Raised on | Answered by / on | Resolution |
|---|---|---|---|---|---|
| 1 | | | | | |

The Vendor does not change the meaning of content; where an item is incomplete the Vendor raises a query
rather than inventing text (SOW §4.11).

## 6. Post-migration confirmation (one per website)

**Website:** [ ] **Environment:** Production / UAT **Import run:** [date, release]

| # | Check | Evidence | Result |
|---|---|---|---|
| 1 | Every inventory item with status `approved` is present at its planned place in the IA, in both languages where supplied | Post-migration verification report (W6) | |
| 2 | Content renders correctly in its template (sample reviewed by the unit: at least 10 % of pages and every template type) | Unit reviewer's list | |
| 3 | Documents open and are the correct versions | Report | |
| 4 | Every old URL redirects (301) to the correct new page or returns 410 where the content was withdrawn | Redirect report (W3) | |
| 5 | Zero broken links and no orphaned pages (automated scan) | Link report (W6) | |
| 6 | Sample content (`_tmc_sample`) removed | [Installation Guide §7](installation-deployment.md#7-pre-go-live-content-clean-up) | |

We confirm that the content migration of the above website is complete and correct.

| | Unit content coordinator | Content owner (unit) | TMC IT | Vendor migration lead |
|---|---|---|---|---|
| Name | | | | |
| Date | | | | |
| Signature | | | | |
