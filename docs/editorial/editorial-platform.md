# Editorial platform

Page templates, design-conformance rules for editors, network publishing and the living component
library (workstream W5). Requirement IDs refer to [`docs/requirements/RTM.md`](../requirements/RTM.md).

## Page templates (R-4.3-2, R-4.6-1)

When an editor creates a page, the block editor offers seven templates ("starter patterns" for pages):

| Template | Pattern | Sections |
|---|---|---|
| Standard content page | `tmc/page-standard` | Introduction · Page content |
| Section landing page | `tmc/page-landing` | Introduction · Section cards · More information |
| Contact page | `tmc/page-contact` | Introduction · Contact details · Location map · Directions |
| Document listing page | `tmc/page-documents` | Introduction · Documents · Help with documents |
| Service / application page | `tmc/page-service` | Introduction · Before you start · Online service · Help |
| People / leadership page | `tmc/page-people` | Introduction · People |
| FAQ page | `tmc/page-faq` | Introduction · Questions · Contact |

- **Locked sections** (`templateLock: contentOnly`) keep their layout; only text and links can change.
  The server refuses any save by a non-administrator that changes their structure (see below).
- **Flexible areas** accept approved blocks and the TMC component patterns (Person, Question and
  answer, Card, Callout). Every section is named in the List View and cannot be removed or moved.
- Text to replace starts with `[Replace:`. A page that still contains a prompt, or a link to `#`,
  cannot be published or scheduled (the REST API returns `tmc_template_incomplete`).
- Code: `src/themes/tmc/inc/page-templates.php` (`tmc_page_templates()`, `tmc_page_template_blocks()`),
  built with the `tmc_block()` builders. Markup was checked against the WordPress 7.1 block library:
  every block validates and round-trips unchanged.

### Slots for other modules

The contact map, the online-service block and the document list are filled through a filter, so
new pages get the right block without changing the templates:

```php
add_filter( 'tmc_page_template_slot', function ( $blocks, $slot ) {
	return 'map' === $slot ? array( tmc_b_dynamic( 'tmc/location-map' ) ) : $blocks;
}, 10, 2 );
```

Slots: `map`, `application`, `documents`. Every `tmc/*` block is approved automatically.

## Design conformance by construction (R-5-1)

`src/mu-plugins/tmc-core/editorial-governance.php`

| Rule | Content Editor, Reviewer / Publisher | Site Administrator | Super Admin |
|---|---|---|---|
| Blocks in the inserter | approved list (`tmc_approved_blocks()`) | all | all |
| Custom HTML, Classic, shortcode, embed blocks | no | yes | yes |
| Code editor, "Edit as HTML" | no | yes | yes |
| Advanced panel (CSS classes, HTML anchor) | hidden | yes | yes |
| Unlock template sections | no | yes | yes |
| Create reusable patterns | no | yes | yes |
| Unfiltered HTML, Additional CSS | no | no | yes |

- Colours, font sizes and spacing come only from `theme.json` (custom values are switched off).
- Patterns: TMC patterns only; core, wordpress.org and Openverse sources are off; no block installs.
- Enforced on the server for every save by a signed-in user — REST API (block editor) and the other
  web paths (classic form, Quick Edit, bulk edit) — whatever the client:
  - a block outside the approved list is refused (`tmc_block_not_allowed`). Blocks already in the
    page (placed by an administrator or imported by the migration toolkit) do not stop editors
    from changing the text around them; adding more of them is refused;
  - a locked section cannot be removed, moved, unlocked or restructured (`tmc_template_locked`).
    This covers WordPress's "Edit pattern" mode and crafted requests. List items, buttons and the
    text inside a disclosure remain editable, as the editor allows;
  - the publish gate (`tmc_template_incomplete`) described above.
- WP-CLI (operators, seeding, migrations), cron and the network-publishing sync are not user edits
  and are not checked. Autosaves are checked when the editor saves.

## Network publishing (R-4.6-2)

`src/mu-plugins/tmc-core/network-publishing.php`

1. On the TMC site, a news item, notice or event has a **Publish to unit websites** panel. Only TMC
   Reviewer / Publishers, TMC Site Administrators and Super Admins (`tmc_network_publish`) can change
   it; others see it read-only.
2. Each selected site gets a copy of the item **and its translations**, linked as translations,
   filed under the same category, with the same dates and structured fields (e.g. expiry, event
   date and venue). Documents and featured images, which belong to the TMC media library, are not
   copied; links in the text keep pointing at the TMC site.
3. Copies record their origin, show "Published by Tata Memorial Centre" with a link, and set their
   canonical URL to the original.
4. Copies are **read-only** on unit sites for everyone (edit, delete, publish refused; the editor
   explains why and links to the original; the list shows "Synced from … (read-only)").
5. Changes to the original propagate: update, schedule, unpublish or trash (copy becomes a draft),
   permanent deletion (copy deleted). Clearing a site in the panel deletes that site's copy.
6. Audit log events: `network_publish_targets_changed`, `network_copy_created`,
   `network_copy_updated`, `network_copy_unpublished`, `network_copy_deleted`, `network_copy_failed`.

Safe against loops and repeated saves: only the TMC site syndicates, copies never do, a re-entrancy
guard covers each run, and each copy stores a hash of its source so an unchanged save writes nothing.

`tmc-core/polylang-multisite.php` fixes a Polylang behaviour this depends on: without it, writing a
post while switched to another site could rebuild that site's language cache with the wrong front
page and turn its home page into a 404.

## Component library (R-4.3-1, R-3-1, R-7-3 — M3)

`/component-library/` on the TMC site (`page-component-library.php`, `inc/component-library.php`)
renders every token and component live, with the PHP functions, CSS classes, blocks and patterns to
use: colour palette with WCAG contrast ratios, type scale, spacing, buttons, links, forms, tables,
badges, callouts, breadcrumbs, tabs, pagination, news cards, notice board, dated lists, calendar,
details list, document list, FAQ, person, section cards, source note, every page template and the
home sections. Tokens are read from `theme.json` and `main.css`, so replacing them with TMC's
Annexure A tokens updates the library and all six sites together.

The page is `noindex, nofollow` (meta tag and `X-Robots-Tag`) and is left out of the XML sitemap,
the HTML sitemap and site search.

## Audience entry points (R-1-1)

"For referring doctors" (under Patient Care) and "Students & researchers" (under Education) on every
site, in the main menu and footer quick links. They link to existing pages and state plainly which
information TMC still has to provide. English first; Hindi pages follow with TMC's content.
Created by migration `050-editorial-platform` on existing sites and by `seed-site-structure.php` on
new installs (both call `tmc_ensure_editorial_ia()`).

## Tests

- `scripts/tests/editorial-test.php` — templates (registered, offered via REST, locked, approved
  blocks, created by a Content Editor, publish gate), governance (editor vs administrator, REST
  and classic-form refusal, existing blocks tolerated, locked sections: text edits allowed, removal,
  moving, unlocking and restructuring refused, home sections, patterns), network publishing (permissions, panel, copies and translations, read-only,
  update, repeated save, deselection, unpublish, events, deletion, audit, Polylang cache), audience
  menus, component library (noindex, sitemap exclusion, coverage, contrast maths).
- `scripts/smoke.d/editorial.sh` — component library renders and is noindex; audience pages and
  menu links; feature stylesheet.
