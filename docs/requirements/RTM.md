# Requirements Traceability Matrix (RTM)

**Source documents**
- **[SOW]** *EOI Document — Development, CMS Implementation, Deployment, Content Migration, Security Certification, Training, Warranty and Maintenance of the TMC Website Ecosystem* (Sr. No. 1, 20 pages), EOI No. TMH/TMH/2026-27/CAP/EO/0009, 28/09/2026.
- **[EOI]** *Invitation for Expression of Interest* — TMH Purchase Department, 28/09/2026 (9 pages: notice, introduction, eligibility, response form, Vendor Capability Form, checklist).

**How to read this file** — every requirement has an ID (`R-<section>-<n>`), the page it comes from, its
**type**, current **status**, the **workstream** that owns it, and how it is **verified**. This file is the
specification for the build; a requirement is only "Done" when its verification passes in CI or is
evidenced in `docs/`.

| Type | Meaning |
|---|---|
| **BUILD** | Software we build and demonstrate |
| **OPS** | Environment / infrastructure / operations capability |
| **DOC** | Document deliverable (produced in `docs/`) |
| **PROC** | Project process (governance, support) — templates + procedure in `docs/` |
| **CERT** | External certification procured by the vendor (VAPT / STQC / Safe-to-Host) — we build *readiness* |
| **BID** | Bidder/company evidence (eligibility, CVs, financials) — outside the software |

| Status | Meaning |
|---|---|
| ✅ Done | Implemented and verified by automated tests |
| 🟡 Partial | Implemented in part; gap noted |
| 🔲 Planned | Assigned to a workstream (W1–W8 below) |
| ⛔ Blocked | Needs an input from TMC (e.g. Annexures A/B/C) |

**Scope decision (29/09/2026):** new features are built **English-first**. Hindi remains live for existing
content and all new interface strings are wrapped for translation (`__( '…', 'tmc' )`), so adding Hindi
later is translation work only (R-4.13).

---

## Workstreams

| ID | Workstream | Main clauses |
|---|---|---|
| W1 | Search and document library | 4.6 (media/document libraries), 4.12 (search) |
| W2 | Security hardening and security testing | 4.8, 2 |
| W3 | SEO, structured data, redirects, analytics | 4.10, 4.11 (redirects) |
| W4 | TMC application gateway and front ends, maps, social | 4.3 (maps/social), 4.4, 4.12 |
| W5 | Editorial platform: page templates, network publishing, component library | 4.3, 4.6, 5, M3 |
| W6 | Quality gates: cross-browser, accessibility, performance, links, HTML validity, acceptance report | 4.9, 4.14, 7.1 |
| W7 | Performance, backup/DR, environments and monitoring | 4.7, 6 |
| W8 | Documentation, training, governance, EOI response pack | 4.15, 4.16, 8, 12.1, 14, EOI |

---

## 1. Introduction and background — SOW p.4

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-1-1 | Unified, secure, accessible, citizen-centric ecosystem for patients & caregivers, referring clinicians, students & researchers, donors, prospective employees and the public | BUILD | 🟡 IA covers patients, education, research, careers, donate; add referral/clinician entry points | W5 | IA review; menu test |
| R-1-2 | TMC website + constituent unit websites on a common platform, CMS and security framework | BUILD | ✅ WordPress Multisite, 6 sites | — | smoke-test (12 home URLs) |
| R-1-3 | Design system, UI/UX, IA, templates and Figma are provided by TMC and binding; vendor implements as provided (no design work) | BUILD | ⛔ Annexures A/B/C not yet received — theme is token-driven (`theme.json`) so the design swaps in without template rewrites | W5 | design-conformance checklist (R-5-1) |

