# 07 Documentation, Training, Warranty, AMC and Support Plan

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-PRP-08 | 0.1 | R-4.15-*, R-4.16-*, R-6-*, R-8.2-* (evaluation parameter 9: 7 marks) |

## 1. Documentation (SOW §4.15, §8.2)

All documents are written in Markdown in the project repository (versioned with the code, reviewed in
the same pull requests, updated at each release) and are built into **DOCX (editable)** and **PDF
(portable)** by `scripts/docs/build-docs.sh` for handover — "editable and portable formats" (SOW §4.15).

| SOW deliverable | Document |
|---|---|
| System architecture document | [System Architecture](../architecture/system-architecture.md) (TMC-WEB-ARC-01), [Data Model](../architecture/data-model.md) |
| Security architecture document (M2) | [Security Architecture](../architecture/security-architecture.md), [Access Control Policy](../architecture/access-control-policy.md), [Audit Log Retention Policy](../architecture/audit-log-retention-policy.md), [Data Residency Statement](../architecture/data-residency-statement.md) |
| Installation and deployment guides | [Installation and Deployment Guide](../operations/installation-deployment.md) |
| Configuration documentation | [Configuration Reference](../operations/configuration-reference.md) |
| Integration and interface documentation | [Integration and Interfaces](../architecture/integration-interfaces.md) |
| Backup and restoration procedures | [Backup and Restoration Procedures](../operations/backup-restore.md) |
| CMS administrator manuals | [CMS Administrator Manual](../manuals/cms-administrator-manual.md) |
| System and security administration manual (SOW §8.2) | [System and Security Administration Manual](../manuals/system-security-administration-manual.md) |
| Content editor user manuals | [Content Editor Manual](../manuals/content-editor-manual.md), [Quick Reference Cards](../manuals/quick-reference/README.md) |
| Test and certification reports | Generated per release and Go-Live ([Test Plan §11](../testing/test-plan.md#11-test-deliverables-and-reports-sow-415-81)) |
| Third-party components and licences | [THIRD-PARTY-LICENSES](../THIRD-PARTY-LICENSES.md) (generated) |
| Handover | [Handover Checklist](../operations/handover-checklist.md) |

## 2. Training (SOW §4.16)

The [Role-Based Training Plan](../training/training-plan.md) defines four tracks, each with agenda,
duration, hands-on exercises on UAT and a practical assessment:

| Track | Audience | Duration | Mode |
|---|---|---|---|
| T1 Operations and security | TMC IT infrastructure and security staff | 3 days | At TMH Mumbai |
| T2 CMS administration | TMC IT CMS officers and unit web coordinators | 2 days | At TMH Mumbai or online |
| T3 TMC-level editors | Editors and reviewers of TMC-wide content | 1.5 days | In person or online |
| T4 Unit-level editors | Editors and reviewers of each unit | 1 day per batch | Online, with an in-person option |

Every SOW topic is covered (CMS administration, users and roles, template and content-type configuration,
content creation, media, publishing workflow, deployment, backup, troubleshooting). Materials — manuals,
quick reference cards, exercises, slides and recordings — are delivered in editable and portable formats
and become TMC's property. Training is completed before each group's Go-Live and in full by M6.

## 3. Warranty and AMC (SOW §6.1, §6.2)

| Period | Coverage |
|---|---|
| Warranty: 12 months from Project Closure | Defect rectification, security fixes, performance stabilisation and support at no additional cost |
| AMC: 4 years after warranty | Preventive maintenance (monthly maintenance releases, weekly vulnerability review, quarterly DR drills and reviews), CMS and content support, security hardening, performance tuning, minor enhancements within the agreed scope, upgrades of core/plugins/frameworks/database, annual VAPT, Safe-to-Host renewal, STQC re-certification as due, annual security review report |

Details, the preventive maintenance calendar and the proposed definition of a minor enhancement are in
the [Warranty and AMC Plan](../operations/warranty-amc-plan.md). A complete handover is repeated at the
end of the AMC (SOW §8.2).

## 4. Support model and SLA management (SOW §6.3, §6.4)

| Element | Approach |
|---|---|
| Points of contact | Named Support Lead (single point of contact), Project Lead, Infrastructure/Security Specialist, senior management escalation [BIDDER TO FILL: names and numbers] |
| Logged channel | Issue tracker with a structured support-incident form; e-mail and telephone for P1, always followed by a ticket |
| Priorities and targets | Critical 4 business hours, High 1 business day, Medium 3 business days (SOW §6.4); proposed first-response times; security incidents 24×7 |
| Escalation matrix | Three vendor levels with time triggers per priority; TMC IT and the PSC informed of any SLA breach |
| Availability 99.5 % | Per-minute external monitoring of every website from within India; planned maintenance notified 3 business days in advance; infrastructure-caused outages recorded separately |
| Patching | Critical within 72 hours, High within 7 days, others within 30 days |
| Reporting | Monthly support report (incidents, resolution times, SLA compliance, availability, patches); quarterly security and performance review; annual security review |

Full model: [Incident and Support Model](../operations/incident-support-model.md); templates:
[monthly](../operations/templates/monthly-support-report.md),
[quarterly](../operations/templates/quarterly-security-performance-review.md),
[annual](../operations/templates/annual-security-review.md).
