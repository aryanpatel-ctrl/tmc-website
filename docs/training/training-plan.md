# Role-Based Training Plan

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-TRN-01 | 0.1 | Draft for TMC IT approval; participant numbers and dates to be confirmed (EOI query Q-27) | R-4.16-1, R-4.16-2, R-4.16-3, R-2-8, R-8.1-\* |

## 1. Requirement

SOW §4.16: "The Vendor shall provide role-based training to TMC IT and CMS administrators and to
TMC-level and unit-level content editors, covering CMS administration, user and role management, template
and content-type configuration, content creation and editing, media handling, the publishing workflow,
and routine deployment, backup and troubleshooting procedures. Training may be conducted online or at
Tata Memorial Hospital, Mumbai, as mutually convenient, and shall be accompanied by role-specific training
material, user manuals and quick reference guides, which shall become the property of TMC."

## 2. Audiences and tracks

| Track | Audience | CMS role(s) | Indicative participants | Duration | Mode |
|---|---|---|---|---|---|
| **T1** TMC IT: operations and security | TMC IT infrastructure and security staff | Super Admin; server access | [TMC TO CONFIRM, assumed 4–6] | 3 days (6 hours/day) | In person at TMH Mumbai (hands-on on the UAT and a training host) |
| **T2** CMS administrators | TMC IT CMS officers (network) and unit web coordinators (one site each) | Super Admin, Site Administrator | [TMC TO CONFIRM, assumed 8–12] | 2 days | In person at TMH Mumbai, or online |
| **T3** TMC-level editors | Editors and reviewers of the TMC umbrella website and TMC-wide content | Content Editor, Reviewer / Publisher | [TMC TO CONFIRM, assumed 10–15] | 1.5 days | In person or online |
| **T4** Unit-level editors | Editors and reviewers of each unit website (TMH, Visakhapatnam, Varanasi, Muzaffarpur, New Chandigarh) | Content Editor, Reviewer / Publisher | [TMC TO CONFIRM, assumed 5–8 per unit] | 1 day per batch | Online (one batch per unit, or combined), with an in-person option at TMH |
| **T5** Refresher / new joiners | Any of the above | As applicable | On request | 2–3 hours | Online |

Topic coverage against SOW §4.16:

| SOW topic | T1 | T2 | T3 | T4 |
|---|---|---|---|---|
| CMS administration | ● | ● | | |
| User and role management | ● | ● | | |
| Template and content-type configuration | ● | ● | ○ | ○ |
| Content creation and editing | | ○ | ● | ● |
| Media handling | | ○ | ● | ● |
| Publishing workflow | ○ | ● | ● | ● |
| Routine deployment | ● | ○ | | |
| Backup | ● | ○ | | |
| Troubleshooting | ● | ● | ○ | ○ |

● full coverage, ○ overview.

## 3. Schedule

Training is delivered so that each group is trained **before** the websites it works on go live, and is
completed by M6 (SOW §7: "training completed").

| Batch | Track | When (indicative) | Prerequisite |
|---|---|---|---|
| 1 | T2 CMS administrators (TMC IT + TMH coordinator) | After M3 sign-off (T+60 to T+75) | Templates and workflow signed off on UAT |
| 2 | T3 TMC-level editors; T4 TMH editors | Before M4 content entry (T+75 to T+90) | Batch 1 |
| 3 | T1 TMC IT operations and security | Before M4 Go-Live (T+90 to T+110) | Production and DR provisioned |
| 4 | T4 editors of the remaining units | Before M5 content entry (T+120 to T+150) | Units' coordinators nominated |
| 5 | T1 handover practice (independent build/deploy/operate rehearsal) | Before M6 (T+180 to T+200) | [Handover Checklist](../operations/handover-checklist.md) Part B |
| — | T5 refreshers | During warranty and AMC, on request | — |

## 4. Agendas

### T1 TMC IT: operations and security (3 days)

| Session | Topic | Hands-on |
|---|---|---|
| Day 1, 1 | Architecture: containers, networks, zones, segregation from clinical systems, environments | Walk-through of `docker-compose.yml` and the security zones |
| Day 1, 2 | Repository tour: `src/`, `scripts/`, `docs/`, migrations, tests | Clone, run `scripts/lint.sh` |
| Day 1, 3 | Installation from nothing | Exercise O-1 |
| Day 1, 4 | Configuration and `.env`; secrets; key escrow | Exercise O-2 |
| Day 2, 1 | CI/CD pipeline, releases, promotion, rollback | Exercise O-3 |
| Day 2, 2 | Backups, restore, DR drill with RPO/RTO measurement | Exercise O-4 |
| Day 2, 3 | Patch management and the licence inventory | Exercise O-5 |
| Day 2, 4 | Monitoring and daily checks (W7), logs | Exercise O-6 |
| Day 3, 1 | Security administration: MFA, admin allow-list, audit log, security events | Exercise O-7 |
| Day 3, 2 | Incident response: containment, evidence, recovery; CERT-In reporting | Exercise O-8 (table-top) |
| Day 3, 3 | Secret rotation | Exercise O-9 |
| Day 3, 4 | Assessment and feedback | Practical assessment |

