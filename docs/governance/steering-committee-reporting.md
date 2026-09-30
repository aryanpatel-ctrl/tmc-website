# Project Steering Committee and Reporting Cadence

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-GOV-06 | 0.1 | Draft for TMC IT approval | R-14-1, R-14-2, R-7-1, R-5-2 |

## 1. Contractual basis (SOW §14)

- "TMC shall constitute a Project Steering Committee to monitor progress, review deliverables, and approve
  milestone sign-offs."
- "Fortnightly project review meetings shall be held, and a written monthly progress report shall be
  submitted to TMC IT."
- "All milestone deliverables shall be formally accepted in writing by TMC IT before the corresponding
  payment is processed."
- "The Vendor shall maintain a risk register and escalation matrix, updated monthly and shared with TMC IT."

## 2. Governance bodies

| Body | Members | Chair | Purpose | Frequency |
|---|---|---|---|---|
| Project Steering Committee (PSC) | Constituted by TMC [TMC TO FILL]; the Vendor's Project Lead and a senior Vendor representative attend by invitation | [TMC TO FILL] | Monitor progress; review deliverables; approve milestone sign-offs; decide escalated issues and changes with schedule or cost impact; project closure | At each milestone (M1–M6) and monthly, or as convened by TMC |
| Fortnightly project review | TMC IT nodal officer, unit coordinators as needed; Vendor Project Lead and role leads | TMC IT nodal officer | Progress against the plan, RTM status, risks, issues, dependencies, change requests, upcoming activities | Every two weeks |
| Working sessions | Topic owners (design, content, integration, security, testing) | As agreed | Detailed work; decisions recorded and reported at the next review | As needed |

## 3. Reporting calendar

| Output | Frequency / due | Owner | Recipient | Template |
|---|---|---|---|---|
| Fortnightly review agenda and minutes | Agenda 2 business days before; minutes within 2 business days after | Vendor Project Lead | TMC IT | [Fortnightly review minutes](templates/fortnightly-review-minutes.md) |
| Monthly progress report | By the 5th business day of each month | Vendor Project Lead | TMC IT (copy PSC) | [Monthly progress report](templates/monthly-progress-report.md) |
| Risk register and escalation matrix | Monthly, with the progress report | Vendor Project Lead | TMC IT | [Risk Register](risk-register.md), [escalation matrix](raci-team-structure.md#4-escalation-matrix-project-phase) |
| RTM status | Monthly and at each milestone | Vendor Project Lead | TMC IT | [RTM](../requirements/RTM.md) |
| Milestone submission and acceptance | At each milestone | Vendor Project Lead → TMC IT | PSC | [Milestone acceptance certificate](templates/milestone-acceptance-certificate.md) |
| Change request register | Monthly and on each decision | Vendor Project Lead | TMC IT | [Change Request Procedure §4](change-request-procedure.md#4-change-request-register) |
| Design deviation register | Monthly and at each Go-Live | Frontend lead | TMC IT | [Design Deviation Register](design-deviation-register.md) |
| Observation register (VAPT, STQC, TMC, independent review) | Monthly and on each report | Infrastructure/Security Specialist | TMC IT | [Observation Remediation §6](../operations/security-observation-remediation.md#6-observation-register-template) |
| Monthly support report (warranty/AMC) | By the 7th of each month | Support Lead | TMC IT | [Monthly Support Report](../operations/templates/monthly-support-report.md) |

## 4. Milestone acceptance

1. The Vendor submits the milestone deliverables with a covering letter listing each SOW deliverable, its
   evidence and location (see the [Execution Plan §3](execution-plan.md#3-milestone-plan-mapped-to-deliverables)).
2. TMC IT reviews within the period it sets [TMC TO CONFIRM, proposed 10 business days]; observations are
   recorded and closed by the Vendor.
3. TMC IT issues written acceptance (certificate template); the PSC approves the milestone sign-off.
4. Payment is processed only after written acceptance (SOW §7, §14).

## 5. Independent review and validation (SOW §5)

TMC may have the design and the ecosystem independently reviewed by TMC or a TMC-appointed agency at any
stage and during warranty and AMC. The Vendor provides access to UAT, the repository (read-only), the
documentation and test reports as requested, and addresses observations within the timelines advised by
TMC, using the [Observation Remediation Procedure](../operations/security-observation-remediation.md).

## 6. Records

Minutes, reports, registers and acceptance certificates are stored in a TMC-controlled location
[TMC TO CONFIRM: e.g. TMC document management system or the project repository's `docs/records/`
restricted area] and handed over at Project Closure.
