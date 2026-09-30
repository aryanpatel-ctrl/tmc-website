# Technical Data Sheet: Offered Solution

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-EOI-02 | 0.1 | Draft; bidder particulars marked [BIDDER TO FILL] | E-5, EOI eligibility (iii) |

EOI notice, eligibility criterion (iii): "Please submit the technical data sheet along with the brochure as
a EOI document with reference to the offered Scope of work (technical specification)." This data sheet
describes the offered solution against the scope in the EOI Document (SOW). It contains no price
information.

**Bidder:** [BIDDER TO FILL] · **EOI:** TMH/TMH/2026-27/CAP/EO/0009 · **Date:** [ ]

---

## 1. Solution at a glance

| Item | Offered |
|---|---|
| Solution | Unified website ecosystem for Tata Memorial Centre and five constituent units on one CMS, one code base and one security framework |
| Websites | 6: TMC umbrella + TMH Mumbai, HBCH & RC Visakhapatnam, MPMMCC / HBCH Varanasi, HBCH & RC Muzaffarpur, HBCH New Chandigarh (subdomains of the primary domain) |
| CMS | WordPress Multisite (subdomain network), open source, GPL-2.0-or-later |
| Languages | English and Hindi at Go-Live; up to 3–4 languages without template code changes |
| Standards | GIGW 3.0, WCAG 2.2 Level AA, W3C HTML/CSS, OWASP Top 10 (2021), CERT-In Directions (28 April 2022) |
| Hosting | TMC-owned or TMC-subscribed infrastructure; MeitY-empanelled cloud in TMC's name where cloud is used; all data, logs and backups in India |
| Environments | Development, CI, UAT, Production, Disaster Recovery — all built from the same scripts |
| Business continuity | RPO 15 minutes, RTO 1 hour, demonstrated by quarterly timed DR drills |
| Certification | CERT-In empanelled VAPT before each Go-Live and annually; Safe-to-Host; STQC certification by Project Closure and re-certification as due |
| Licensing | Free and open-source components only; no licence fees, premium plugins or vendor lock-in; full source and rights to TMC |
| Support | 12 months' warranty from Project Closure + 4 years' AMC; SLA per SOW §6.4 |

## 2. Technical specification

| Component | Specification |
|---|---|
| Application | WordPress (current supported release) Multisite; PHP 8.3; Apache httpd with hardened configuration |
| Database | MariaDB 11.4 (long-term-support series), `utf8mb4` |
| Cache | Redis-protocol object cache (BSD-licensed build); page cache |
| Multilingual | Polylang (GPL-3.0-or-later), language directories (`/hi/`), linked translations |
| Custom code | Must-use plugin `tmc-core` (roles, review workflow, audit log, content types, expiry, search, security, SEO, gateway modules) and theme `tmc` (design tokens, templates, components, accessibility features) — all GPL, owned by TMC |
| Packaging | Docker containers (web, database, cache, scheduler) with infrastructure-as-code provisioning; database and cache on an internal network without internet or host ports |
| Delivery | Git repository with full history; CI/CD pipeline: static checks → build of all six sites from scratch → integration and smoke tests → automated UAT deployment → controlled Production promotion; one-step rollback |
| Fonts and assets | Self-hosted Noto Sans (Latin and Devanagari, SIL OFL 1.1); no external CDN or third-party script at runtime |

## 3. Compliance with the scope of work

| SOW clause | Requirement (summary) | Offered |
|---|---|---|
| §4.1 | Six websites on common design system, component library, CMS and security framework | Yes — one Multisite network; units reuse the platform with low incremental effort |
| §4.3 | Component library, page templates and content types from Annexures A/B; editors create pages by selecting a template and filling fields; navigation, header, footer, breadcrumbs, sitemap, search, event calendar, maps, social links | Yes — token-driven theme, locked template sections, structured fields; all site-wide elements |
| §4.4 | Front-end layer for TMC applications through TMC-approved endpoints; no access to clinical data | Yes — server-side allow-listed application gateway; no persistence of submitted data |
| §4.5 | Widely adopted open-source CMS; full ownership, no licensing dependency | Yes — WordPress Multisite; GPL only |
| §4.6 | Code-free editing; RBAC with central and unit publishing; review workflow; version history and audit trail; scheduling and automatic expiry; media/document libraries; multilingual; secured admin | Yes — four roles; review queue with return notes; revisions; HMAC-chained tamper-evident audit log with integrity verification and export; scheduling and date-driven expiry of notices, tenders, EOIs, job openings and events; document library; network publishing of TMC-wide notices; MFA |
| §4.7 | Hosting in India on TMC infrastructure; Dev/UAT/Prod/DR; auditable promotion and rollback; logged vendor access; RPO 15 min / RTO 1 h; backups with tested restore; peak load and scaling | Yes — see §1 and §2 |
| §4.8 | Segregation from clinical systems; separate accounts; MFA, restricted network, RBAC; tamper-evident audit logs; vulnerability assessment of every release; GIGW/WCAG/W3C/OWASP; VAPT, STQC, Safe-to-Host; security architecture at M2; closure of observations at no cost | Yes — zone design with segregation tests; CI security gate; certification plan |
| §4.9 | WCAG 2.2 AA and GIGW 3.0; responsive; all major browsers | Yes — accessibility bar (text size, contrast), skip link, keyboard navigation, focus visibility, pause controls; automated accessibility and cross-browser tests |
| §4.10 | Clean URLs, editable metadata, structured data, sitemap and crawl directives, redirects; performance thresholds per template; analytics and search console | Yes |
| §4.11 | Migration, template mapping, completeness, redirects, zero broken links and orphans, post-migration confirmation | Yes — inventory-driven scripted import with automated verification |
| §4.12 | Secure standards-based integrations with authentication, rate control, logging; payment gateway front end; social, calendars, maps; full-text search with auto-suggestions across content and documents | Yes |
| §4.13 | English and Hindi; more languages without template changes | Yes |
| §4.14 | Test plan; functional, integration, cross-browser, performance/load, security testing; UAT support and defect closure report | Yes — automated test suites in CI; defect closure report generated from the tracker |
| §4.15 | Complete documentation, updated at each release, editable and portable formats | Yes — Markdown sources with DOCX and PDF outputs |
| §4.16 | Role-based training with material, manuals and quick reference guides | Yes — four training tracks with exercises and assessment |
| §5 | Strict design conformance; independent review | Yes — design tokens locked; deviation register |
| §6 | 12-month warranty, 4-year AMC, support channel, escalation, monthly report, SLA | Yes |
| §8.2 | Complete handover incl. repository, scripts, configuration, schema, manuals, licence list; demonstration of independent operation | Yes |
| §13 | TMC property; legally licensed OSS; no lock-in; no vendor branding or advertisements | Yes |

## 4. Bidder particulars

| Item | Details |
|---|---|
| Company | [BIDDER TO FILL] |
| ISO 27001 certificate | [BIDDER TO FILL: number, scope, validity] |
| CMMI (if any) | [BIDDER TO FILL] |
| Local office and service support | [BIDDER TO FILL: address, contact, support hours] |
| Relevant projects (user list) | [BIDDER TO FILL: see Vendor Capability Form item 22 and performance reports] |
| Contact for this EOI | [BIDDER TO FILL: name, designation, phone, e-mail] |

*Signature and seal of the authorised signatory:* [ ]
