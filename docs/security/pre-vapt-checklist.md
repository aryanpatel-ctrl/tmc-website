# Pre-VAPT checklist

Internal pre-assessment before the release candidate goes to the CERT-In empanelled VAPT agency, STQC
and the Safe-to-Host review (tender §4.8; RTM R-4.8-7). Work through it for every release candidate that
will be tested, record the result and attach the evidence to the VAPT readiness pack.

Status legend: **Built** = implemented and tested in this repository; **Ops** = must be done on the target
environment; **TMC** = needs a decision or information from TMC; **Pending** = open work item.

Release candidate: `__________` (git tag / commit) · Environment: `__________` · Checked by: `__________` · Date: `__________`

## 1. Evidence from the pipeline

| # | Check | Status | Evidence |
|---|---|---|---|
| 1.1 | CI run for the release candidate is green: lint, integration tests, smoke test | Built | Actions run URL |
| 1.2 | Security gate green: gitleaks, Trivy (no fixable CRITICAL), ZAP baseline (no FAIL rule) | Built | Artifacts `security-secrets-report`, `security-image-reports`, `security-dast-reports` |
| 1.3 | Every WARN in the ZAP report is either in the accepted list (`security/zap-baseline.conf`, `docs/security/owasp-top10.md`) or has a fix/ticket | Built (review each release) | ZAP HTML report, reviewer's notes |
| 1.4 | HIGH vulnerabilities in `trivy-image-high-critical.txt` reviewed; rebuild on the latest patched base image | Ops | Trivy report |
| 1.5 | `security-test.php` passed in full (about 125 checks) | Built | CI log |
| 1.6 | OWASP Top 10 mapping reviewed for changes since the last test | Built | `docs/security/owasp-top10.md` |

## 2. Hosting and network

| # | Check | Status | Notes |
|---|---|---|---|
| 2.1 | Only ports 80/443 reachable from the internet; 80 redirects to 443 | Ops | Port scan from outside |
| 2.2 | TLS 1.2+ only, strong ciphers, valid certificate for every site host, OCSP stapling | Ops | e.g. `testssl.sh` report |
| 2.3 | HSTS present on HTTPS responses (automatic); decide `TMC_HSTS_INCLUDE_SUBDOMAINS` once all hosts under the domain use HTTPS | Built / TMC | `curl -I https://…` |
| 2.4 | Database and cache on the internal network only, no host ports; no route to clinical systems | Built / Ops | `docker-compose.yml`; security architecture document (R-4.8-8) |
| 2.5 | Reverse proxy appends the real client address to `X-Forwarded-For`; Apache trusts only the proxy (`RemoteIPInternalProxy`). For production, narrow the trusted range to the proxy's own address | Ops | `wordpress/apache-tmc.conf`, proxy config |
| 2.6 | `TMC_ADMIN_ALLOW_CIDRS` set to TMC's administrative networks (and VPN) for production; the default (private ranges) is for UAT | TMC / Ops | `.env` on the server |
| 2.7 | Rate limiting / WAF at the edge for volumetric attacks (the application throttles sign-in, not traffic floods) | Ops | Proxy / WAF config |
| 2.8 | Server OS patched, SSH key-only, no password login, firewall enabled | Ops | Hosting checklist |

## 3. Application configuration

| # | Check | Status | Notes |
|---|---|---|---|
| 3.1 | `TMC_ENV=server` on UAT/production (code changes from admin screens disabled) | Built / Ops | `.env` |
| 3.2 | `TMC_ENFORCE_MFA` not set to `0` | Built / Ops | `.env` |
| 3.3 | `WP_DEBUG` off, no debug output in pages | Built | Smoke test checks pages for PHP notices |
| 3.4 | XML-RPC blocked; no PHP execution in uploads; directory listing off | Built | Smoke test; `apache-tmc.conf`; `Options -Indexes` from the official image's `docker-php.conf` (verify on the server) |
| 3.5 | Security headers present (CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy, COOP, CORP) | Built | Smoke test `security.sh` |
| 3.6 | `security.txt` contact confirmed by TMC and set in `TMC_SECURITY_CONTACT` (the file currently shows a marked placeholder) | TMC | `/.well-known/security.txt` |
| 3.7 | Default/demo accounts removed or passwords changed; `demo-users.txt` deleted; sample content (`_tmc_sample`) removed before go-live | Ops | `wp user list`, content review |
| 3.8 | Only pinned, required plugins installed (Polylang, Two Factor); no inactive plugins or themes left on the server | Built / Ops | `wp plugin list`, `wp theme list` |
| 3.9 | User registration disabled; default role is not privileged | Built (network default) / Ops | Network Admin → Settings |

