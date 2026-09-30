# Training Exercises and Assessment

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-TRN-02 | 0.1 | R-4.16-1 to R-4.16-3 |

Exercises referenced by the [Training Plan](training-plan.md). Each exercise states the goal, the steps
(with a pointer to the manual section) and the **evidence** the trainer checks. Exercises are done on UAT
(T2–T4) or a training host (T1) with training accounts, never on Production.

---

## 1. T1 Operations and security (TMC IT)

| # | Exercise | Steps | Evidence checked by the trainer |
|---|---|---|---|
| O-1 | Install the ecosystem from nothing | Clone; `scripts/make-env.sh server`; set `TMC_BASE_DOMAIN`; `scripts/setup.sh` ([Installation Guide §3](../operations/installation-deployment.md#3-server-installation-uat-production-dr)) | `setup complete` line; six sites listed by `wp site list` |
| O-2 | Configuration and secrets | Identify every `.env` variable and its consumer; record `TMC_AUDIT_KEY` in the escrow procedure ([Configuration Reference §1](../operations/configuration-reference.md#1-environment-file-env)) | Participant explains three variables that must not change after install and why |
| O-3 | Release and rollback | Open a pull request with a harmless change; watch CI; merge; confirm UAT deploy; roll back with *Run workflow* ([Installation Guide §4–5](../operations/installation-deployment.md#4-routine-deployment-every-release)) | Two entries in `.release-history`; smoke test passed after rollback |
| O-4 | Backup, restore and DR timing | Take an on-demand DB and uploads backup; delete a test page; restore; measure the time ([Backup and Restoration §3–4](../operations/backup-restore.md#3-taking-a-backup-on-demand)) | Page back; audit log verified intact; drill record filled with RPO/RTO |
| O-5 | Patch a component | Raise `POLYLANG_VERSION` (or an image tag) on a branch; run CI; regenerate the licence inventory ([Patch Management §4](../operations/patch-management.md#4-how-updates-are-applied)) | Patch register entry; `scripts/licenses.sh --check` passes |
| O-6 | Daily checks and logs | Run the daily checks; find a PHP error and a `TMC-AUDIT` line in the logs ([System and Security Administration Manual §3–4](../manuals/system-security-administration-manual.md#3-daily-health-checks)) | Checklist completed |
| O-7 | Security review | Find all `login_failed` events of a user; verify audit-log integrity; export CSV ([CMS Administrator Manual §9](../manuals/cms-administrator-manual.md#9-audit-log)) | Screenshot of the verification result; CSV file |
| O-8 | Incident table-top | Scenario: a unit home page is defaced at 22:00 on a Saturday. Walk through containment, evidence, CERT-In reporting, recovery ([Incident and Support Model §6](../operations/incident-support-model.md#6-security-incidents)) | Written timeline with owners and times |
| O-9 | Rotate `DB_PASSWORD` | Follow the procedure ([System and Security Administration Manual §6.1](../manuals/system-security-administration-manual.md#61-db_password-wordpress-database-user)) | Sites work; smoke test passed; rotation recorded without the value |

## 2. T2 CMS administrators

| # | Exercise | Steps | Evidence |
|---|---|---|---|
| A-1 | Users and roles | Create a user; add as Content Editor to a unit site; change to Reviewer / Publisher; remove from the site ([CMS Administrator Manual §4](../manuals/cms-administrator-manual.md#4-users-and-roles)) | Four audit-log entries: `user_created`, `user_added_to_site`, `user_role_changed`, `user_removed_from_site` |
| A-2 | Site settings and menus | Change the phone number in *TMC contact details*; add the Hindi address translation; add a page to the Main menu in both languages ([§5](../manuals/cms-administrator-manual.md#5-site-settings)) | Footer shows the new number in both languages; menu item visible in both languages |
| A-3 | Languages | Locate the Hindi string translations; explain the steps to add a third language ([§6.3](../manuals/cms-administrator-manual.md#63-adding-a-third-or-fourth-language-sow-413)) | Participant lists the five steps |
| A-4 | Configuration vs change request | For five given requests, decide "configure in CMS" or "Change Request" ([§7](../manuals/cms-administrator-manual.md#7-templates-sections-and-content-types)) | ≥ 4 of 5 correct |
| A-5 | Workflow administration | As reviewer, return an item with a note; then schedule it 10 minutes ahead ([Content Editor Manual §11](../manuals/content-editor-manual.md#11-review-approval-and-scheduling)) | Item published at the scheduled time; audit shows `content_returned_for_changes`, `content_scheduled`, `content_published` |
| A-6 | Audit log | Filter by site and event; verify integrity; export CSV; record the anchor ([§9](../manuals/cms-administrator-manual.md#9-audit-log)) | Anchor (count + hash) written in the exercise sheet |

## 3. T3 / T4 editors

| # | Exercise | Steps | Evidence |
|---|---|---|---|
| E-1 | Create a page | Create "TRAINING – <name> page" under *Patient Care* with two Heading 2 sections, a list and a link; preview; submit ([Content Editor Manual §3](../manuals/content-editor-manual.md#3-pages)) | Page in *Waiting for review* with correct parent |
| E-2 | Time-bound notice | Create a notice with *Expires on* 15 minutes ahead; after approval, confirm it leaves the notice board after expiry ([§4](../manuals/content-editor-manual.md#4-news-and-notices-posts)) | Notice visible, then gone from the notice board |
| E-3 | Tender and corrigendum | Create a TRAINING tender with reference number, closing date, portal link and a PDF; then add a corrigendum PDF and extend the date ([§5](../manuals/content-editor-manual.md#5-tenders-and-eois)) | Tender listed as open with two documents and the new date |
| E-4 | Event and doctor | Create an event with start/end and venue; download its `.ics`; create a doctor profile linked to a department ([§6](../manuals/content-editor-manual.md#6-events), [§8](../manuals/content-editor-manual.md#8-departments-and-doctors)) | Event in the calendar view; doctor found via the department filter |
| E-5 | Media | Upload an image with meaningful alternative text and a text-based PDF; insert both on the training page ([§10](../manuals/content-editor-manual.md#10-images-and-documents-media)) | Alt text present; PDF link text states type and size |
| E-6 | Hindi version | Create the linked Hindi version of the training page ([§9](../manuals/content-editor-manual.md#9-hindi-versions-translations)) | Language switcher moves between the two versions |
| E-7 | Review (Reviewer / Publishers) | Review a colleague's page against the checklist; return it once with a note; approve and schedule it ([§11](../manuals/content-editor-manual.md#11-review-approval-and-scheduling)) | Note visible to the author; page published at the scheduled time |
| E-8 | Revisions | Change a sentence, then restore the previous revision ([§12](../manuals/content-editor-manual.md#12-version-history)) | Original text restored |

## 4. Practical assessment tasks

| Track | Mandatory tasks (completed unaided within 45 minutes) |
|---|---|
| T1 | O-4 (restore with measured time) and O-7 (integrity verification + export) |
| T2 | A-1 and A-6 |
| T3 / T4 Content Editor | E-1, E-3 and E-6 |
| T3 / T4 Reviewer / Publisher | E-1, E-3, E-6 and E-7 |

## 5. Knowledge-check question bank

Trainers select 10–15 questions for the participant's track. Pass mark 80 %.

| # | Question | Tracks |
|---|---|---|
| Q1 | A Content Editor wants to correct a typing mistake on a published page. What happens and who does it? | T2–T4 |
| Q2 | Which field makes a tender move to the archive, and does anyone have to act at that moment? | T2–T4 |
| Q3 | How do you return an item to its author with instructions? | T2–T4 |
| Q4 | Where do you see what the reviewer asked you to change? | T3–T4 |
| Q5 | What must every informative image have, and why? | T3–T4 |
| Q6 | How is a Hindi page linked to its English page? | T2–T4 |
| Q7 | Name the four CMS roles and one thing each can do that the role below cannot. | T1–T4 |
| Q8 | A unit asks for a new field "Pre-bid meeting date" on tenders. Is this configuration or a Change Request? | T1–T2 |
| Q9 | What does "Tampering detected at entry #N" mean and what do you do first? | T1–T2 |
| Q10 | Why is a site created only through *Network Admin → Sites → Add New* not enough? | T1–T2 |
| Q11 | Which `.env` value must be kept in escrow separately and why? | T1 |
| Q12 | Which command must never be used on a server because it deletes the data volumes? | T1 |
| Q13 | What are the RPO and RTO targets and how are they demonstrated? | T1 |
| Q14 | Within how many hours must a cyber-security incident be reported to CERT-In, and by whom in this project? | T1 |
| Q15 | How does a code change reach Production, and how is it rolled back? | T1 |
| Q16 | What are the SLA resolution times for Critical, High and Medium issues? | T1–T2 |
| Q17 | Where is the list of third-party components and their licences, and how is it updated? | T1 |
| Q18 | What is the maximum upload size, and what kind of PDF should be uploaded? | T3–T4 |

## 6. Answer key (trainers only)

| # | Expected answer |
|---|---|
| Q1 | Content Editors cannot change published content; a Reviewer / Publisher edits and clicks **Update** (logged as `content_updated` with the changed fields). |
| Q2 | *Last date and time of submission*; no action — listings treat it as closed from that moment and the automatic job records `tender_closed`. |
| Q3 | Write in **Review note**, **Save**, change status from *Pending Review* to **Draft**, save; the author is notified. |
| Q4 | Dashboard **My submissions** (Returned for changes, with the note) and the **Review note** box in the editor. |
| Q5 | Alternative text — so screen-reader users get the information (WCAG 2.2 AA / GIGW). |
| Q6 | Languages panel → **+** next to Hindi from the English item (Polylang translation link). |
| Q7 | Super Admin (network, audit log) > Site Administrator (users, menus, settings of one site) > Reviewer / Publisher (publish, schedule) > Content Editor (drafts, submit). |
| Q8 | Change Request: fields are defined in code (`tmc_field_schema()`), deployed through the pipeline. |
| Q9 | An entry from #N onward was altered, deleted or re-ordered; treat as a P1 security incident — change nothing, export, inform TMC IT and the Vendor. |
| Q10 | Provisioning scripts and fresh/DR installations would not know it (scripts stop with "Unknown site"); use the documented procedure. |
| Q11 | `TMC_AUDIT_KEY`; without it the audit-log chain of a restored database cannot be verified, and it must not be in the database. |
| Q12 | `docker compose down -v`. |
| Q13 | RPO 15 minutes, RTO 1 hour, demonstrated by the quarterly timed DR drill. |
| Q14 | Within 6 hours of noticing it; TMC reports, the Vendor supplies technical details. |
| Q15 | Pull request → CI → merge → UAT deploy → TMC approval → Production promotion; rollback by running the pipeline for an earlier commit/tag (data migrations need a backup restore). |
| Q16 | Critical 4 business hours; High 1 business day; Medium 3 business days. |
| Q17 | `docs/THIRD-PARTY-LICENSES.md`, regenerated by `scripts/licenses.sh` at each release. |
| Q18 | 64 MB; a PDF containing real text (not a scanned image), with a clear title. |
