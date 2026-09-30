# Annual Security Review Report (Template)

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-TPL-03 | 0.1 | R-6-4, R-6-2, R-4.8-7, R-13-2 |

SOW §6.2 (AMC): "An annual security review report covering the CMS, modules, and plugins, submitted to TMC
IT", together with annual VAPT by a CERT-In empanelled agency, renewal of the Safe-to-Host certificate
and STQC re-certification as due. Submitted within 30 days of the end of each AMC year.

---

**To:** Head, IT Department, Tata Memorial Centre
**From:** [BIDDER TO FILL: Vendor] **AMC year:** [1–4] **Period:** [DD/MM/YYYY – DD/MM/YYYY]

## 1. Scope

Six websites, the WordPress Multisite CMS, the `tmc-core` must-use plugin, the `tmc` theme, all
third-party plugins and libraries, the container images and the hosting configuration of Production and
DR.

## 2. Component review (CMS, modules, plugins)

One row per component in [THIRD-PARTY-LICENSES.md](../../THIRD-PARTY-LICENSES.md) and per project module.

| Component | Version at start of year | Version at end of year | Supported by upstream? | Security advisories during the year (IDs) | Time to patch (days) | Still required? | Licence unchanged and compatible? | Action |
|---|---|---|---|---|---|---|---|---|
| WordPress core | | | | | | Yes | | |
| Polylang | | | | | | Yes | | |
| PHP / Apache (image) | | | | | | Yes | | |
| MariaDB | | | | | | Yes | | |
| Redis-compatible cache | | | | | | Yes | | |
| `tmc-core` modules (one row per module) | | | Project | | | | GPL-2.0-or-later | |
| `tmc` theme | | | Project | | | | GPL-2.0-or-later | |
| Components added by work streams (search, security, SEO, gateway, editorial, quality, backup) | | | | | | | | |

## 3. Certification status

| Certification | Agency | Date of test / audit | Report ref. | Observations (C/H/M/L) | All closed on | Certificate valid until |
|---|---|---|---|---|---|---|
| VAPT (CERT-In empanelled) | | | | | | n/a |
| Safe-to-Host | | | | n/a | | |
| STQC | | | | | | |

## 4. Security architecture re-validation

Re-validate the [Security Architecture Document](../../architecture/security-architecture.md) against the
deployed system (its §11 checklist) and record changes made during the year.

| Check | Result | Evidence |
|---|---|---|
| Zones, flows and firewall rules unchanged or documented | | |
| Segregation tests S-1 to S-6 repeated | | |
| MFA and administrative network restriction effective | | |
| Secrets rotated as scheduled (list, dates — no values) | | |
| Audit log: four quarterly anchors recorded; integrity intact | | |
| Log retention (180 days minimum, within India) confirmed | | |
| Data residency statement still accurate | | |

## 5. Incidents and trends

| Metric | Year total | Trend vs previous year |
|---|---|---|
| Security incidents | | |
| P1 / P2 incidents | | |
| Mean time to patch (security) | | |
| Availability (lowest site, yearly) | | |

## 6. Risks and recommendations for the next year

| # | Risk / recommendation | Rating | Proposed action | Change Request needed? |
|---|---|---|---|---|
| 1 | | | | |

## 7. Sign-off

| | Vendor (Infrastructure/Security Specialist) | TMC IT / CISO |
|---|---|---|
| Name | | |
| Date | | |
| Signature | | |