## 2. Objectives — SOW p.4

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-2-1 | Implement, develop and deploy on a suitable CMS conforming to the design system and IA | BUILD | 🟡 | W5 | R-5-1 |
| R-2-2 | CMS lets TMC and unit editors create, edit, review and publish without code | BUILD | ✅ roles + workflow; 🔲 page-template picker | W5 | workflow-test, content-test |
| R-2-3 | Frontend presentation layer for TMC-developed applications and transactional modules | BUILD | 🔲 | W4 | gateway tests |
| R-2-4 | Migrate TMC-provided content accurately and completely | BUILD | 🔲 migration toolkit | W3/W6 | migration test + link report |
| R-2-5 | Secure, accessible, high-performing, responsive; meets GoI digital-governance, accessibility and cyber-security requirements | BUILD | 🟡 | W2/W6 | acceptance report (R-7.1) |
| R-2-6 | Public website segregated from clinical and patient-service systems | OPS | 🟡 internal DB network; 🔲 security architecture doc + gateway | W2/W4/W8 | segregation evidence (R-4.8-1) |
| R-2-7 | Functional, performance and security testing; obtain certifications | BUILD/CERT | 🟡 | W6/W2 | CI reports |
| R-2-8 | Documentation, role-based training, warranty, AMC, post-Go-Live support | DOC/PROC | 🔲 | W8 | docs present |
| R-2-9 | Handover of source, repositories, configurations, documentation free of proprietary lock-in | DOC | 🟡 repo + scripts; 🔲 handover guide, licence list | W8 | handover checklist |
| R-2-10 | Scalable, modular: add units, features, languages, integrations | BUILD | ✅ multisite + Polylang + migrations | — | add-site procedure in docs (W8) |

## 3. Design inputs — SOW p.5

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-3-1 | Annexure A: design strategy, design tokens, style guide, reusable component library — implement strictly | BUILD | ⛔ awaiting TMC; tokens + living component library ready to receive it | W5 | component library page |
| R-3-2 | Annexure B: IA and page-to-template mapping, full page list — implement | BUILD | ⛔ awaiting TMC; provisional IA seeded | W5 | IA/menus via migrations |
| R-3-3 | Annexure C: interactive Figma (desktop + mobile) — implement | BUILD | ⛔ awaiting TMC | W5 | visual regression (W6) |
| R-3-4 | No redesign proposal or design fee | BID | n/a | — | — |

## 4.1 Websites in scope — SOW p.5–6

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.1-1 | Six websites: tmc.gov.in, tmh., hbchrcv., mpmmcc., hbchrcmzp., hbchpunjab. (subdomain names confirmed at award) | BUILD | ✅ (demo domain `*.tmc.<env>`; one variable to change) | — | smoke-test |
| R-4.1-2 | Common design system, component library, CMS and security framework | BUILD | ✅ | — | — |
| R-4.1-3 | Units substantially similar after TMC + first unit — reuse, low incremental effort | BUILD | ✅ one seeding/migration path for every site | — | CI provisions all six from scratch |

## 4.2 Summary of scope (13 equal-weight work streams) — SOW p.6

Covered by the rows below: website development (4.3), CMS (4.5–4.6), frontend (4.3–4.4), deployment (4.7), migration (4.11), security (4.8), functional testing (4.14), performance testing (4.10/4.14), documentation (4.15), training (4.16), warranty (6.1), AMC (6.2), post-Go-Live support (6.3–6.4).

## 4.3 Website development and frontend implementation — SOW p.6–7

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.3-1 | Complete reusable component library, page templates and content types (Annexures A/B) | BUILD | 🟡 components + content types; 🔲 living component library page | W5 | component library page lists every component |
| R-4.3-2 | Editors create any page by **selecting a template** and filling **defined content fields**, without HTML/CSS/code | BUILD | 🟡 locked home sections; 🔲 page-template picker with locked starter templates | W5 | e2e: create page from each template |
| R-4.3-3 | Volume: ~80–100 CMS-managed pages per site | BUILD | ✅ 36+ seeded per site per language; scales | — | — |
| R-4.3-4 | Site-wide: navigation, header, footer, breadcrumbs, sitemap, search | BUILD | ✅ | — | smoke-test |
| R-4.3-5 | Site-wide: event calendar | BUILD | ✅ month calendar, ICS | — | content-test, smoke |
| R-4.3-6 | Site-wide: location maps | BUILD | 🔲 accessible map block (no API key, text alternative + directions link) | W4 | smoke |
| R-4.3-7 | Site-wide: social media links | BUILD | 🟡 per-site settings exist; 🔲 configured + share links | W4 | smoke |

