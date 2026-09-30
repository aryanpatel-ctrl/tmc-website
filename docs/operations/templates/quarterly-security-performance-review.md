# Quarterly Security and Performance Review (Template)

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-TPL-02 | 0.2 | R-6-8, R-6-4, R-4.7-6, R-4.8-4, R-4.10-6 |

SOW §6.4: "Security and performance review — Quarterly, with a written report to TMC IT." Submitted within
15 days of the end of each quarter during warranty and AMC. Inputs are the CI and scan reports of the
quarter (security gate: `security-secrets-report`, `security-image-reports`, `security-dast-reports`;
quality gates: `quality-reports` with the Go-Live acceptance report; `dr-drill-report`; `capacity-report`), the monthly availability reports of
`scripts/sla/availability-report.sh`, the audit log, the DR drill record and the tracker.

---

**To:** Head, IT Department, Tata Memorial Centre
**From:** [BIDDER TO FILL: Vendor] — Infrastructure/Security Specialist [name], Support Lead [name]
**Quarter:** Q[ ] [YYYY] **Releases in Production this quarter:** [tags]

## 1. Executive summary

| Area | Status (Green / Amber / Red) | Key points |
|---|---|---|
| Security posture | | |
| Vulnerabilities and patching | | |
| Access control | | |
| Audit log integrity | | |
| Backups and DR (RPO 15 min / RTO 1 h) | | |
| Performance against agreed thresholds | | |
| Availability (99.5 %) | | |
| Accessibility (WCAG 2.2 AA / GIGW 3.0) | | |

## 2. Vulnerability management

| Item | Value |
|---|---|
| Container image scan: Critical / High / Medium open at quarter end | [ ] / [ ] / [ ] |
| Dependency and secret scan findings | [ ] |
| DAST baseline (latest release): alerts by risk | [ ] |
| Patches applied (from the patch register), all within timeline? | [ ] |
| Components more than one minor version behind current (list) | [ ] |
| Open VAPT / STQC / TMC / independent-review observations ([register](../security-observation-remediation.md#6-observation-register-template)) | [ ] |

## 3. Access control review

| Check | Result |
|---|---|
| Super Admin list reviewed and confirmed by TMC IT (names) | [ ] |
| Each site's user list confirmed by its Site Administrator (6 sites) | [ ] of 6 |
| Accounts without MFA among privileged roles (must be 0; enforced by `TMC_ENFORCE_MFA=1`) | [ ] |
| Accounts inactive for more than 90 days (disabled?) | [ ] |
| Vendor access windows to Production/DR this quarter (tickets) | [ ] |
| Demonstration or default accounts present in Production (must be none) | [ ] |

## 4. Audit log integrity (anchoring)

Performed in *Network Admin → Audit Log → Verify integrity* (see
[Audit Log Retention Policy §4–5](../../architecture/audit-log-retention-policy.md#5-review-by-tmc)).

| Date and time verified | Result | Entries | Latest hash (full 64 characters) | CSV export archived (file name, SHA-256) | Verified by |
|---|---|---|---|---|---|
| | Intact / broken at # | | | | |

Privileged events of the quarter (counts): `super_admin_granted` [ ], `super_admin_revoked` [ ],
`user_role_changed` [ ], `plugin_activated`/`plugin_deactivated` [ ], `theme_switched` [ ],
`site_created`/`site_deleted` [ ], `network_setting_changed` [ ], `login_failed` [ ].

## 5. Backups and disaster recovery

| Item | Result |
|---|---|
| Backup success rate (scheduled sets) | [ ] % |
| DR drill date and scenario | [ ] |
| RPO achieved (target ≤ 15 min) | [ ] min |
| RTO achieved (target ≤ 60 min) | [ ] min |
| Drill record | [Backup and Restoration §6](../backup-restore.md#6-disaster-recovery-drill-record) attached |
| Issues and corrective actions | [ ] |

## 6. Performance

Thresholds are those agreed with TMC (EOI query Q-07); until agreed, the proposed targets in the
[Test Plan](../../testing/test-plan.md#6-entry-and-exit-criteria) apply. Measured on the Production
build, desktop and mobile profiles, for every template type in use.

| Template type | Example URL | Performance score (mobile / desktop) | LCP (s) | CLS | INP / TBT (ms) | Page weight (KB) | Meets threshold? |
|---|---|---|---|---|---|---|---|
| Home | | | | | | | |
| Standard page | | | | | | | |
| Listing (tenders / careers / events) | | | | | | | |
| Detail (tender / job / event / doctor / department) | | | | | | | |
| Search results | | | | | | | |

| Load test (if run this quarter) | Concurrent users | p95 response (ms) | Error rate | Result |
|---|---|---|---|---|
| | | | | |

## 7. Availability and incidents

| Website | Availability Q[ ] | Incidents P1 / P2 / P3 | SLA breaches |
|---|---|---|---|
| Six websites (one row each) | | | |

## 8. Accessibility and quality

| Check | Result |
|---|---|
| Automated accessibility scan of every template (violations) | [ ] |
| Broken links / orphaned pages (all sites) | [ ] / [ ] |
| Pages with missing metadata (title/description) | [ ] |

## 9. Recommendations and action plan

| # | Recommendation | Priority | Owner | Target date | Status of previous quarter's actions |
|---|---|---|---|---|---|
| 1 | | | | | |

## 10. Sign-off

| | Vendor | TMC IT |
|---|---|---|
| Name / designation | | |
| Date | | |
| Signature | | |
