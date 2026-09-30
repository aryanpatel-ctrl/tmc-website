# 03 Execution Plan, Project Management and Risk Mitigation

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-PRP-04 | 0.2 | R-7-1 to R-7-3, R-14-\*, R-10-\* (evaluation parameter 4: 10 marks) |

## 1. Plan

The detailed plan is the [Execution Plan (M1–M6)](../governance/execution-plan.md). It maps each SOW §7
milestone deliverable to the artefact that evidences it, shows the schedule, the dependencies on TMC and
the resource loading. In summary:

| Milestone | T + days | What TMC receives |
|---|---|---|
| M1 Mobilisation | 0 | Project management plan (execution plan, team, RACI, risk register, change control, reporting), test plan approach, named team deployed, repository in a TMC-owned organisation |
| M2 Environments, architecture, security sign-off | 30 | Design inputs confirmed; CMS approved; Dev, UAT, Production and DR provisioned from the same scripts; security architecture document with segregation tests; data residency statement |
| M3 Core platform and templates | 60 | Component library; all Annexure B templates editable; content types; roles and workflow; standard navigation, header and footer; demonstration and sign-off |
| M4 TMC + pilot unit live | 120 | Two websites developed, migrated, tested, VAPT closed, UAT signed off, live with Safe-to-Host |
| M5 Remaining units live | 180 | Four unit websites live; clean broken-link and defect closure reports for all six |
| M6 Certification, training, handover | 210 | STQC, Safe-to-Host, final VAPT report; complete handover per SOW §8.2 with independent build/deploy/operate demonstration; documentation; training; PSC sign-off |

The duration discrepancy in SOW §7 (150 days versus T + 210 days) has been raised as EOI query Q-01; the
plan follows the milestone table.

## 2. Why the plan is achievable

1. **The platform exists.** The CMS network, roles, workflow, audit log, content types, expiry, search
   and document library, security hardening (MFA, CSP, admin allow-list), SEO, redirects and analytics,
   the application gateway (tested against a demonstration back end), the editorial platform, caching,
   backup and DR tooling, monitoring, and CI/CD with automated quality gates are already implemented and
   tested; project time goes into Annexure A–C conformance, connection to TMC's real application
   endpoints, migration, testing and certification.
2. **Build once, reuse five times.** All six websites share one code base and one provisioning path;
   the four remaining units at M5 differ mainly in content (SOW §4.1).
3. **Continuous verification.** Every change builds all six sites from scratch and runs the test suites,
   so milestone demonstrations are routine rather than special events.
4. **Parallel development by feature area** with clear file ownership and non-overlapping migration
   ranges ([Engineering Conventions](../engineering/CONVENTIONS.md)), integrated continuously through CI.
5. **Certification started early.** VAPT is booked at M3; STQC is initiated immediately after M4.

## 3. Project management

| Element | Approach | Document |
|---|---|---|
| Organisation and roles | SOW §10 roles, single point of contact, RACI | [Team structure and RACI](../governance/raci-team-structure.md) |
| Governance and reporting | PSC at milestones and monthly; fortnightly reviews; monthly progress report; written milestone acceptance | [Steering Committee and Reporting](../governance/steering-committee-reporting.md) |
| Requirements traceability | Every SOW clause has an ID, owner, status and verification | [RTM](../requirements/RTM.md) |
| Change control | Written Change Requests; nothing implemented without TMC IT approval | [Change Request Procedure](../governance/change-request-procedure.md) |
| Design conformance | Design tokens locked; deviation register with written approvals only | [Design Deviation Register](../governance/design-deviation-register.md) |
| Quality | CI gates, test plan, UAT, defect closure | [Test Plan](../testing/test-plan.md) |

## 4. Risk mitigation

The initial [Risk Register](../governance/risk-register.md) lists 20 risks with probability, impact,
mitigation, contingency and owner; it is updated monthly and shared with TMC IT. The highest-rated risks
at bid stage and their principal mitigations:

| Risk | Mitigation |
|---|---|
| Late design inputs (Annexures A–C) | Token-driven theme absorbs the design system without template rewrites; gap review within 5 days |
| Late or incomplete content from units | Inventory template, schedule 60 days before each Go-Live, weekly tracking, early queries |
| Certification lead times (STQC) | Early initiation; continuous evidence from CI; request to exclude certifying-body delays (Q-11) |
| Late-stage VAPT findings | Security gate on every release and internal pre-assessment before the agency test |
| Undefined performance thresholds | Proposed thresholds measured from the start; agreement at M2 (Q-07) |