## 4.4 Frontend interfaces for TMC-developed applications — SOW p.7

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.4-1 | Frontend presentation layer for TMC apps (online forms, patient services, EMR, appointments, payment gateway, exam/results) consistent with the design system | BUILD | 🔲 appointment, results lookup, generic online form, donation hand-off | W4 | gateway tests + e2e |
| R-4.4-2 | Integrate with TMC backends only through TMC-approved endpoints | BUILD | 🔲 allow-listed endpoint registry + server-side gateway | W4 | gateway test: unknown endpoint refused |
| R-4.4-3 | No business logic/backend by vendor; **no access to clinical or patient data** (website stores none) | BUILD | 🔲 pass-through, no persistence of submissions | W4 | test: DB contains no submitted fields |

## 4.5 CMS platform — SOW p.7

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.5-1 | Widely adopted, maintained open-source CMS (Drupal / WordPress multisite preferred) | BUILD | ✅ WordPress Multisite | — | — |
| R-4.5-2 | Full ownership, unrestricted rights, no proprietary/licensing dependency | BUILD/DOC | ✅ GPL only, no premium plugins; 🔲 licence inventory | W8 | THIRD-PARTY-LICENSES |

## 4.6 CMS functional requirements — SOW p.7

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.6-1 | Code-free creation/editing of all pages with approved templates and defined fields | BUILD | 🟡 (see R-4.3-2) | W5 | e2e |
| R-4.6-2 | RBAC: **centralized publishing of TMC-wide content** and decentralized unit-level management; roles admin / editor / reviewer-publisher | BUILD | ✅ roles + unit isolation; 🔲 **network publishing** (TMC publishes a notice to selected unit sites) | W5 | network-publish test |
| R-4.6-3 | Content workflow with review and approval before publication | BUILD | ✅ | — | workflow-test (20 checks) |
| R-4.6-4 | Version history and audit trail: author, timestamp, changes for every modification | BUILD | ✅ revisions + HMAC-chained audit log | — | workflow-test tamper check |
| R-4.6-5 | Scheduled publishing and **automatic expiry** (announcements, EOIs, events) | BUILD | ✅ | — | content-test (27 checks) |
| R-4.6-6 | **Central management of media and document libraries** | BUILD | 🔲 document library (types, listing, filters) + network-wide media overview | W1 | test + smoke |
| R-4.6-7 | Multilingual content management (4.13) | BUILD | ✅ | — | — |
| R-4.6-8 | Secured administrative access (4.8) | BUILD | ✅ admin network allow-list, TOTP MFA enforced for privileged roles, lockout, idle timeout, password policy | W2 | security-test (sections 1, 3–7) + smoke `security.sh` |

## 4.7 System architecture, hosting and deployment — SOW p.8

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.7-1 | Hosting on TMC-owned/subscribed infra; MeitY-empanelled cloud in TMC's name; all data, logs, backups in India | OPS/DOC | 🔲 container stack is host-agnostic; data residency statement | W8 | doc |
| R-4.7-2 | Data residency compliance statement | DOC | 🔲 | W8 | doc |
| R-4.7-3 | Separate **Dev, UAT, Production, DR** environments | OPS | 🟡 Dev + CI + UAT live; Production (`compose.prod.yml`, `release.yml`: tag → gate → approval) and DR (`make-env.sh dr`, isolated drill project) built — ⛔ production/DR hosts are TMC infrastructure | W7 | ops-checks.yml (compose), dr-drill.yml, docs/operations/environments.md |
| R-4.7-4 | Controlled, auditable promotion of code; version control; rollback | OPS | ✅ Git + pipeline + release history + DB backup; rollback via workflow_dispatch | — | pipeline |
| R-4.7-5 | Prod/DR infra owned by TMC; vendor access limited and logged | OPS/DOC | 🟡 key-restricted, switchable access; 🔲 access policy doc | W8 | doc |
| R-4.7-6 | **RPO 15 minutes, RTO 1 hour**, demonstrated through periodic drills | OPS | ✅ backup every 15 min + off-host copy; timed drill (RPO/RTO measured) from the off-host copy, monthly in CI | W7 | dr-drill.yml report; health `backup`/`offsite` ages |
| R-4.7-7 | Single CMS and security framework for TMC and all unit subdomains | BUILD | ✅ | — | — |
| R-4.7-8 | Scheduled backup of content, databases, configurations; documented, tested restore | OPS | ✅ DB + uploads/plugins/languages + config, checksummed, retention 48 h/30 d/12 m, audit-logged; restore.sh; docs/operations/backup-and-dr.md | W7 | backup-test.php, backup-retention-test.sh, dr-drill.yml |
| R-4.7-9 | Sustain peak load without degrading agreed thresholds; scale for more units/languages/modules | OPS | ✅ Redis object cache + full-page cache (purge on change) + Brotli/static caching; k6 capacity test; thresholds to agree with TMC (docs/operations/capacity.md) | W7/W6 | perf-test.php, smoke.d/cache.sh, capacity.yml report |