### T2 CMS administrators (2 days)

| Session | Topic | Hands-on |
|---|---|---|
| Day 1, 1 | The network, sites, roles, Network Admin vs site dashboard | Tour |
| Day 1, 2 | Users and roles: create, add to site, change, remove; access register; quarterly review | Exercise A-1 |
| Day 1, 3 | Site settings, contact details, menus (both languages) | Exercise A-2 |
| Day 1, 4 | Languages: translations, string translations, adding a language (procedure) | Exercise A-3 |
| Day 2, 1 | Templates, locked sections, content types and fields; what is configuration vs Change Request | Exercise A-4 |
| Day 2, 2 | Review workflow administration; scheduled publishing; automatic expiry | Exercise A-5 |
| Day 2, 3 | Audit log: filter, verify integrity, export; responding to tampering | Exercise A-6 |
| Day 2, 4 | Adding a unit website (procedure); support model and escalation; assessment | Practical assessment + quiz |

### T3 TMC-level editors (1.5 days) and T4 unit-level editors (1 day)

| Session | Topic | T3 | T4 | Hands-on |
|---|---|---|---|---|
| 1 | Signing in, dashboard, roles and the publishing workflow | ● | ● | Tour |
| 2 | Pages: templates, sections, headings, parent pages, preview | ● | ● | Exercise E-1 |
| 3 | News and notices; expiry | ● | ● | Exercise E-2 |
| 4 | Tenders and EOIs, corrigenda; job openings | ● | ● | Exercise E-3 |
| 5 | Events and the calendar; departments and doctors | ● | ● | Exercise E-4 |
| 6 | Media: images with alternative text, accessible PDFs, documents | ● | ● | Exercise E-5 |
| 7 | Hindi versions (linked translations) | ● | ● | Exercise E-6 |
| 8 | Review, return with note, publish, schedule (Reviewer / Publishers) | ● | ● | Exercise E-7 |
| 9 | TMC-wide content and network publishing (*Publish to unit websites* panel) | ● | ○ | Demonstration |
| 10 | Writing for GIGW/WCAG: plain language, link text, tables; revisions and undo | ● | ● | Exercise E-8 |
| 11 | Assessment and feedback | ● | ● | Practical assessment + quiz |

## 5. Training environment

- Training is given on **UAT** (never on Production), using a training account per participant with the
  role of their track, created by TMC IT before the session and removed afterwards.
- T1 exercises that rebuild or restore an environment use a dedicated training host provided by TMC (or
  the Vendor's training host), never UAT or Production.
- All sample content created in training is labelled "TRAINING" and removed after the session.

## 6. Materials (property of TMC)

| Material | Location |
|---|---|
| Manuals | [CMS Administrator Manual](../manuals/cms-administrator-manual.md), [System and Security Administration Manual](../manuals/system-security-administration-manual.md), [Content Editor Manual](../manuals/content-editor-manual.md) |
| Quick reference cards per role | [Quick Reference Cards](../manuals/quick-reference/README.md) |
| Exercises and assessment | [Exercises and Assessment](exercises-and-assessment.md) |
| Slides | Built from the manuals for each session (delivered as editable files with the documentation set) |
| Recordings of online sessions | If TMC permits recording; handed over with the documentation |
| Attendance and feedback forms | [Training Records](training-records.md) |

All materials are delivered in editable (DOCX, Markdown) and portable (PDF) formats
(`scripts/docs/build-docs.sh`) and become TMC's property (SOW §4.16).

## 7. Assessment and certification of completion

| Element | Method | Pass criterion |
|---|---|---|
| Practical assessment | Each participant completes the assessment tasks of their track on UAT unaided; the trainer checks the result (and, for administrators, the audit log) | All mandatory tasks completed correctly |
| Knowledge check | 10–15 question quiz per track ([question bank](exercises-and-assessment.md#5-knowledge-check-question-bank)) | ≥ 80 % |
| Feedback | Participant feedback form | Average ≥ 4 of 5; lower scores trigger a corrective session |

Participants who do not pass repeat the relevant exercises in a T5 refresher session at no cost. The
training completion report (per batch: participants, attendance, results, feedback, actions) is
submitted to TMC IT and forms part of the M6 deliverables.

## 8. Roles

| Role | Responsibility |
|---|---|
| Support Lead (Vendor) | Training coordination (SOW §10), scheduling, records, completion report |
| Trainers (Vendor: Backend/CMS Developer for T2–T4; Infrastructure/Security Specialist for T1) | Delivery, exercises, assessment |
| TMC IT nodal officer | Nominations, rooms/online platform, training accounts, approval of the plan and completion report |
| Unit coordinators | Nominating unit editors, ensuring attendance |
