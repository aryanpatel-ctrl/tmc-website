# Design Deviation Register

| Document ID | Version | Status | RTM references |
|---|---|---|---|
| TMC-WEB-GOV-05 | 0.2 | Open register; maintained from receipt of Annexures A–C until Project Closure and through AMC | R-5-1, R-1-3, R-3-1 to R-3-3, R-7.1-1 |

## 1. Purpose

SOW §5: "The Vendor shall not deviate from the approved design — in typography, spacing, colour, layout,
or component behaviour — except where such deviation is agreed in writing by TMC IT in advance. Any
deviation implemented without prior written approval shall be rectified by the Vendor at its own cost."
SOW §7.1 requires, for each Go-Live, "full conformance with the approved design system and Information
Architecture, with no unapproved variants".

This register lists every difference between the delivered websites and Annexures A (design system),
B (IA and templates) and C (Figma), with its justification and TMC IT's written decision. At each Go-Live
the register must contain **no entry with status *Proposed* or *Rejected – not yet rectified***.

## 2. When a deviation may be proposed

Only where following the design exactly would:

1. breach WCAG 2.2 Level AA or GIGW 3.0 (SOW §4.9: the stricter requirement applies) — for example a
   colour pair below the 4.5:1 contrast ratio, a target smaller than the minimum size, information conveyed
   by colour alone, or motion without a pause control;
2. breach a security or privacy requirement (for example an embedded third-party script);
3. be technically impossible in the CMS without code that editors would have to maintain; or
4. conflict with another part of the annexures (inconsistency between Annexure A and C).

Deviations are proposed through the [Change Request Procedure](change-request-procedure.md) (category
*Design deviation*) and recorded here.

## 3. How conformance is checked

| Check | Method |
|---|---|
| Design tokens | Colours, fonts, sizes and spacing exist only as tokens in `src/themes/tmc/theme.json`; editors cannot choose custom values (`custom: false`), so pages cannot drift from the tokens |
| Templates and components | Visual comparison of each template against its Annexure C frame at the defined breakpoints: visual regression suite `tests/visual/visual.spec.js` in the CI quality gates; its baselines are approved against the Figma design once Annexure C is received ([Quality gates](../testing/quality-gates.md#visual-regression-baselines)) |
| Behaviour | Component behaviour (menus, accordions, tabs, carousels) checked against Annexure A descriptions in the end-to-end tests (`tests/e2e/`, Chromium, Firefox and WebKit at three widths) |
| IA | Page tree and menus of each site compared with Annexure B |

## 4. Register

| DEV No. | Date raised | Annexure reference (A token / B template / C frame) | Site(s) and template/component | Design specifies | Implemented / proposed | Reason (criterion in §2) | Evidence (contrast ratio, WCAG SC, screenshot) | CR No. | TMC IT decision (date, ref.) | Status |
|---|---|---|---|---|---|---|---|---|---|---|
| DEV-001 | | | | | | | | | | |

Status values: *Proposed*, *Approved*, *Approved with conditions*, *Rejected – not yet rectified*,
*Rejected – rectified*, *Withdrawn*.

## 5. Current state (before receipt of Annexures)

Annexures A–C have not yet been received (EOI query Q-06). The theme currently uses a provisional
GIGW-compliant token set (navy, teal and saffron palette; Noto Sans for Latin and Devanagari scripts)
and a provisional IA seeded by `scripts/seed-site-structure.php`. These are placeholders, **not
deviations**: they are replaced by the Annexure A tokens and Annexure B IA through migrations and token
updates as soon as the annexures are received, and the register starts from that baseline.
