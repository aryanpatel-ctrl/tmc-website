# Data Model and Database Schema Reference

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-ARC-04 | 0.2 | Draft for TMC IT approval | R-8.2-5, R-4.6-4, R-4.6-5, R-4.8-4 |

This reference describes the database schema, the project-specific data stored in it, the seed data and
the migration mechanism, as required for handover (SOW §8.2: "Database schema, seed data, and migration
scripts").

## 1. Database

| Item | Value |
|---|---|
| Engine | MariaDB 11.4 (`mariadb:11.4.13`) |
| Character set / collation | `utf8mb4` / `utf8mb4_unicode_ci` (Hindi and all Unicode text) |
| Schema name | `DB_NAME` from `.env` (default `tmc_wp`) |
| Table prefix | `tmc_` (`WORDPRESS_TABLE_PREFIX`) |
| Network layout | WordPress Multisite: network tables once; one set of content tables per site |

## 2. Tables

### 2.1 Network-wide tables

| Table | Contents |
|---|---|
| `tmc_blogs`, `tmc_blogmeta` | The six sites (ID, domain, path, status) |
| `tmc_site`, `tmc_sitemeta` | The network and network options (e.g. `tmc_audit_db_version`, super admins) |
| `tmc_users`, `tmc_usermeta` | All user accounts (one account can hold roles on several sites; roles are stored per site in `tmc_usermeta` as `tmc_<n>_capabilities`) |
| `tmc_signups`, `tmc_registration_log` | Core multisite registration tables (self-registration is not used) |
| `tmc_tmc_audit_log` | **Project table**: tamper-evident audit log for all sites (section 4) |

### 2.2 Per-site tables

The main site (TMC, blog ID 1) uses `tmc_<table>`; each unit site uses `tmc_<blog_id>_<table>`:

| Table | Contents |
|---|---|
| `posts`, `postmeta` | Pages, posts, the five TMC content types, attachments (media/documents), menu items, revisions; structured fields as protected meta |
| `terms`, `term_taxonomy`, `term_relationships`, `termmeta` | Categories (News, Notices and Hindi equivalents), menus, Polylang language and translation groups |
| `options` | Site settings, theme mods (contact details, social links), Polylang settings, applied migrations |
| `comments`, `commentmeta` | Core tables; public commenting is not part of the provisioned information architecture |
| `links` | Unused core table |

Site blog IDs are assigned at creation. On a fresh install they are: 1 TMC, 2 TMH, 3 HBCH&RC
Visakhapatnam, 4 MPMMCC Varanasi, 5 HBCH&RC Muzaffarpur, 6 HBCH New Chandigarh (creation order in
`scripts/install-network.sh`). Always confirm with `wp site list`.

## 3. Content types and fields

Defined once in `tmc_field_schema()` (`src/mu-plugins/tmc-core/content-types.php`). Every field is
registered with `register_post_meta` (REST-enabled, sanitised, `edit_post` capability required) and
stored as protected meta with a leading underscore.

| Post type | Archive URL | Field (meta key without `_`) | Type | Required |
|---|---|---|---|---|
| `tmc_tender` (Tender / EOI) | `/tenders/` | `tmc_ref_no` Reference number | text | Yes |
| | | `tmc_kind` Type: tender, eoi, rfp, corrigendum | select | |
| | | `tmc_closing_at` Last date and time of submission | datetime | Yes |
| | | `tmc_opening_at` Bid opening date and time | datetime | |
| | | `tmc_portal_url` e-Procurement portal link (CPP / GeM) | url | |
| | | `tmc_documents` Documents | documents (array of attachment IDs) | |
| `tmc_event` (Event) | `/events/` | `tmc_start_at` Starts | datetime | Yes |
| | | `tmc_end_at` Ends | datetime | |
| | | `tmc_venue` Venue | text | |
| | | `tmc_registration_url` Registration link | url | |
| `tmc_job` (Job opening) | `/careers/` | `tmc_ref_no` Advertisement number | text | Yes |
| | | `tmc_closing_at` Last date to apply | datetime | Yes |
| | | `tmc_vacancies` Number of posts | number | |
| | | `tmc_apply_url` Online application link | url | |
| | | `tmc_documents` Documents | documents | |
| `tmc_department` (Department) | `/departments/` | `tmc_hod`, `tmc_location`, `tmc_opd_days`, `tmc_phone` | text | |
| | | `tmc_email` | email | |
| `tmc_doctor` (Doctor) | `/doctors/` | `tmc_designation` | text | Yes |
| | | `tmc_department_ids` Departments | departments (one meta row per department ID) | |
| | | `tmc_qualifications`, `tmc_specialisation`, `tmc_opd_days` | text | |
| `post` (News / Notice) | category archives | `tmc_expires_at` Expires on | datetime | |

Date-times are stored in site time (Asia/Kolkata) as `Y-m-d H:i:s`.

### 3.1 Other project meta and options

| Key | Where | Meaning |
|---|---|---|
| `_tmc_sample` | postmeta | `1` on seeded sample items; remove all before Go-Live |
| `_tmc_closed` | postmeta | Time the expiry job recorded a tender/job as closed (cleared if the date is moved into the future) |
| `_tmc_expired` | postmeta | Time the expiry job recorded a post as expired |
| `_tmc_review_note` | postmeta | Reviewer's note to the author |
| `_tmc_returned` | postmeta | `{by, at}` when content was returned for changes |
| `tmc_migrations` | site option | Names of applied migrations |
| `tmc_roles_version` | site option | Version of the role configuration applied |
| `tmc_name_hi`, `tmc_city`, `tmc_city_hi` | site option | Per-site Hindi name and city |
| `tmc_address`, `tmc_phone`, `tmc_email`, `tmc_facebook`, `tmc_x`, `tmc_youtube`, `tmc_instagram`, `tmc_linkedin` | theme mods | Per-site contact details and social links (Customizer) |
| `tmc_audit_db_version` | network option | Schema version of the audit table |

Keys and tables added by the feature modules:

| Key / table | Kind | Purpose |
|---|---|---|
| `<prefix>tmc_search_index`, `<prefix>tmc_document_text` | per-site tables | Search index and text extracted from uploaded documents (`search-index.php`, `document-text.php`) |
| `tmc_search_db_version`, `tmc_doc_text_db_version`, `tmc_doc_types_version` | site options | Schema / seed versions of the above |
| `tmc_doc_type` | taxonomy on attachments | Document type (annual report, circular, form, tender document …) |
| `_tmc_doc_date`, `_tmc_doc_text_method`, `_tmc_doc_text_chars` | attachment meta | Document date; how and how much text was extracted |
| `<prefix>tmc_redirects`, `tmc_redirects_db_version` | per-site table, site option | Redirect manager rules (301/302/410) |
| `_tmc_seo_*` (e.g. `_tmc_seo_noindex`) | post meta | Editor SEO fields (title, description, image, robots) |
| `tmc_analytics` | network option | Analytics and search-console settings (off until configured) |
| `tmc_apps_registry` | network option | Application gateway endpoint registry (no secrets: keys come from the environment) |
| `tmc_w4_placed` | site option | Pages that already received their application / map block (placed once) |
| `tmc_map_lat`, `tmc_map_lon`, `tmc_map_zoom`, `tmc_map_approximate` | theme mods | Location map position per site |
| `_tmc_syndicate_targets`, `_tmc_syndicated_copies`, `_tmc_origin_blog`, `_tmc_origin_post`, `_tmc_origin_url`, `_tmc_sync_hash` | post meta | Network publishing: selected unit sites, copies, origin and sync hash |
| `tmc_mfa_required_since`, `tmc_password_change_required` | user meta | MFA grace-period start; weak-password flag. TOTP secrets and hashed backup codes are stored by the Two Factor plugin in user meta |
| `<base_prefix>tmc_backup_log`, `tmc_cron_heartbeat` | network table, network option | Backup runs reported by the backup service; last cron run (health endpoint) |

## 4. Audit log table `tmc_tmc_audit_log`

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint unsigned, PK, auto-increment | Sequence |
| `created_at` | datetime | UTC time of the event |
| `blog_id` | bigint unsigned | Site where it happened |
| `user_id`, `user_login` | bigint, varchar(60) | Actor (`system` for automatic jobs, `wp-cli` for command line) |
| `ip` | varchar(45) | Client IP (`cli` for command line) |
| `action` | varchar(64) | Event name (see Security Architecture §6.1) |
| `object_type`, `object_id`, `object_title` | varchar(32), bigint, varchar(255) | What was affected |
| `details` | text | JSON details (e.g. changed fields, from/to status, language) |
| `prev_hash`, `hash` | char(64) | HMAC-SHA256 chain |

Indexes: `blog_created (blog_id, created_at)`, `action`, `user_login`.

## 5. Revisions (version history)

WordPress stores a revision of every save of pages, posts and all TMC content types (`revisions` is in
each type's `supports`). Editors compare and restore revisions from the editor. Revisions record the
author and timestamp; together with the audit log this meets SOW §4.6 ("version history and audit trail
recording author, timestamp, and changes for every modification").

## 6. Seed data

| Script | What it creates | Idempotent |
|---|---|---|
| `scripts/install-network.sh` | Network, five unit sites, time zone Asia/Kolkata, date `d/m/Y`, permalinks `/%postname%/` | Yes |
| `scripts/setup-languages.php` | English (default, `en_GB`) and Hindi (`hi_IN`) with directory URLs `/hi/` | Yes |
| `scripts/seed-home-pages.php` | Home page per language | Yes |
| `scripts/seed-site-structure.php` | Provisional information architecture pages (EN + HI), GIGW policy pages, categories, sample updates, menus, per-site facts | Yes (creates only what is missing) |
| `scripts/migrations/001-content-types.php` | Content-type listings replace placeholder pages; starter departments; the TMC EOI notice on TMC and TMH sites; clearly labelled SAMPLE tenders, openings, events and doctor profiles | Run once per site |
| `scripts/create-demo-users.sh` | One demo account per role (UAT only; passwords only in `demo-users.txt`) | Yes |

The information architecture is **provisional** until TMC supplies Annexure B; then it is replaced by
new migrations in `scripts/migrations/`. All sample content carries `_tmc_sample = 1`; the pre-Go-Live checklist
deletes it (see [Installation and Deployment Guide](../operations/installation-deployment.md#7-pre-go-live-content-clean-up)).

## 7. Migrations

`scripts/migrate.php` runs every file in `scripts/migrations/` once per site in name order and records
the name in the site option `tmc_migrations`; each application is written to the audit log
(`migration_applied`). A migration returns `false` to fail the provisioning run. Number ranges per feature
area are fixed in [Engineering Conventions](../engineering/CONVENTIONS.md#migration-numbers-avoid-collisions).

To list what has been applied on a site:

```bash
docker compose run --rm -T wpcli --url=tmh.<base-domain> option get tmc_migrations --format=json
```

## 8. Data that is never stored

| Data | Reason |
|---|---|
| Clinical or patient data | SOW §4.4; the application gateway and front ends pass data through to TMC's applications without storing it |
| Payment card or bank data | Handled only by the TMC-approved payment gateway |
| Secrets (DB passwords, audit key, API credentials) | Environment only (`getenv`), never in the database |
