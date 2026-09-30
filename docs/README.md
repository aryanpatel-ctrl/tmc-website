# Documentation Register

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-DOC-00 | 0.1 | Draft | R-4.15-1 to R-4.15-9, R-8.2-6, R-2-8 |

This register lists every document of the TMC Website Ecosystem (EOI No. TMH/TMH/2026-27/CAP/EO/0009),
its document ID, and the requirements of the [Requirements Traceability Matrix](requirements/RTM.md) that
it evidences. The Markdown files in this folder are the **master copies**; the editable (DOCX) and
portable (PDF) sets handed over to TMC are generated from them (section 4).

## 1. Conventions used in the documents

| Marker | Meaning |
|---|---|
| **Document ID** `TMC-WEB-<area>-<nn>` | Stable identifier quoted in tickets, reports and correspondence. Areas: ARC architecture, OPS operations, MAN manuals, QRC quick reference, TRN training, TST testing, GOV governance, TPL templates, PRP proposal, EOI EOI response, LIC licences, DOC this register |
| **Version / Status** | 0.x = draft; 1.0 = approved by TMC IT for the milestone at which it is due. Each release updates the version of every document it changes (SOW §4.15) |
| **[BIDDER TO FILL]** | Company particulars (names, experience, certificates, contacts) to be completed by the bidder with facts that can be evidenced. No such fact is asserted anywhere in these documents |
| **[TMC TO FILL] / [TMC TO CONFIRM]** | Information or a decision that only TMC can provide (contacts, business hours, DR location and similar); related EOI queries are numbered Q-nn in the [queries letter](eoi/queries-letter.md) |
| **Verify at integration** | Behaviour delivered by a parallel work stream (W1–W7) whose exact routes, settings or variable names are confirmed when that work is merged; the statement of *what* is delivered follows the RTM |
| **Sample / demonstration data** | Content seeded on development and UAT environments is labelled as sample data in the content itself and flagged `_tmc_sample = 1` for removal before Go-Live. It is not information about TMC |

## 2. Register

### 2.1 Architecture (M2 deliverables)

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-ARC-01 | [System Architecture](architecture/system-architecture.md) | R-4.15-1, R-4.7-1 to R-4.7-9, R-2-10 |
| TMC-WEB-ARC-02 | [Security Architecture](architecture/security-architecture.md) | R-4.8-1 to R-4.8-9, R-2-6, R-7-2 |
| TMC-WEB-ARC-03 | [Integration and Interface Document](architecture/integration-interfaces.md) | R-4.15-4, R-4.4-*, R-4.12-* |
| TMC-WEB-ARC-04 | [Data Model and Database Schema Reference](architecture/data-model.md) | R-8.2-5 |
| TMC-WEB-ARC-05 | [Data Residency Compliance Statement (template)](architecture/data-residency-statement.md) | R-4.7-1, R-4.7-2, R-7-2 |
| TMC-WEB-ARC-06 | [Access Control and Vendor Access Policy](architecture/access-control-policy.md) | R-4.7-5, R-4.8-2, R-4.8-3 |
| TMC-WEB-ARC-07 | [Audit Log Retention Policy](architecture/audit-log-retention-policy.md) | R-4.8-4 |

### 2.2 Operations

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-OPS-01 | [Installation and Deployment Guide](operations/installation-deployment.md) | R-4.15-2, R-4.7-4, R-8.2-3, R-8.2-8 |
| TMC-WEB-OPS-02 | [Configuration Reference](operations/configuration-reference.md) | R-4.15-3, R-8.2-4 |
| TMC-WEB-OPS-03 | [Backup and Restoration Procedures](operations/backup-restore.md) | R-4.15-5, R-4.7-6, R-4.7-8 |
| TMC-WEB-OPS-04 | [Incident and Support Model](operations/incident-support-model.md) (SLA per SOW §6.4, escalation matrix) | R-6-5 to R-6-7, R-4.14-6 |
| TMC-WEB-OPS-05 | [Patch Management Procedure](operations/patch-management.md) | R-6-3, R-4.8-5 |
| TMC-WEB-OPS-06 | [Observation Remediation Procedure](operations/security-observation-remediation.md) | R-4.8-9, R-5-2 |
| TMC-WEB-OPS-07 | [Warranty and AMC Plan](operations/warranty-amc-plan.md) | R-6-1, R-6-2 |
| TMC-WEB-OPS-08 | [Project Handover Checklist](operations/handover-checklist.md) (SOW §8.2) | R-8.2-1 to R-8.2-8, R-2-9 |
| TMC-WEB-OPS-09 | [Content Migration Coordination](operations/content-migration-coordination.md) and [content inventory template (CSV)](operations/templates/content-inventory-template.csv) | R-4.11-5 |
| TMC-WEB-TPL-01 | [Monthly Support Report (template)](operations/templates/monthly-support-report.md) | R-6-5 |
| TMC-WEB-TPL-02 | [Quarterly Security and Performance Review (template)](operations/templates/quarterly-security-performance-review.md) | R-6-8 |
| TMC-WEB-TPL-03 | [Annual Security Review Report (template)](operations/templates/annual-security-review.md) | R-6-4 |

