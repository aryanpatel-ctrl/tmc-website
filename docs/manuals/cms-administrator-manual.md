# CMS Administrator Manual

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-MAN-01 | 0.1 | Draft; screens of parallel work streams confirmed at integration | R-4.15-6, R-8.2-6, R-4.16-2, R-4.6-\*, R-4.13-2, R-2-10 |

**Audience.** TMC IT officers who hold the **Super Admin** role (whole network) and unit **Site
Administrators** (one website). Content editing itself is covered by the
[Content Editor Manual](content-editor-manual.md); servers, backups and secrets by the
[System and Security Administration Manual](system-security-administration-manual.md).

**Conventions.** *Italic arrows* such as *Network Admin → Sites* describe where to click in the
WordPress administration screens (`/wp-admin/`).

---

## 1. The network at a glance

The six websites are one WordPress Multisite **network**: one login, one set of user accounts, one code
base and one security framework. Each website has its own content, menus, settings and users with roles.

| # | Website | Address (Production, indicative) | Admin screens |
|---|---|---|---|
| 1 | Tata Memorial Centre (main site) | `https://tmc.gov.in/` | `https://tmc.gov.in/wp-admin/` |
| 2 | Tata Memorial Hospital, Mumbai | `https://tmh.tmc.gov.in/` | `…/wp-admin/` on that address |
| 3 | HBCH & RC, Visakhapatnam | `https://hbchrcv.tmc.gov.in/` | as above |
| 4 | MPMMCC & HBCH, Varanasi | `https://mpmmcc.tmc.gov.in/` | as above |
| 5 | HBCH & RC, Muzaffarpur | `https://hbchrcmzp.tmc.gov.in/` | as above |
| 6 | HBCH, New Chandigarh | `https://hbchpunjab.tmc.gov.in/` | as above |

Two kinds of administration screen exist:

| Screen | Reached by | Who | What it controls |
|---|---|---|---|
| **Network Admin** | *My Sites → Network Admin* (top bar) | Super Admin only | Sites, all users, network settings, themes and plugins, **Audit Log** |
| **Site dashboard** | *My Sites → <site name> → Dashboard* | Site Administrator and editorial roles of that site | Content, media, menus, site settings, site users |

Every website has an English version and a Hindi version (under `/hi/`). Each page in one language is
linked to its translation.

## 2. Signing in and protecting your account

1. Open `https://<site>/wp-login.php` from a TMC network or the TMC-approved VPN. Administrative
   access from other networks is refused with "403 Forbidden" (the allowed ranges are set by TMC IT in
   `TMC_ADMIN_ALLOW_CIDRS`).
2. Enter your username and password, then the six-digit code from your authenticator application
   (multi-factor authentication is mandatory for Super Admins, Site Administrators and Reviewer /
   Publishers). At your first sign-in you are taken to *Users → Profile* to set up the authenticator
   (scan the QR code, enter one code) and to download your backup codes; nothing else opens until then.
3. Never share an account. Never send a password by e-mail or chat. Use *Users → Profile* to change
   your password (at least 12 characters, with three of: capitals, small letters, digits, symbols).
   After five wrong passwords sign-in pauses for a minute, and longer after further failures.
4. Sign out when you leave the workstation (*top bar → your name → Log Out*). Privileged sessions end
   automatically after 30 minutes of inactivity, and after at most 12 hours in any case.

Every sign-in, failed sign-in and sign-out is recorded in the audit log (§9).

## 3. Roles

| Role (as shown in the CMS) | Scope | Can | Cannot |
|---|---|---|---|
| **Super Admin** | All sites | Everything: create sites, manage all users, network settings, themes, plugins, audit log | — (use sparingly; 2 named TMC IT officers) |
| **Site Administrator** | One site | Site settings, menus, contact details, users of that site, and everything a Reviewer / Publisher can do | Network settings, plugins, themes, other sites, audit log |
| **Reviewer / Publisher** | One site | Review, approve, publish and schedule all content; edit published content; return content with a review note | Change settings, menus or users |
| **Content Editor** | One site | Create and edit drafts of pages, news/notices and all content types; upload media; submit for review | **Publish**, schedule, or change anything that is already live |

The built-in WordPress "Author" role has been removed because it could publish without review. The
technical names are `administrator`, `editor` and `contributor`; the labels above are applied by the
`tmc-core` plugin (`roles.php`).

**TMC-wide content.** Centralised publishing of TMC-wide notices to selected unit websites is provided by
network publishing: on the TMC site, a news item, notice or event has a **Publish to unit websites**
panel where a TMC Reviewer / Publisher, Site Administrator or Super Admin selects the unit sites. Each
selected site receives a read-only synced copy that follows the original (updates, scheduling,
unpublishing, deletion). Changes are made once, on the original.