## 4.8 Security and segregation from clinical systems — SOW p.8–9

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.8-1 | Website/CMS in a network zone segregated from clinical systems; no direct connectivity; only allow-listed integration endpoints | OPS/BUILD | 🟡 DB/cache on internal network; 🔲 gateway + documented zones | W4/W8 | segregation test + doc |
| R-4.8-2 | Separate infrastructure accounts, databases, credentials | OPS | ✅ own DB, users, secrets | — | — |
| R-4.8-3 | Admin access: **MFA**, **restricted network access**, role-based authorisation | BUILD | ✅ RBAC; TOTP MFA (Two Factor 0.17.0) enforced for Super Admin / Site Administrator / Reviewer-Publisher (`TMC_ENFORCE_MFA`); admin allow-list (`TMC_ADMIN_ALLOW_CIDRS`) | W2 | security-test + smoke `security.sh` (403 outside the allow-list) |
| R-4.8-4 | Tamper-evident audit logs of all admin activity; retained; available to TMC | BUILD | ✅ HMAC chain, verify, CSV export; 🔲 retention policy doc | W8 | workflow-test |
| R-4.8-5 | Secure development/deployment; **every release vulnerability-assessed before production** | OPS | 🟡 CI security gate built (`.github/workflows/security.yml`: gitleaks, Trivy, OWASP ZAP baseline); 🔲 wire into `pipeline.yml` before deploy (integrator) | W2 | pipeline gate + report artifacts |
| R-4.8-6 | GIGW 3.0, WCAG 2.2 AA, W3C standards, protection against OWASP Top 10 | BUILD | 🟡 OWASP Top 10 mapping (`docs/security/owasp-top10.md`), nonce-based CSP and security headers (W2); W3C HTML validity gate (Nu HTML Checker, 0 errors, every template × 6 sites) + axe gate (W6) | W2/W6 | security-test, smoke, ZAP report; quality.yml `html`/`a11y` reports |
| R-4.8-7 | VAPT by CERT-In empanelled agency; STQC certification; Safe-to-Host before Go-Live (costs to vendor) | CERT | 🟡 pre-VAPT checklist (`docs/security/pre-vapt-checklist.md`), security.txt (contact ⛔ TMC to confirm); 🔲 external VAPT / STQC / Safe-to-Host | W2/W8 | pre-VAPT report |
| R-4.8-8 | Security architecture document (segregation, network/data flows, access controls) at M2, re-validated before Go-Live | DOC | 🔲 | W8 | doc |
| R-4.8-9 | Close all VAPT/STQC/TMC observations at no cost | PROC | 🔲 remediation procedure | W8 | doc |