### 2.3 Manuals and quick reference cards

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-MAN-01 | [CMS Administrator Manual](manuals/cms-administrator-manual.md) (network, sites, users and roles, languages, templates, content types, audit log) | R-4.15-6, R-4.13-2, R-2-10 |
| TMC-WEB-MAN-02 | [System and Security Administration Manual](manuals/system-security-administration-manual.md) | R-8.2-6, R-4.15-6 |
| TMC-WEB-MAN-03 | [Content Editor Manual](manuals/content-editor-manual.md) (pages, templates, tenders, events, careers, doctors, media, review, scheduling, expiry) | R-4.15-7 |
| TMC-WEB-QRC-00 | [Quick Reference Cards](manuals/quick-reference/README.md): [Super Admin](manuals/quick-reference/super-admin.md), [Site Administrator](manuals/quick-reference/site-administrator.md), [Reviewer / Publisher](manuals/quick-reference/reviewer-publisher.md), [Content Editor](manuals/quick-reference/content-editor.md), [Operations](manuals/quick-reference/operations.md) | R-4.16-3 |

### 2.4 Training

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-TRN-01 | [Role-Based Training Plan](training/training-plan.md) (TMC IT, CMS administrators, TMC-level and unit-level editors) | R-4.16-1, R-4.16-2 |
| TMC-WEB-TRN-02 | [Training Exercises and Assessment](training/exercises-and-assessment.md) | R-4.16-1 to R-4.16-3 |
| TMC-WEB-TRN-03 | [Training Records (templates)](training/training-records.md) | R-4.16-1 |

### 2.5 Testing

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-TST-01 | [Test Plan](testing/test-plan.md) (for TMC approval) | R-4.14-1 to R-4.14-6, R-7.1-1 |
| — | Issue forms: [defect](../.github/ISSUE_TEMPLATE/defect.yml), [change request](../.github/ISSUE_TEMPLATE/change-request.yml), [support incident](../.github/ISSUE_TEMPLATE/support-incident.yml) | R-4.14-6, R-14-3, R-6-5 |
| — | [Defect closure report](../scripts/reports/defect-closure-report.sh) (from the issue tracker, `--gate` for Go-Live) and [tracker label set-up](../scripts/reports/setup-tracker-labels.sh) | R-4.14-6 |

### 2.6 Governance

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-GOV-01 | [Execution Plan M1–M6](governance/execution-plan.md) | R-7-1, R-7-2 |
| TMC-WEB-GOV-02 | [Team Structure and RACI](governance/raci-team-structure.md) | R-10-*, R-14-1 |
| TMC-WEB-GOV-03 | [Risk Register](governance/risk-register.md) | R-14-2 |
| TMC-WEB-GOV-04 | [Change Request Procedure](governance/change-request-procedure.md) | R-14-3 |
| TMC-WEB-GOV-05 | [Design Deviation Register](governance/design-deviation-register.md) | R-5-1 |
| TMC-WEB-GOV-06 | [Steering Committee and Reporting Cadence](governance/steering-committee-reporting.md) | R-14-1, R-5-2 |
| TMC-WEB-TPL-04 | [Monthly Progress Report (template)](governance/templates/monthly-progress-report.md) | R-14-1 |
| TMC-WEB-TPL-05 | [Fortnightly Review Minutes (template)](governance/templates/fortnightly-review-minutes.md) | R-14-1 |
| TMC-WEB-TPL-06 | [Milestone Acceptance Certificate (template)](governance/templates/milestone-acceptance-certificate.md) | R-7-1, R-14-1 |

### 2.7 Technical proposal (SOW §12.1) and EOI response

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-PRP-00 to 09 | [Technical Proposal](proposal/README.md): company and eligibility (placeholders), solution and architecture, CMS justification, execution plan, content migration methodology, test strategy, security and compliance approach, documentation/training/warranty/AMC/support plan, statement of deviations | R-12.1-*, R-11-* |
| TMC-WEB-EOI-00 to 06 | [EOI Response Pack](eoi/README.md): queries and suggestions letter, technical data sheet, Response Form, Vendor Capability Form, EOI checklist, submission checklist | E-2 to E-8 |

### 2.8 Licences and engineering

| ID | Document | RTM |
|---|---|---|
| TMC-WEB-LIC-01 | [Third-Party Components and Licences](THIRD-PARTY-LICENSES.md) (generated by [`scripts/licenses.sh`](../scripts/licenses.sh)) | R-8.2-7, R-13-2 |
| — | [Requirements Traceability Matrix](requirements/RTM.md) | all |
| — | [Engineering Conventions](engineering/CONVENTIONS.md) | R-8.2-1 |

## 3. Keeping the documentation current (SOW §4.15)

Documentation is part of every release, not a separate activity:

1. A change that alters behaviour, configuration, a procedure or a component updates the affected
   document in the **same pull request**, and raises its version.
2. `scripts/licenses.sh` is re-run whenever an image, plugin, font, CI action or tool changes; CI fails
   when the inventory is out of date (`scripts/licenses.sh --check`).
3. CI checks every relative link and heading anchor in `README.md` and `docs/`
   (`python3 scripts/docs/check-links.py`, no network needed). Both checks run in the reusable workflow
   `.github/workflows/docs.yml`.
4. At each release handed to TMC, the documentation set is regenerated (section 4) and delivered with the
   release tag and its checksums.

## 4. Editable and portable formats (R-4.15-9)

```bash
./scripts/docs/build-docs.sh                   # every document, DOCX (editable) + PDF (portable)
./scripts/docs/build-docs.sh --format docx     # DOCX only
./scripts/docs/build-docs.sh docs/manuals      # only the documents under a folder
```

The build runs in pinned Pandoc and Mermaid CLI containers (only Docker is needed) and writes
`dist/docs/` (not version-controlled) with the same folder structure, diagrams rendered as images, links
between documents pointing at the generated files, and a `SHA256SUMS` file for the handover record.
