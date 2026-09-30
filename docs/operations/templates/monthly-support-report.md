# Monthly Support Report (Template)

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-TPL-01 | 0.1 | R-6-5, R-6-6, R-6-7, R-6-3 |

SOW §6.3: "A monthly support report covering incidents, resolution times, and SLA compliance shall be
submitted to TMC IT." Submitted by the 7th of the following month by the Support Lead.

**How to fill.** Replace every `[ ]`. Ticket data comes from the tracker:

```bash
# all support tickets opened or closed in the month (CSV + Markdown summary)
scripts/reports/defect-closure-report.sh --label incident --since 2027-06-01 --until 2027-06-30 --out reports/support-2027-06
```

Availability figures come from the uptime monitoring of `/wp-json/tmc/v1/health` and the monthly
availability report (`scripts/sla/availability-report.sh`; see [monitoring](../monitoring.md)).

---

**To:** Head, IT Department, Tata Memorial Centre
**From:** [BIDDER TO FILL: Vendor], Support Lead [name]
**Contract:** [ ] **Reporting month:** [MM/YYYY] **Period:** Warranty / AMC Year [ ]

## 1. Summary

| Indicator | This month | Previous month | Target |
|---|---|---|---|
| Availability (lowest of the six websites) | [ ] % | [ ] % | ≥ 99.5 % |
| Tickets opened / closed / open at month end | [ ] / [ ] / [ ] | | — |
| Critical (P1) resolved within 4 business hours | [ ] of [ ] | | 100 % |
| High (P2) resolved within 1 business day | [ ] of [ ] | | 100 % |
| Medium (P3) resolved within 3 business days | [ ] of [ ] | | 100 % |
| Security patches applied within timeline | [ ] of [ ] | | 100 % (≤ 30 days; critical on priority) |
| SLA breaches | [ ] | | 0 |

## 2. Availability by website

Measured as defined in the [Incident and Support Model §1.1](../incident-support-model.md#11-interpretation-used-for-measurement-subject-to-tmc-confirmation).

| Website | Minutes in month | Unplanned downtime (min) | Planned maintenance (min, notified) | Availability | Outages caused by TMC-owned infrastructure (recorded separately) |
|---|---|---|---|---|---|
| tmc.gov.in | | | | | |
| tmh.tmc.gov.in | | | | | |
| hbchrcv.tmc.gov.in | | | | | |
| mpmmcc.tmc.gov.in | | | | | |
| hbchrcmzp.tmc.gov.in | | | | | |
| hbchpunjab.tmc.gov.in | | | | | |

## 3. Incidents and service requests

| Ticket | Site | Priority | Summary | Logged (date, time) | Resolved (date, time) | Resolution time (business hours) | Within SLA | Clock-stop (reason) | Root cause / fix (release) |
|---|---|---|---|---|---|---|---|---|---|
| | | | | | | | | | |

### 3.1 SLA breaches and corrective actions

| Ticket | Target | Actual | Reason | Corrective action | Owner | Due |
|---|---|---|---|---|---|---|
| | | | | | | |

### 3.2 Root-cause analyses completed (P1/P2)

| Ticket | RCA summary | Preventive action | Status |
|---|---|---|---|
| | | | |

## 4. Releases and patches

| Release (tag / commit) | Date in Production | Contents (patches, fixes, minor enhancements) | Change / ticket refs | Rollback needed? |
|---|---|---|---|---|
| | | | | |

Patch register extract: see [Patch Management §7](../patch-management.md#7-patch-register).

## 5. Security events

| Item | This month |
|---|---|
| Privileged events reviewed in the audit log (`super_admin_*`, `user_role_changed`, `plugin_*`, `theme_switched`, `site_*`, `network_setting_changed`) | [count], anomalies: [ ] |
| Failed-login bursts (`login_failed`) and actions taken | [ ] |
| Vendor access to Production/DR (tickets, windows) | [ ] |
| Security incidents (and CERT-In reports made by TMC, if any) | [ ] |
| Open VAPT / STQC / TMC observations | [ ] (see register) |

## 6. Backups and DR

| Item | Result |
|---|---|
| Scheduled backups successful | [ ] of [ ] |
| Restore test performed (date, environment, result) | [ ] |
| DR drill this month (if quarterly) | RPO achieved [ ] min / RTO achieved [ ] min |

## 7. Minor enhancements (AMC)

| Request | Person-days used | Cumulative this AMC year | Status |
|---|---|---|---|
| | | [ ] of [allowance] | |

## 8. Planned for next month

- Maintenance window(s): [date, time, impact]
- Planned releases / upgrades: [ ]
- Training / other: [ ]

## 9. Sign-off

| | Vendor Support Lead | Reviewed by TMC IT |
|---|---|---|
| Name | | |
| Date | | |
| Signature | | |