## 4.9 Accessibility, responsiveness, browsers — SOW p.9

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.9-1 | WCAG 2.2 AA and GIGW 3.0 (stricter prevails) | BUILD | ✅ automated axe scan (WCAG 2.0/2.1/2.2 A+AA) of every template on every site, 1280 + 360 px + high contrast; manual audit checklist in docs/testing/quality-gates.md | W6 | axe report (0 violations) |
| R-4.9-2 | Responsive across mobile/tablet/desktop breakpoints | BUILD | ✅ E2E at 360/768/1280 px, no sideways scrolling on any template | W6 | Playwright |
| R-4.9-3 | Full functionality on current major desktop and mobile browsers | BUILD | ✅ Chromium, Firefox, WebKit | W6 | Playwright |

## 4.10 SEO, performance, analytics — SOW p.9

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.10-1 | Clean, readable URLs | BUILD | ✅ (Hindi slugs transliterated in seed) | — | — |
| R-4.10-2 | Complete, **editor-managed** page metadata (title, description, social) | BUILD | 🔲 | W3 | test |
| R-4.10-3 | Structured data | BUILD | 🔲 JSON-LD: organisation, website search, breadcrumbs, events, job postings | W3 | test + validator |
| R-4.10-4 | Sitemap and crawl directives | BUILD | 🟡 core XML sitemap; 🔲 robots per environment, sitemap in robots | W3 | smoke |
| R-4.10-5 | Correct handling of redirects | BUILD | 🔲 redirect manager (301/410, CSV import, hit log) | W3 | test |
| R-4.10-6 | Performance thresholds per template, desktop + mobile, verified by an industry-standard tool | BUILD | ✅ Lighthouse CI budgets per template, desktop + mobile (docs/testing/thresholds.md; targets to be confirmed by TMC) | W6 | Lighthouse report |
| R-4.10-7 | TMC-approved web analytics + search console, dashboards accessible to TMC | BUILD | 🔲 analytics/search-console settings (self-hosted, India-resident option) | W3 | test |

## 4.11 Content migration — SOW p.9

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.11-1 | Migrate TMC-approved content | BUILD | 🔲 CSV/inventory importer | W3 | import test |
| R-4.11-2 | Format and map content to templates and content types | BUILD | 🔲 importer maps template + parent + type | W3 | import test |
| R-4.11-3 | Complete, correctly rendered, correctly placed in IA | BUILD | 🟡 published URL inventory + link scan (every page reachable and answering) in the acceptance report; 🔲 content-vs-inventory comparison once the W3 importer exists | W6 | report |
| R-4.11-4 | Redirects for superseded URLs; **zero broken links, no orphaned pages** by automated scan; clean report before Go-Live | BUILD | 🟡 crawler with broken-link + orphan detection, CSV + HTML (W6 ✅); 🔲 redirect manager (W3) | W3/W6 | link report |
| R-4.11-5 | Coordinate receipt of content and post-migration confirmation | PROC | 🔲 content inventory template + sign-off form | W8 | doc |

## 4.12 Integrations — SOW p.10

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.12-1 | Access to TMC apps via secure, approved endpoints; internal URLs never exposed publicly | BUILD | 🔲 gateway hides upstream URLs | W4 | test: page source has no upstream host |
| R-4.12-2 | Standards-based interfaces with authentication, rate control, logging | BUILD | 🔲 | W4 | gateway test |
| R-4.12-3 | Frontend integration of TMC-approved payment gateway for donations | BUILD | 🔲 hand-off + return/verify screens (mock gateway in demo) | W4 | e2e |
| R-4.12-4 | Social media channels, event calendars, location maps | BUILD | 🟡 calendar done | W4 | smoke |
| R-4.12-5 | Site-wide **full-text search with auto-suggestions and paginated results across web content and documents** | BUILD | 🟡 basic search; 🔲 PDF text indexing, accessible autosuggest, filters | W1 | search test |

## 4.13 Multilingual — SOW p.10

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.13-1 | English and Hindi at Go-Live (content by TMC) | BUILD | ✅ platform; new features English-first (scope decision) | — | smoke `/hi/` |
| R-4.13-2 | Add languages (3–4 total) without template-level code changes | BUILD | ✅ Polylang | — | doc: add-language procedure (W8) |

