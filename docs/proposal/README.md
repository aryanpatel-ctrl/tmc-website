# Technical Proposal

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-PRP-00 | 0.1 | Draft technical bid sections; company particulars to be completed by the bidder | R-12.1-\*, R-11-\*, R-9-\*, R-10-\* |

**Tender:** Development, CMS Implementation, Deployment, Content Migration, Security Certification,
Training, Warranty and Maintenance of the TMC Website Ecosystem (TMC Website and Five Constituent Unit
Websites). EOI Reference No. TMH/TMH/2026-27/CAP/EO/0009 dated 28/09/2026. Method of selection: QCBS
(70 % technical, 30 % financial).

**Bidder:** [BIDDER TO FILL: legal name] · **Authorised signatory:** [BIDDER TO FILL]

> **Instructions to the bid team.** Every statement about the bidder (experience, clients, projects,
> certificates, turnover, personnel) is a placeholder marked **[BIDDER TO FILL]** and must be completed
> only with facts that can be evidenced. Nothing in these sections may be presented as the bidder's
> experience unless documentary evidence is attached. **No cost element may appear anywhere in the
> Technical Bid** (SOW §12.2: "such disclosure shall render the bid liable to rejection").

## 1. Contents mapped to SOW §12.1

| SOW §12.1 Technical Bid item | Section |
|---|---|
| Company profile and certificate of incorporation | [00 Company profile and eligibility](00-company-and-eligibility.md) §1 |
| Documentary evidence for every eligibility criterion in Section 9, including audited financial statements and the declaration regarding blacklisting | [00](00-company-and-eligibility.md) §2 |
| ISO 27001 certificate (mandatory); CMMI certificate, if applicable | [00](00-company-and-eligibility.md) §2 |
| Details of qualifying projects: client name, project value, live URL, duration, client contact | [00](00-company-and-eligibility.md) §3 |
| Curricula vitae of all proposed team members, with roles clearly stated | [Team structure and RACI](../governance/raci-team-structure.md) §2 + CV annexes |
| Proposed technical solution and architecture, including hosting, environments, and business continuity approach | [01 Solution and architecture](01-solution-architecture.md) |
| Proposed CMS platform with justification | [02 CMS justification](02-cms-justification.md) |
| Detailed execution plan covering all milestones in Section 7 | [03 Execution plan](03-execution-plan.md) |
| Content migration methodology | [04 Content migration methodology](04-content-migration-methodology.md) |
| Test strategy and quality assurance plan | [05 Test strategy and QA plan](05-test-strategy.md) |
| Security and compliance approach, covering segregation from clinical and patient-service systems, the VAPT and STQC plan, and the certification schedule | [06 Security and compliance approach](06-security-compliance-approach.md) |
| Documentation, training, warranty, AMC, and support plan | [07 Documentation, training, warranty, AMC and support](07-documentation-training-warranty-amc-support.md) |
| Signed acceptance of all terms and conditions, and a statement of deviations, if any | [08 Acceptance and statement of deviations](08-statement-of-deviations.md) |

## 2. Cross-reference to the technical evaluation (SOW §11.1)

| # | Parameter | Marks | Where addressed |
|---|---|---|---|
| 1 | Understanding of scope and solution approach (architecture, hosting and deployment, scalability, business continuity) | 15 | [01](01-solution-architecture.md), [System Architecture](../architecture/system-architecture.md), [Backup and Restoration](../operations/backup-restore.md) |
| 2 | Proposed CMS platform (functionality, maintainability, scalability, security, support, freedom from lock-in) | 15 | [02](02-cms-justification.md), [Third-party licences](../THIRD-PARTY-LICENSES.md) |
| 3 | Security and compliance approach (secure architecture, segregation, VAPT, STQC, CERT-In, access control, audit logging), supported by relevant experience | 15 | [06](06-security-compliance-approach.md), [Security Architecture](../architecture/security-architecture.md); experience: [00](00-company-and-eligibility.md) §3 [BIDDER TO FILL] |
| 4 | Execution plan, project management and risk mitigation | 10 | [03](03-execution-plan.md), [Risk Register](../governance/risk-register.md), [Change Requests](../governance/change-request-procedure.md) |
| 5 | Project team (all mandatory roles named, CVs) | 10 | [Team structure](../governance/raci-team-structure.md) [BIDDER TO FILL] |
| 6 | Multi-site and large-scale portal experience | 10 | [00](00-company-and-eligibility.md) §3 [BIDDER TO FILL] |
| 7 | Past performance and on-time delivery | 10 | [00](00-company-and-eligibility.md) §3–4 [BIDDER TO FILL] |
| 8 | Content migration, testing and quality assurance | 8 | [04](04-content-migration-methodology.md), [05](05-test-strategy.md), [Test Plan](../testing/test-plan.md) |
| 9 | Documentation, training, warranty, AMC and support | 7 | [07](07-documentation-training-warranty-amc-support.md) |

## 3. Presentation and demonstration (SOW §11)

TMC may call for a technical presentation and demonstration confined to the contents of the Technical Bid.
The solution described here exists as a working implementation in this repository and can be
demonstrated on the UAT environment: six websites in English and Hindi, the four editorial roles, the
review workflow, content types with automatic expiry, the tamper-evident audit log with integrity
verification, and the CI/CD pipeline that builds and tests all six sites from scratch on every change.
Content on the demonstration environment is sample data, labelled as such.