## 4. Users and roles

### 4.1 Create a user and give a role on one site (Super Admin)

1. *Network Admin → Users → Add New*: enter the username (use the TMC convention, e.g. `firstname.lastname`)
   and the official e-mail address. The user receives an e-mail to set a password (outbound e-mail must
   be configured; see [Configuration Reference §1.1](../operations/configuration-reference.md#11-feature-settings)).
2. *Network Admin → Sites → <site> → Edit → Users → Add Existing User*: choose the user and the role.
3. Record the request reference (the written request from the unit head) in the access register
   ([Access Control Policy §7](../architecture/access-control-policy.md#7-access-register)).

### 4.2 Add a user to your site (Site Administrator)

1. *Users → Add New* on your site.
2. For a person who already has an account on another TMC site, use **Add Existing User**, type the
   username or e-mail, choose the role and save.
3. Creating a brand-new account from a site dashboard is possible only if the Super Admin has enabled
   *Network Admin → Settings → "Allow site administrators to add new users"*; by default, request new
   accounts from TMC IT.

### 4.3 Change a role or remove a user

- *Users → All Users → <user> → Edit → Role*. The change is logged as `user_role_changed`.
- To remove someone from your site: *Users → All Users*, hover the user, **Remove**. This removes access
  to your site only (`user_removed_from_site`); the account remains for other sites.
- When a person leaves TMC, the Super Admin deletes the account from the network (*Network Admin →
  Users → Delete*, attributing content to another user). This is logged as `user_deleted_from_network`.
- Remove access **on the same day** as a transfer or exit.

### 4.4 Grant or revoke Super Admin (Super Admin)

*Network Admin → Users → <user> → Edit → "Grant this user super admin privileges"*. Logged as
`super_admin_granted` / `super_admin_revoked`; reviewed monthly.

### 4.5 Quarterly access review

Every quarter each Site Administrator exports or screenshots *Users → All Users* for their site, marks
accounts that are no longer needed, and confirms the list to TMC IT. TMC IT confirms the Super Admin
list. The result goes into the
[Quarterly Security and Performance Review](../operations/templates/quarterly-security-performance-review.md).

## 5. Site settings

| Setting | Where | Notes |
|---|---|---|
| Site title and tagline | *Settings → General* | The Hindi site name is kept in the site option `tmc_name_hi` (set by provisioning). Changes are logged (`setting_changed`). |
| Time zone, date and time format | *Settings → General* | Provisioned as Asia/Kolkata, `d/m/Y`, `h:i A`. Do not change. |
| Permalinks | *Settings → Permalinks* | Provisioned as `/%postname%/`. Do not change: it would break links. |
| Search-engine visibility | *Settings → Reading* | Set by provisioning from the environment: off on Dev, CI and UAT; on for Production only. Outside production the sites also send `noindex` and a restrictive `robots.txt`. |
| Contact details and social media links | *Appearance → Customize → TMC contact details* | Address, phone, e-mail; Facebook, X, YouTube, Instagram, LinkedIn URLs. Shown in the footer and contact areas. |
| Hindi version of the address | *Languages → Translations*, group "TMC contact details" | Enter the Hindi text and save. |
| Menus | *Appearance → Menus* | See §5.1. |

### 5.1 Menus

The theme has three menu locations per language: **Main menu** (`primary`), **Footer: quick links**
(`footer-quick`) and **Footer: website policies** (`footer-policies`). Polylang shows each location once
per language (e.g. "Main menu English", "Main menu हिन्दी").

1. *Appearance → Menus*, choose the menu of the language you are editing.
2. Add pages, content-type listings (Departments, Find a Doctor, Careers, Tenders, Events) or
   categories from the left panel; drag to order; drag right to make a sub-item.
3. The **Description** of a top-level item is shown in the mega-menu; keep it to one short sentence.
4. Save. Repeat for the other language so both menus stay equivalent.

Menus are provisioned automatically on new sites; keep the GIGW policy pages (Copyright, Hyperlinking,
Privacy, Terms and Conditions, Accessibility Statement, Disclaimer, Help, Feedback, Sitemap) in the
policies footer menu.

## 6. Languages

### 6.1 Languages in use

English (`en`, locale `en_GB`, default, no URL prefix) and Hindi (`hi`, locale `hi_IN`, under `/hi/`)
are configured on every site by provisioning (`scripts/setup-languages.php`). Visitors choose the
language with the switcher; the browser language is not used to redirect.

### 6.2 Translating fixed interface text

Interface text of the theme (buttons, headings of listings, accessibility bar) is translated in code
(`src/themes/tmc/languages/`). Site-specific strings registered with Polylang (e.g. the address) are
translated in *Languages → Translations*.

### 6.3 Adding a third or fourth language (SOW §4.13)

No template code changes are needed. For each site (Super Admin):

1. Install the WordPress language pack through a release: add the locale to the language-pack step of
   provisioning (`scripts/setup.sh`) and to `scripts/setup-languages.php` so that every environment and
   every fresh installation get it (Change Request; normal pipeline release).
2. After the release, check *Languages → Languages* on each site: the new language is listed with its
   URL prefix (e.g. `/mr/`).
3. Translate the theme's interface strings: add `src/themes/tmc/languages/<locale>.l10n.php` with the
   translations of the strings wrapped in `__( '…', 'tmc' )` (translation work, no template change).
4. Create the menus for the new language (§5.1) and translate content page by page (Content Editor
   Manual §9).
5. Check the new script renders correctly; if the language uses a script not covered by the bundled
   Noto Sans fonts (Latin and Devanagari), add the matching Noto font file to the theme (licence: SIL
   OFL 1.1) through a release.

## 7. Templates, sections and content types

What administrators can configure in the CMS and what is fixed in code (to keep all six sites within the
TMC design system, SOW §5):

| Item | Configured in | By |
|---|---|---|
| Colours, fonts, sizes, spacing (design tokens) | `theme.json` (code); editors cannot pick custom colours or sizes | Change Request |
| Page templates and locked sections | Theme templates and patterns (code); home-page sections are locked so editors change text and links only | Change Request |
| Page template chosen for a page | Page editor: when a new page is created, the template picker offers seven locked starter templates (standard, section landing, contact, document listing, service, people, FAQ) | Editors |
| Content types (Tenders & EOIs, Events, Careers, Departments, Doctors) and their fields | `tmc_field_schema()` in `src/mu-plugins/tmc-core/content-types.php` (code) | Change Request |
| Which fields are required | Same schema (`required`) | Change Request |
| Listing sizes (tenders/jobs/events 20 per page, doctors 24) | Theme (`inc/content-views.php`) | Change Request |
| Dynamic blocks placed on pages (network cards, sitemap, notice board, latest news, tenders, jobs, events) and their options (category, number of items) | Block editor | Reviewer / Publisher |
| Menus, contact details, site title | CMS (§5) | Site Administrator |

### 7.1 Content types and their fields

| Content type | Listing address | Required fields | Automatic behaviour |
|---|---|---|---|
| Tender / EOI | `/tenders/` (archive: `?view=archive`) | Reference number; Last date and time of submission | Moves to the archive after the last date; closure recorded in the audit log (`tender_closed`) |
| Event | `/events/` (calendar: `?view=calendar`; past: `?view=past`) | Starts | Leaves "upcoming" after the start date; `.ics` calendar download |
| Job opening | `/careers/` (archive: `?view=archive`) | Advertisement number; Last date to apply | Moves to the archive after the last date (`job_opening_closed`) |
| Department | `/departments/` | — | Listed in page order, then alphabetically |
| Doctor | `/doctors/` (filter by department and name) | Designation | Linked to departments |
| News / Notice (post) | Category archives | — | Optional "Expires on": leaves the notice board and lists after that time (`content_expired`) |

The editor shows a "Details missing" warning when a required field is empty, so incomplete items are not
approved.

## 8. Adding a new unit website

Adding a unit is a supported procedure (SOW §2: "accommodating additional units"). It is performed by
TMC IT with the Vendor (during warranty/AMC) as a Change Request, because fresh installations and CI must
also know about the new site.

| Step | Action | Who |
|---|---|---|
| 1 | Agree the unit's subdomain slug (e.g. `newunit`) and full English and Hindi names | TMC |
| 2 | DNS record and TLS certificate for `newunit.<base domain>` (a wildcard certificate covers it) | TMC infrastructure |
| 3 | Add the slug and name to the site list in `scripts/install-network.sh`; add the slug to `SITES` in `scripts/setup.sh` and `scripts/smoke-test.sh`; add the per-site facts (Hindi name, city, address) to `scripts/seed-home-pages.php` and `scripts/seed-site-structure.php` | Vendor (pull request) |
| 4 | Run CI: it builds all sites including the new one from scratch and smoke-tests it | Pipeline |
| 5 | Deploy to UAT, then Production. `install-network.sh` creates the site; `setup.sh` activates the theme, languages, home page, migrations, pages and menus on it | Pipeline |
| 6 | The new site appears automatically in the network cards ("Our institutions") on every site | Automatic |
| 7 | Create the unit's Site Administrator and editors (§4), fill in contact details (§5) | Super Admin, Site Administrator |
| 8 | Migrate and approve the unit's content ([Content Migration Coordination](../operations/content-migration-coordination.md)) | Unit + Vendor |
| 9 | Go-Live acceptance for the new site ([Test Plan §9](../testing/test-plan.md#9-go-live-acceptance-per-website-sow-71)) | TMC IT |

Creating a site only through *Network Admin → Sites → Add New* is possible but **not sufficient**: the
provisioning scripts would not recognise it (they stop with "Unknown site"), and it would not exist in a
rebuilt or DR environment. Always use the procedure above.

## 9. Audit log

*Network Admin → Audit Log* (Super Admin only) lists every administrative action on all six websites.

### 9.1 Reading and filtering

| Column | Meaning |
|---|---|
| # | Sequence number |
| Time (IST) | When it happened |
| Site | Which website |
| User | Who (`system` = automatic job, `wp-cli` = provisioning or maintenance command) |
| IP | Client IP address (`cli` for command line) |
| Event | What happened, e.g. `content_published`, `user_role_changed`, `login_failed` |
| Object | What was affected (type, ID, title) |
| Details | Extra information: changed fields, previous and new status, language |
| Hash | Start of the entry's integrity hash (hover to see all of it) |

Use the **All sites**, **All events** and **Username** filters, then **Filter**. Fifty entries are shown
per page.

The events recorded are listed in the
[Security Architecture §6.1](../architecture/security-architecture.md#61-cms-audit-log-in-place).

### 9.2 Verifying integrity

Click **Verify integrity**. The system recomputes the HMAC-SHA256 chain of every entry:

- *"Integrity verified. All N entries are intact. Latest hash: …"* — nothing has been altered. For the
  quarterly anchor, copy the count and the full latest hash into the quarterly review.
- *"Tampering detected at entry #N"* — an entry from #N onwards was altered, deleted or re-ordered.
  Treat this as a **P1 security incident** ([Incident and Support Model §6](../operations/incident-support-model.md#6-security-incidents)):
  do not change anything, export the log, and inform TMC IT and the Vendor immediately.

Each verification is itself logged (`audit_log_verified`).

### 9.3 Exporting

**Export CSV** downloads the entries that match the current filters (UTF-8, opens correctly in
spreadsheet software, Hindi included). Values that could be interpreted as spreadsheet formulas are
neutralised. Each export is logged (`audit_log_exported`). Store exports according to the
[Audit Log Retention Policy](../architecture/audit-log-retention-policy.md).

## 10. Media and documents

- Upload limits: maximum file size 64 MB (`upload_max_filesize`); allowed file types in *Network Admin →
  Settings → Upload file types* (changes logged as `network_setting_changed`). Keep executable and
  script types off the list.
- One media library per site, shared by English and Hindi.
- Documents attached to tenders and job openings are chosen from the media library in the item's
  **Details** box.
- Documents get a **Document type** and **Document date** in the media library; the public
  **Documents** page (`/documents/`) lists them with type and year filters, and site search finds text
  inside PDFs. Super Admins see every site's uploads under *Network Admin → Media & documents*, where
  "Extract text now" processes documents whose text has not been read yet.

## 11. Review workflow administration

| What | Where |
|---|---|
| Items waiting for review on a site | Dashboard panel **Awaiting your review** and the **Review queue (n)** counter in the top bar (Reviewer / Publisher and above) |
| An editor's own items and their status | Dashboard panel **My submissions** (Content Editor) |
| Notifications | E-mail to reviewers when content is submitted; to the author when it is returned, approved and published, or approved and scheduled |

If notifications do not arrive, outbound e-mail is not configured on that environment (EOI query Q-23;
see [Installation Guide §8](../operations/installation-deployment.md#8-troubleshooting)).

## 12. Scheduled publishing and automatic expiry

- A Reviewer / Publisher can **schedule** publication (*Publish → change "Immediately" to a date*). The
  scheduler runs every minute on every site.
- Tenders and job openings close, notices expire and events become past **automatically** at the dates
  in their fields. Listings decide this at the moment of each request, so no manual action is needed;
  the automatic job records each closure once in the audit log (every 5 minutes).
- If scheduled items are not published on time, check the `cron` container (System and Security
  Administration Manual §4).

## 13. Routine administration checklist

| Frequency | Task | Who |
|---|---|---|
| Daily | Clear the review queue of your site | Reviewer / Publisher |
| Weekly | Check that listings show current tenders, openings and events; remove obsolete notices | Site Administrator |
| Monthly | Review privileged events in the audit log (§9) | Super Admin |
| Quarterly | Verify audit-log integrity and record the anchor; access review (§4.5) | Super Admin, Site Administrators |
| On staff change | Add/remove users the same day (§4.3) | Site Administrator / Super Admin |

## 14. Getting help

Log a ticket using the **Support incident** form of the project tracker; for a critical problem (a
website down, defacement, suspected account compromise) telephone the Vendor Support Lead as well. See
the [Incident and Support Model](../operations/incident-support-model.md).
