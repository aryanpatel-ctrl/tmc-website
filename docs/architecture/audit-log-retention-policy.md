# Audit Log Retention Policy

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-ARC-07 | 0.1 | Draft for TMC IT approval (retention periods to be confirmed: EOI query Q-25) | R-4.8-4, R-4.6-4 |

## 1. Purpose

SOW §4.8 requires "tamper-evident audit logs … for all administrative activity on the website and CMS",
"retained and made available to TMC for review". This policy defines what is retained, for how long,
where, how integrity is preserved, how TMC reviews the logs and how records are disposed of.

## 2. Log sources covered

| Code | Source | Content | Tamper evidence |
|---|---|---|---|
| L1 | CMS audit log (`tmc_tmc_audit_log`) | Every administrative action on all six sites (events listed in [Security Architecture §6.1](security-architecture.md#61-cms-audit-log-in-place)) | HMAC-SHA256 hash chain, verifiable on demand |
| L2 | Web container log (`tmc-wp`) | Apache access and error logs, PHP errors, `TMC-AUDIT` duplicate of every L1 entry written during a web request | Shipped to append-only central store |
| L3 | Reverse proxy / WAF logs | Requests, blocked requests | Central store (TMC infrastructure) |
| L4 | Host and bastion logs | SSH sessions, privilege use, Docker daemon events | Central store |
| L5 | Release records | GitHub Actions runs, `.release-history` on each server | GitHub history; server file included in backups |
| L6 | Repository history | Commits, pull requests, reviews | Git hashes; GitHub audit log |

## 3. Retention periods (proposed)

| Source | Online (searchable) | Archive | Basis |
|---|---|---|---|
| L1 CMS audit log | For the whole contract period, including warranty and AMC (no automatic deletion) | Quarterly signed CSV export retained by TMC for [TMC TO CONFIRM, proposed: 8 years] | SOW §4.8; audit and RTI needs of a public body |
| L2 to L4 | 180 days minimum, rolling | 1 year in compressed archive [TMC TO CONFIRM] | CERT-In Directions of 28 April 2022 (logs for a rolling 180 days within India) |
| L5, L6 | Life of the repository | Transferred to TMC at handover | SOW §8.2 |

All log storage is located in India (see [Data Residency Statement](data-residency-statement.md)).

## 4. Integrity controls

1. **Hash chain (L1).** Each entry contains the HMAC of its own fields and the previous entry's hash.
   *Network Admin → Audit Log → Verify integrity* recomputes the chain. A broken chain identifies the
   first altered, deleted or re-ordered entry.
2. **Key separation.** The HMAC key `TMC_AUDIT_KEY` is kept in the environment, not in the database. TMC
   IT holds an offline escrow copy. Database access alone is not enough to forge entries.
3. **Second record.** Web-request entries are also written to the container log (L2) and shipped off the
   host, so deleting database rows also leaves an independent trace.
4. **Anchoring.** At each quarterly review the reviewer records the entry count and the latest hash
   shown by *Verify integrity* in the review record (section 5). Any later attempt to rewrite history
   before that point is detectable against the recorded hash.
5. **No pruning in the application.** The current implementation has no function that deletes audit
   entries, and deleting the oldest entries would break verification from the first remaining entry.
   If TMC later requires online pruning, a checkpoint mechanism (recording the hash at the cut-off
   point) must be implemented through a Change Request before any deletion.

## 5. Review by TMC

| Frequency | Activity | By | Record |
|---|---|---|---|
| Monthly | Review of privileged events: `super_admin_granted/revoked`, `user_role_changed`, `plugin_*`, `theme_switched`, `site_created/deleted`, `network_setting_changed`, and bursts of `login_failed` | TMC IT with the Vendor Support Lead | Monthly support report, section "Security events" |
| Quarterly | *Verify integrity*; record count and latest hash; export CSV for archive; access review | TMC IT | [Quarterly Security and Performance Review](../operations/templates/quarterly-security-performance-review.md) |
| On demand | Filtered export (site, event, user) for an investigation or audit | Super Admin | Export itself is logged as `audit_log_exported` |

## 6. Access to logs

Only Super Admins (`manage_network` capability) can view, verify and export the CMS audit log. Host and
central-store logs are accessible to TMC IT and, per ticket, to the Vendor's Infrastructure/Security
Specialist (see [Access Control Policy](access-control-policy.md)).

## 7. Key rotation

Rotating `TMC_AUDIT_KEY` makes earlier entries unverifiable with the new key. Rotation is therefore done
only on suspicion of compromise, and as follows: (1) verify the chain and record count and last hash;
(2) export the full log and archive it with the old key in TMC's escrow; (3) change the key in `.env`
and recreate the web and cron containers (`docker compose up -d`, which applies the new environment); (4) record the rotation in the incident/change ticket. A key
rotation feature that keeps older segments verifiable can be added by Change Request.

## 8. Disposal

At the end of the retention period archived exports are destroyed by TMC IT under TMC's record
management procedure, and the destruction is recorded. The Vendor retains no copy of any log after
handover.