## 4. Authentication and sessions

| # | Check | Status | Notes |
|---|---|---|---|
| 4.1 | Every Super Admin, Site Administrator and Reviewer / Publisher has enrolled an authenticator and stored backup codes | Ops | Network Admin → Users (Two Factor column) |
| 4.2 | Lockout works (5 failures per account, 20 per IP, exponential backoff) and is audited | Built | `security-test.php` section 3 |
| 4.3 | Login errors generic; lost-password form does not reveal accounts; no username in author archives, REST, oEmbed, sitemaps | Built | `security-test.php` section 2; smoke |
| 4.4 | Password policy for privileged accounts; weak password found at sign-in forces a change | Built | `security-test.php` section 6 |
| 4.5 | Idle timeout (30 min) and 12-hour session cap for privileged accounts | Built | `security-test.php` section 7 |
| 4.6 | Application passwords disabled (or restricted to named integration roles via `TMC_APP_PASSWORD_ROLES`) | Built / TMC | `security-test.php` section 5 |

## 5. Authorisation and data

| # | Check | Status | Notes |
|---|---|---|---|
| 5.1 | Roles and unit isolation as designed; Content Editors cannot publish | Built | `workflow-test.php` |
| 5.2 | Admin area reachable only from the allow-list; public `admin-ajax.php`/`admin-post.php` still work for visitors | Built | `security-test.php` section 1; smoke |
| 5.3 | No patient or clinical data in the CMS, forms or uploads | TMC / Ops | Content review |
| 5.4 | Uploaded documents are public by design; nothing confidential in the media library | TMC | Content review |
| 5.5 | Database backups encrypted and access-controlled | Pending (W7) | Backup/DR workstream |

## 6. Logging and monitoring

| # | Check | Status | Notes |
|---|---|---|---|
| 6.1 | Audit log intact (Network Admin → Audit Log → Verify integrity) | Built | Screenshot of the result |
| 6.2 | Security events present: `login_failed`, `login_lockout`, `admin_access_blocked`, `mfa_*` | Built | Audit log filter / CSV export |
| 6.3 | Container logs forwarded to TMC's log store; alerts on lockouts, blocked admin access and MFA removal | Pending (Ops) | Monitoring setup |
| 6.4 | Log retention period agreed and documented | Pending (W8, R-4.8-4) | Retention policy document |

## 7. Information for the VAPT agency

Provide with the test request:

- Scope: the six site host names (production or a production-like staging environment), in English and Hindi (`/hi/`).
- Test accounts, one per role (Content Editor, Reviewer / Publisher, Site Administrator), created for the test
  window only. Privileged test accounts must enrol an authenticator; share the enrolment QR code or seed
  through a secure channel, and delete the accounts after the test.
- The agency's source IP addresses, added to `TMC_ADMIN_ALLOW_CIDRS` for the test window (and removed afterwards),
  so that authenticated admin testing is possible. Keep the lockout active; tell the agency the thresholds.
- This checklist, the OWASP mapping and the latest security-gate reports.
- Architecture and data-flow description (security architecture document, R-4.8-8).
- A contact for urgent findings during the test.

After the test: record each observation, fix it through the normal pipeline (every fix gets a test), re-run
the security gate and request re-validation (R-4.8-9).

## Known limitations to disclose

- Login/admin screens use a baseline CSP (WordPress core still prints inline scripts there); mitigated by the
  admin network allow-list and MFA.
- `style-src 'unsafe-inline'` on public pages (WordPress core inline styles; no script execution).
- TOTP seeds are stored in the database (plugin design); database isolated on an internal network.
- Application-level throttling protects sign-in only; traffic floods must be handled at the edge (2.7).
