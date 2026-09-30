# Access Control and Vendor Access Policy

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-ARC-06 | 0.1 | Draft for TMC IT approval | R-4.7-5, R-4.8-2, R-4.8-3, R-4.6-2, R-13-5 |

## 1. Purpose and scope

This policy governs who may access the TMC Website Ecosystem, how access is granted, reviewed and
removed, and the specific limits on access by the Vendor. It covers the CMS (all six websites), the
hosting infrastructure of every environment, the source code repository, CI/CD, backups and logs.

SOW §4.7: "Ownership of production and disaster recovery infrastructure shall rest with TMC. Vendor
access shall be limited to what is required for the project and shall be logged." SOW §4.8:
"Administrative access to the CMS and infrastructure shall be secured through multi-factor
authentication, restricted network access, and role-based authorization."

## 2. Principles

1. **TMC owns and grants.** TMC IT owns every production and DR account, subscription and credential and
   is the only party that grants access. The Vendor never creates its own standing access.
2. **Named individuals only.** No shared or generic accounts. Each person has one account per system.
3. **Least privilege.** The lowest role that allows the task. Super Admin is limited to TMC IT.
4. **Multi-factor authentication** for every privileged CMS account and every infrastructure account.
5. **Restricted network access** for administration: CMS administration and SSH only from TMC networks or
   the TMC-approved VPN/bastion.
6. **Everything is logged** and the logs are available to TMC (see
   [Audit Log Retention Policy](audit-log-retention-policy.md)).
7. **Time-bound vendor access** to Production and DR, tied to an approved ticket.

## 3. Access matrix

| System | TMC IT | Unit site administrators | Reviewers / Publishers | Content Editors | Vendor team |
|---|---|---|---|---|---|
| CMS network administration (Super Admin) | 2 named officers | — | — | — | UAT: yes. Production: time-bound, per ticket |
| CMS site administration | As required | Own site | — | — | UAT only; Production per ticket |
| CMS review and publishing | As required | Own site | Own site | — | UAT only |
| CMS drafting | As required | Own site | Own site | Own site | UAT only |
| Production/DR hosts (SSH, console) | Owner | — | — | — | Time-bound, per ticket, via VPN/bastion with MFA |
| Production database | Via host, emergency only | — | — | — | Via host, per ticket |
| UAT hosts | Read access on request | — | — | — | Operate (project period) |
| Source repository | Owner (admin) | — | — | — | Write via pull requests; `main` protected |
| CI/CD (GitHub Actions) | Owner | — | — | — | Run workflows; production promotion only with TMC approval |
| Backups | Owner; restore approval | — | — | — | Perform restores per ticket and drills |
| Audit log (read, verify, export) | Super Admins | — | — | — | Only when TMC IT requests an extract |

## 4. Account lifecycle

| Step | CMS accounts | Infrastructure accounts |
|---|---|---|
| Request | Written request from the unit head or TMC IT, stating site(s) and role | Support ticket stating purpose and duration |
| Approval | TMC IT (Super Admin) for Site Administrator; Site Administrator for Reviewer/Content Editor on their site | TMC IT |
| Creation | Created by the approving administrator; user sets own password; MFA enrolment enforced at first login (W2) | Created by TMC IT in TMC's identity system; MFA mandatory |
| Recording | Automatically recorded in the audit log (`user_created`, `user_added_to_site`, `user_role_changed`) | Recorded in the access register (section 7) |
| Review | Quarterly: each Site Administrator confirms the user list of their site; TMC IT confirms Super Admins | Quarterly by TMC IT; monthly for vendor accounts |
| Removal | Same day on transfer or exit (`remove user from site`; network removal for leavers) | Same day; vendor accounts disabled automatically at expiry of the ticket window |

Default and demonstration accounts created by `scripts/create-demo-users.sh` exist on UAT only and are
deleted before any environment receives live content.

## 5. Vendor access

### 5.1 Development and UAT (project period)

The Vendor operates Development, CI and UAT. UAT is reachable only over a private network (currently
Tailscale) and holds no live personal data.

### 5.2 Production and DR

| Rule | Detail |
|---|---|
| No standing access | Vendor accounts on Production/DR are disabled by default. |
| Ticket-based activation | TMC IT enables a named vendor account for a stated window (default 8 hours, maximum 5 working days) against an approved change or incident ticket. |
| Routine releases need no shell access | Releases reach Production only through the pipeline, after UAT sign-off; there is no manual file change on servers. |
| Session logging | All vendor sessions pass through the TMC VPN/bastion and are logged; CMS actions are in the audit log. |
| Data handling | Vendor staff do not copy Production data out of the environment. Where a copy is unavoidable for diagnosis, TMC IT approves it in writing, it stays in India and is deleted after use, with a deletion confirmation. |
| Emergency (Critical incident) | The Vendor Support Lead calls the TMC IT on-call contact; access is enabled and the ticket is completed retrospectively within one business day. |
| Personnel | Only personnel named in the project team and approved by TMC; each has signed the NDA (SOW §13). Replacement of key personnel only with TMC's prior written consent (SOW §10). |
| Exit | At contract end all vendor accounts are removed and all credentials the Vendor ever knew are rotated (see [Handover Checklist](../operations/handover-checklist.md)). |

### 5.3 Location

Vendor personnel access TMC environments only from within India.

## 6. Authentication standards

| Item | Standard |
|---|---|
| Passwords | Minimum 12 characters; not reused; no passwords in e-mail or chat. Initial passwords are set by the user. |
| MFA | TOTP authenticator for all Super Admin, Site Administrator and Reviewer/Publisher CMS accounts (enforced by `tmc-core/security-mfa.php` with the Two Factor plugin), and for every infrastructure account. |
| SSH | Key-based only; password authentication disabled; keys are per person. |
| Sessions | CMS sessions end after 30 minutes of inactivity; privileged sessions last at most 12 hours per sign-in (`tmc-core/security-session.php`). |
| Service credentials | Stored only in `.env` (mode 600) on the host or TMC's secret store; rotated per the [System and Security Administration Manual](../manuals/system-security-administration-manual.md#6-rotating-secrets). |

## 7. Access register

TMC IT maintains the register below (or the equivalent in its identity system) and reviews it
quarterly.

| # | Name | Organisation | System | Role / privilege | Approved by | From | To | Ticket | Reviewed on |
|---|---|---|---|---|---|---|---|---|---|
| 1 | | | | | | | | | |

## 8. Violations

Access outside this policy is a security incident and is handled under the
[Incident and Support Model](../operations/incident-support-model.md), including reporting to CERT-In
where required.