## 4.14 Testing and QA — SOW p.10

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-4.14-1 | Test plan for TMC approval | DOC | 🔲 | W8 | doc |
| R-4.14-2 | Functional + integration tests of all templates, interfaces, CMS workflows | BUILD | ✅ PHP suites + smoke + Playwright E2E of every template type | W6 | CI |
| R-4.14-3 | Cross-browser and cross-device | BUILD | ✅ Chromium, Firefox, WebKit × phone/tablet/desktop | W6 | CI |
| R-4.14-4 | Performance and load testing against thresholds | BUILD | 🟡 Lighthouse gate in CI (W6); k6 load test (W6 load-test.yml, manual, UAT) and capacity test (W7 capacity.yml) with thresholds; 🔲 peak run on UAT + TMC-agreed targets | W6/W7 | reports |
| R-4.14-5 | Security testing incl. VAPT | BUILD/CERT | 🔲 | W2 | reports |
| R-4.14-6 | UAT support: defect logging, tracking, closure; defect closure report before each Go-Live | PROC | 🔲 issue templates + report script | W8 | doc |

## 4.15 Documentation — SOW p.10

| ID | Deliverable | Type | Status | Owner |
|---|---|---|---|---|
| R-4.15-1 | System architecture document | DOC | 🔲 | W8 |
| R-4.15-2 | Installation and deployment guides | DOC | 🟡 README | W8 |
| R-4.15-3 | Configuration documentation | DOC | 🔲 | W8 |
| R-4.15-4 | Integration and interface documentation | DOC | 🔲 | W8/W4 |
| R-4.15-5 | Backup and restoration procedures | DOC | 🔲 | W8/W7 |
| R-4.15-6 | CMS administrator manuals | DOC | 🔲 | W8 |
| R-4.15-7 | Content editor user manuals | DOC | 🔲 | W8 |
| R-4.15-8 | All test and certification reports | DOC | 🟡 test reports generated by CI (artifact quality-reports); certificates external | W6 |
| R-4.15-9 | Updated at each release; handed over in **editable and portable formats** | DOC | 🔲 Markdown source + generated PDF/DOCX | W8 |

## 4.16 Training — SOW p.10

| ID | Requirement | Type | Status | Owner |
|---|---|---|---|---|
| R-4.16-1 | Role-based training: TMC IT + CMS admins; TMC-level and unit-level editors | DOC/PROC | 🔲 training plan per role | W8 |
| R-4.16-2 | Topics: CMS admin, users/roles, template & content-type configuration, content creation, media, publishing workflow, deployment, backup, troubleshooting | DOC | 🔲 | W8 |
| R-4.16-3 | Role-specific material, user manuals, quick reference guides (TMC property) | DOC | 🔲 | W8 |

## 5. Design conformance, validation, change control — SOW p.11

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-5-1 | Strict conformance (typography, spacing, colour, layout, behaviour); deviations only with prior written approval | BUILD/PROC | 🟡 tokens lock editor choices; visual regression gate built (baselines to be approved after Annexure C); 🔲 deviation register | W6/W8 | visual diff |
| R-5-2 | Independent review/validation at any stage; address observations | PROC | 🔲 | W8 | doc |

## 6. Warranty, maintenance, post-Go-Live — SOW p.11–12

| ID | Requirement | Type | Status | Owner | Verification |
|---|---|---|---|---|---|
| R-6-1 | 12-month warranty from Project Closure (defects, security fixes, performance, support) | PROC | 🔲 | W8 | doc |
| R-6-2 | 4-year AMC: preventive maintenance, CMS/content support, hardening, tuning, minor enhancements | PROC | 🔲 | W8 | doc |
| R-6-3 | Upgrades of core/plugins/frameworks/DB; security patches within 30 days, critical on priority | OPS | ✅ Dependabot (actions, images, compose) + weekly wordpress.org check of pinned plugins/core (updates.yml) + docs/operations/patching.md | W7/W8 | Dependabot config, updates.yml |
| R-6-4 | Annual VAPT, Safe-to-Host renewal, STQC re-certification, annual security review report | CERT/DOC | 🔲 report template | W8 | doc |
| R-6-5 | Points of contact, escalation matrix, logged support channel; **monthly support report** (incidents, resolution times, SLA) | PROC | 🔲 | W8 | template |
| R-6-6 | SLA: availability 99.5% monthly | OPS | ✅ health endpoint + Uptime Kuma monitor set (docs/operations/monitoring.md) + monthly availability report script; monitoring host provided at go-live | W7 | health-test.php, availability-report-test.sh |
| R-6-7 | SLA: critical 4 business hours, high 1 business day, medium 3 business days | PROC | 🔲 | W8 | doc |
| R-6-8 | Quarterly security and performance review report | DOC | 🔲 template (inputs from CI reports) | W8 | doc |

