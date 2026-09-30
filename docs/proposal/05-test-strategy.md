# 05 Test Strategy and Quality Assurance Plan

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-PRP-06 | 0.1 | R-4.14-\*, R-4.9-\*, R-4.10-6, R-7.1-1 (evaluation parameter 8, with 04: 8 marks) |

## 1. Strategy

1. **Automate everything repeatable** and run it on every change: CI builds all six websites from an
   empty server and runs static checks, integration tests of the CMS rules, and an HTTP smoke test of
   every site in both languages. A failing check blocks merging and deployment.
2. **Shift security and accessibility left**: security scanning and accessibility scanning run in CI on
   every release, so the external VAPT and STQC audits confirm quality rather than discover it.
3. **Test the real thing**: tests drive WordPress's own REST API as each role, so permissions are proven
   by the platform's real checks, not by mocks.
4. **Evidence per Go-Live**: every SOW §7.1 criterion has an automated or signed evidence item.

The detailed plan submitted for TMC approval is the [Test Plan](../testing/test-plan.md).

## 2. Test levels

| Level | Coverage | Tooling |
|---|---|---|
| Static analysis | PHP 8.3 syntax, JSON, JavaScript, shell, compose configuration | `scripts/lint.sh` |
| CMS integration tests | Role permissions, unit isolation, review workflow, translation links, audit trail and tamper detection; content types, lifecycle, expiry, listings (43 checks today, growing with each feature) | WP-CLI test suites in `scripts/tests/` |
| Smoke tests | Every site, both languages, key pages and listings, error pages, blocked endpoints, no PHP errors | `scripts/smoke-test.sh` + feature checks |
| End-to-end, cross-browser, responsive | User journeys per template on Chromium, Firefox and WebKit at mobile, tablet and desktop sizes | Playwright |
| Accessibility | Every template scanned (WCAG 2.2 AA) + manual keyboard, screen-reader, zoom and GIGW checks | axe-core + checklist |
| Performance | Per template, desktop and mobile, against agreed thresholds | Lighthouse CI |
| Load and stress | Agreed peak concurrency; failure point | k6 or equivalent |
| Security | Secret scan, image and dependency scan, DAST baseline; pre-VAPT assessment; CERT-In empanelled VAPT; segregation tests | CI security gate; agency |
| Links, orphans, HTML validity | Every site | Crawler, W3C validator |
| Migration verification | Inventory vs imported; redirects | Import and verification reports |
| Backup/DR | Restore and timed DR drill (RPO 15 min, RTO 1 h) | Drill scripts and record |
| UAT | TMC testers per role with approved scripts | Issue tracker with defect form |

## 3. Quality assurance practices

| Practice | Rule |
|---|---|
| Definition of done | A requirement is done only when its verification passes in CI or is evidenced in the documentation (RTM) |
| Code review | Every change through a pull request reviewed by a second developer; security-relevant changes reviewed by the Infrastructure/Security Specialist |
| Coding standards | PHP 8.3 with WordPress coding standards; every output escaped, every input sanitised, nonces and capability checks on writes, prepared SQL ([Engineering Conventions](../engineering/CONVENTIONS.md)) |
| Accessibility by construction | Semantic HTML, labels, visible focus, 44 × 44 px targets, announced dynamic results, reduced-motion support in every component |
| Reproducibility | No manual server changes; data changes as migrations; every environment from the same scripts |
| Defect management | Severity S1–S4, lifecycle, closure only by the tester; defect closure report before each Go-Live generated from the tracker (`scripts/reports/defect-closure-report.sh`) |

## 4. UAT support

The QA / Testing Engineer prepares UAT scripts per role and journey, creates tester accounts and data on
UAT, supports testers daily, triages defects, publishes daily status, and prepares the UAT sign-off and
defect closure report ([Test Plan §8](../testing/test-plan.md#8-user-acceptance-testing-uat)). No website
is proposed for Go-Live with an open S1 or S2 defect.

## 5. Reports

CI run reports on every change; accessibility, cross-browser, performance, link/orphan and security gate
reports per release; load test, VAPT and closure, DR drill, migration verification, UAT sign-off and
defect closure reports per Go-Live — all listed in the [Test Plan §11](../testing/test-plan.md#11-test-deliverables-and-reports-sow-415-81).
