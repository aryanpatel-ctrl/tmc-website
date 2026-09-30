# Data Residency Compliance Statement (Template)

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-ARC-05 | 0.2 | Template: completed and signed at Milestone M2 once hosting is confirmed | R-4.7-1, R-4.7-2, R-7-2 |

**Instructions.** SOW §4.7 requires the Vendor to submit a data residency compliance statement. Complete
every field marked [BIDDER TO FILL] or [TMC TO FILL] from the provisioned environments, attach the
evidence listed in Part C, and submit on company letterhead with the M2 deliverables. Do not sign until
every row in Part B can be evidenced.

---

**(On company letterhead)**

To: The Head, Information Technology Department, Tata Memorial Centre, Parel, Mumbai – 400 012

Subject: Data residency compliance statement: TMC Website Ecosystem (EOI No. TMH/TMH/2026-27/CAP/EO/0009,
Contract No. [TMC TO FILL])

## Part A: Declaration

We, [BIDDER TO FILL: legal name of the Vendor], the Vendor for the TMC Website Ecosystem, state that:

1. The TMC Website Ecosystem (the TMC website and the constituent unit websites) is hosted on
   infrastructure owned or subscribed by Tata Memorial Centre, as listed in Part B.
2. Where a cloud service provider is used, the provider is empanelled by the Ministry of Electronics and
   Information Technology (MeitY), and the subscription is held in the name of Tata Memorial Centre.
3. All data of the ecosystem, including content, databases, uploaded media and documents,
   configuration, application and security logs, audit logs, and backups (including disaster-recovery
   copies), are stored and processed only within the territory of India.
4. No data of the ecosystem is transferred to, replicated to, processed in or accessible from any
   location outside India, including by any content delivery network, analytics, e-mail, monitoring,
   backup, support or software-update service.
5. The software does not load scripts, fonts, style sheets or trackers from third-party servers at
   runtime; all assets are served from TMC's own hosts.
6. Vendor personnel access the environments only from within India, through the access arrangements
   approved by TMC, and all such access is logged.
7. We will notify TMC IT in writing before any change that could affect the location of data, and will
   not implement such a change without TMC's prior written approval.

## Part B: Data location register

| # | Data asset | Environment | Service / component | Provider and MeitY empanelment reference | Region / data centre (city, India) | Account holder | Encryption at rest | Evidence ref. |
|---|---|---|---|---|---|---|---|---|
| 1 | Database (MariaDB `db_data`) | Production | [BIDDER TO FILL] | [BIDDER TO FILL] | [BIDDER TO FILL] | Tata Memorial Centre | [ ] | C-1 |
| 2 | Uploads and media (`wp_html` volume) | Production | [BIDDER TO FILL] | [BIDDER TO FILL] | [BIDDER TO FILL] | Tata Memorial Centre | [ ] | C-1 |
| 3 | Database and uploads | DR | [BIDDER TO FILL] | [BIDDER TO FILL] | [BIDDER TO FILL] | Tata Memorial Centre | [ ] | C-1 |
| 4 | Backups (15-minute and daily sets) | Production/DR | [BIDDER TO FILL] | [BIDDER TO FILL] | [BIDDER TO FILL] | Tata Memorial Centre | [ ] | C-2 |
| 5 | Application, web server and audit logs | All | [BIDDER TO FILL] | [BIDDER TO FILL] | [BIDDER TO FILL] | Tata Memorial Centre | [ ] | C-3 |
| 6 | UAT environment | UAT | [BIDDER TO FILL] | [BIDDER TO FILL] | [BIDDER TO FILL] | [TMC TO FILL] | [ ] | C-1 |
| 7 | Source code repository and CI logs | — | [BIDDER TO FILL]: repository host | [BIDDER TO FILL] | [BIDDER TO FILL] | Tata Memorial Centre | — | C-4 |
| 8 | Web analytics data | Production | [TMC TO FILL]: TMC-approved tool | [TMC TO FILL] | [TMC TO FILL] | Tata Memorial Centre | [ ] | C-5 |
| 9 | Outbound e-mail relay | Production | [TMC TO FILL] | [TMC TO FILL] | [TMC TO FILL] | Tata Memorial Centre | — | C-5 |
| 10 | DNS and TLS certificates | Production | [TMC TO FILL] (e.g. NIC) | — | — | Tata Memorial Centre | — | C-5 |

**Note on row 7.** The repository contains source code, provisioning scripts and documentation, and no
content database, personal data or secrets (`.env`, backups and credentials are excluded from version
control). CI builds use generated sample data only. If TMC requires the repository and CI to be hosted
in India as well, the repository is transferable without change to any Git server designated by TMC (for
example a self-hosted GitLab or Gitea instance in TMC's data centre), and CI can run on a self-hosted
runner in India. [TMC TO CONFIRM]

## Part C: Evidence attached

| Ref. | Evidence |
|---|---|
| C-1 | Screenshots or exports of the cloud/host console showing region and account holder for each compute and storage resource |
| C-2 | Backup configuration export showing destination bucket/storage and region |
| C-3 | Log shipping configuration showing the log store location |
| C-4 | Repository and CI hosting details |
| C-5 | Confirmation from TMC of TMC-provided services (analytics, e-mail relay, DNS, TLS) |
| C-6 | Evidence that browsers request no third-party hosts: the Content-Security-Policy of the public pages (`default-src`, `script-src` and `connect-src` limited to the site itself and the network's own sites; `src/mu-plugins/tmc-core/security-headers.php`, checked by `scripts/smoke.d/security.sh`) and a browser network log of each template captured on the deployed environment. Exceptions, loaded only when configured or requested: the analytics host chosen by TMC and the OpenStreetMap map shown after the visitor presses "Show map" |

## Part D: Signature

| | Vendor | Accepted by TMC IT |
|---|---|---|
| Name | [BIDDER TO FILL] | [TMC TO FILL] |
| Designation | [BIDDER TO FILL] | [TMC TO FILL] |
| Signature and seal | | |
| Date | | |