## 7. Timeline, milestones, acceptance — SOW p.12–13

| ID | Requirement | Type | Status | Owner |
|---|---|---|---|---|
| R-7-1 | M1–M6 deliverables and payment stages (10/15/20/20/20/15%) | PROC | 🔲 execution plan mapped to this repo | W8 |
| R-7-2 | M2: environments, security architecture, data residency statement | OPS/DOC | 🔲 | W7/W8 |
| R-7-3 | M3: component library, templates editable, content types, roles, workflow, nav/header/footer — demo & sign-off | BUILD | 🟡 | W5 |
| R-7.1-1 | Go-Live acceptance per website: design conformance; zero broken links/orphans; WCAG 2.2 AA; responsive + browsers; performance per template; VAPT closed + segregation evidence; Safe-to-Host/STQC; migration confirmed; docs | BUILD | ✅ **automated Go-Live acceptance report** per site (tests/report/acceptance.js; PASS/FAIL/MANUAL) | W6 |

## 8. Deliverables and handover — SOW p.13–14

| ID | Requirement | Type | Status | Owner |
|---|---|---|---|---|
| R-8.1-* | Deliverables list (component library, templates, configured CMS, sites, app front ends, migrated content + reports, test reports, certificates, security architecture, docs & training) | mixed | tracked by rows above | all |
| R-8.2-1 | Full source with inline documentation | DOC | ✅ | — |
| R-8.2-2 | Version-controlled repository with history, branches, access transfer | DOC | ✅ Git (private GitHub) | — |
| R-8.2-3 | Build/deploy scripts and provisioning automation | DOC | ✅ | — |
| R-8.2-4 | Config files and documented settings (credentials handed over separately) | DOC | 🟡; 🔲 configuration reference | W8 |
| R-8.2-5 | Database schema, seed data, migration scripts | DOC | ✅ seed + migrations; 🔲 schema reference | W8 |
| R-8.2-6 | Technical docs; admin manuals (CMS + system & security); user manuals; install/deploy/backup/restore guides | DOC | 🔲 | W8 |
| R-8.2-7 | List of all third-party components with licences | DOC | 🔲 generated | W8 |
| R-8.2-8 | Handover accepted after demonstrating independent build/deploy/operate | PROC | ✅ CI builds everything from scratch; 🔲 handover runbook | W8 |

## 9–12. Eligibility, team, evaluation, bid — SOW p.14–18

| ID | Requirement | Type | Status | Owner |
|---|---|---|---|---|
| R-9-* | Legal status, ≥3 years, 3 large portals in 5 years, multi-site, CMS expertise, CERT-In/STQC experience, ₹1 cr turnover incl. one ≥ ₹25 lakh, **ISO 27001 mandatory**, 2 on-time projects, non-blacklisting | BID | company evidence | bidder |
| R-10-* | Named team: PM 1, frontend 2, backend/CMS 2, infra/security 1, QA 1, support lead 1 (with CVs) | BID | RACI template | W8 |
| R-11-* | QCBS 70/30; technical ≥ 60/100; presentation/demonstration may be required | BID | demo-ready UAT + proposal docs | W8 |
| R-12.1-* | Technical bid: solution & architecture; CMS justification; execution plan; migration methodology; test strategy; security & compliance approach; documentation/training/warranty/AMC/support plan; deviations statement | DOC | 🔲 proposal pack | W8 |

