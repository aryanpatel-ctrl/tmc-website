# 02 Proposed CMS Platform and Justification

| Document ID | Version | RTM references |
|---|---|---|
| TMC-WEB-PRP-03 | 0.1 | R-4.5-1, R-4.5-2, R-12.1-\*, R-13-2, R-13-3 (evaluation parameter 2: 15 marks) |

## 1. Proposal

The Vendor proposes **WordPress (current supported release) in Multisite mode with subdomains**, which is
one of the two platforms named as preferred in SOW §4.5 ("a widely adopted, actively maintained
open-source CMS, such as Drupal or WordPress (multisite)"). No premium or proprietary plugin is used; the
only third-party plugin at present is Polylang (GPL-3.0-or-later) for multilingual content. Everything
else is the project's own GPL code, owned by TMC.

## 2. Assessment against the SOW §4.5 and §11.1 criteria

| Criterion | WordPress Multisite (proposed) | Basis in this project |
|---|---|---|
| **Functionality** | Multisite in core: one network, shared users and code, per-site content and settings. Block editor with template locking lets editors fill defined fields and sections without HTML or CSS (SOW §4.3). Built-in revisions, scheduling, roles and capabilities, media library, REST API | Six sites provisioned as one network; locked home sections; content types with structured fields; review workflow and audit log implemented as modules |
| **Maintainability** | Large pool of skilled developers in India; stable, backward-compatible APIs; WP-CLI for full automation; configuration as code | Every environment built by scripts (`setup.sh`); data changes as versioned migrations; modules as separate files; inline documentation |
| **Scalability** | Proven on high-traffic sites; object cache (Redis protocol), page cache, horizontal scaling of stateless web containers | Redis object cache in place; container architecture scales horizontally ([System Architecture §10](../architecture/system-architecture.md#10-scalability-and-extensibility)) |
| **Security** | Dedicated core security team, coordinated disclosure, frequent security releases; hardening options (file editing disabled, least-privilege roles); mature ecosystem of security tooling | Hardened container and PHP/Apache configuration; roles tightened (no self-publishing role); HMAC-chained audit log; MFA and admin restrictions (W2); CI security gate; patch SLA within 30 days ([Patch Management](../operations/patch-management.md)) |
| **Community / long-term support** | Open-source project with a published release cycle, an active global community and long history of maintained releases; translations maintained for Hindi and many Indian languages | Language packs `hi_IN` and `en_GB` installed by provisioning |
| **Freedom from lock-in** | GPL-2.0-or-later; no licence fees; standard LAMP stack; content exportable via standard formats and REST | GPL-only inventory ([THIRD-PARTY-LICENSES](../THIRD-PARTY-LICENSES.md)); repository and all scripts handed over; any competent WordPress team can take over (SOW §13) |
| **Accessibility** | Accessible admin interface and block editor; themes can meet WCAG 2.2 AA | Theme built to GIGW 3.0 / WCAG 2.2 AA: skip link, text resizing, contrast mode, keyboard mega-menu, focus styles |
| **Multilingual** | Mature multilingual plugins; Polylang links translations and uses language directories (`/hi/`) | English and Hindi on every site; new languages without template changes (SOW §4.13) |

## 3. Comparison with alternatives

| Aspect | WordPress Multisite | Drupal (current major version) | Custom-built CMS |
|---|---|---|---|
| Named as preferred in SOW §4.5 | Yes | Yes | No (requires justification and evidence of two comparable deployments, SOW §9) |
| Multi-site model | Core Multisite: one database, shared users and code | Multisite with separate databases per site, or domain-access modules | Must be built |
| Editor experience for code-free template-based pages | Block editor with locked templates and patterns | Layout Builder / Paragraphs, powerful but more complex for occasional editors | Must be built |
| Upgrade effort over a 5-year AMC | Incremental releases with strong backward compatibility | Major-version upgrades every few years require planned migration effort | Entirely the vendor's responsibility |
| Availability of skills to TMC after handover | Very high | Good | Limited to the original vendor (lock-in risk contrary to SOW §13) |
| Licence | GPL-2.0-or-later | GPL-2.0-or-later | Depends on the vendor |

Drupal is a capable platform and would also meet the SOW. WordPress Multisite is proposed because it gives
the six websites one shared user directory and code base out of the box, gives occasional unit-level
editors the simplest template-driven editing experience, minimises upgrade effort across the four-year
AMC, and maximises the pool of people TMC can call on after handover. A custom CMS is not proposed because
it would concentrate security and maintenance risk in one vendor and conflict with the no-lock-in
requirement.

## 4. Plugin policy

1. Only GPL-compatible, actively maintained plugins with a public security track record; no premium,
   "pro" or licence-key plugins (SOW §4.5: "no proprietary or licensing dependency").
2. Every plugin is pinned to an exact version in the provisioning scripts and updated through the
   pipeline (never from the admin screens).
3. Functionality specific to TMC is written as project modules in `tmc-core`, not as third-party
   plugins, to keep the attack surface small and ownership with TMC.
4. Each plugin is listed with its licence in the generated [licence inventory](../THIRD-PARTY-LICENSES.md).

## 5. Approval at M2

SOW §7 requires "CMS platform approved by TMC" at M2. The Vendor will demonstrate the working platform on
UAT (six sites, roles, workflow, content types, audit log, CI/CD) and submit this justification with the
[System Architecture](../architecture/system-architecture.md) for TMC's decision. Acceptance is entirely
at TMC's discretion (SOW §4.5).