## 13–15. IP, governance, general terms — SOW p.18–20

| ID | Requirement | Type | Status | Owner |
|---|---|---|---|---|
| R-13-1 | All code/configs/docs/content are TMC property | DOC | ✅ no vendor branding, GPL | — |
| R-13-2 | Only legally licensed software; OSS licences compatible with government use and transfer | DOC | 🔲 licence inventory | W8 |
| R-13-3 | No lock-in; transferable to TMC or a third party | DOC | ✅ | — |
| R-13-4 | **No promotional links, vendor branding or advertisements** | BUILD | ✅ (generator tag removed) | — |
| R-13-5 | Confidentiality of TMC design, IA, content, systems; NDA | PROC | — | bidder |
| R-14-1 | Steering committee; fortnightly reviews; monthly progress report; written acceptance | PROC | 🔲 templates | W8 |
| R-14-2 | Risk register and escalation matrix, updated monthly | PROC | 🔲 | W8 |
| R-14-3 | Written change-request process; no change without approval | PROC | 🔲 | W8 |

## EOI notice — immediate submission items

| ID | Item | Deadline | Owner |
|---|---|---|---|
| E-1 | Online submission on CPP portal (e-Tender, single packet; no fee, no EMD) | **05/10/2026 15:00** | bidder |
| E-2 | Response to EOI form on company letterhead (accept terms, certify, complete, eligibility) | 05/10/2026 | W8 draft |
| E-3 | Vendor Capability Form (24 items) | 05/10/2026 | W8 draft (company fields blank) |
| E-4 | EOI checklist (5 items, Yes/No) | 05/10/2026 | W8 draft |
| E-5 | Technical data sheet / brochure against the scope of work | 05/10/2026 | W8 draft |
| E-6 | User list and performance reports / testimonials | 05/10/2026 | bidder |
| E-7 | Local office and service-support details | 05/10/2026 | bidder |
| E-8 | Comments / queries / suggestions on the specification (Serial No. 1) — by email to capitalequip-purchase.tmh@tmc.gov.in | **06/10/2026** | W8 draft |
| E-9 | EOI meeting, Digital Library, TMH Parel | **09/10/2026 14:00** | bidder |

---

## Findings to raise with TMC (input to E-8)

1. **Duration conflict** — §7 says 150 days; milestone table ends at T+210 (M5 T+180, M6 T+210).
2. **Unit count conflict** — §8.1 says "TMC website and **four** unit websites"; everywhere else five.
3. **Financial example error** — §11.2 Bidder A: 120 + 20 shown as 270 (should be 140; F-scores then change).
4. **Bid packets** — notice says *E-Tender Single Packet*; SOW §12 requires separate Technical and Financial bids.
5. **Title vs scope** — notice/checklist say "Website **Design** & Development"; SOW §1/§3 exclude design.
6. **Annexures A–D** — listed as "provided with this EOI document" but not attached; needed for estimation. Annexure D may come at the EOI meeting.
7. **Undefined thresholds** — performance thresholds and peak traffic "agreed with TMC" (§4.7, §4.10); request target metrics (e.g. Lighthouse ≥ 90, LCP ≤ 2.5 s) and concurrency.
8. **Availability ownership** — 99.5% SLA on TMC-owned infrastructure; clarify responsibility for infra outages.
9. **Scope of app front ends** — "wherever applicable" (§4.4); request list of TMC applications/screens and API specs.
10. **Migration volume** — number of pages/documents and source systems; URL inventory for redirects.
11. **Certification timing** — STQC lead time vs M6 (T+210); exclude delays caused by certifying bodies.
12. **"Business hours"** definition for SLA; DR site location; third/fourth languages; analytics tool (self-hosted, India-resident?).
13. **Eligibility wording** — notice asks OEM/distributor and "supply, installation and commissioning"; SOW asks a registered software firm — confirm the SOW criteria govern.
14. **EOI objective paragraph** mentions "diagnostic and treatment turnover time" — appears carried over from a medical-equipment EOI.
15. **SOW table of contents** labels Financial Evaluation "30 Marks" — it is a 30% weight.
