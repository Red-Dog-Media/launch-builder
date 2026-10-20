# Launch Builder v2 design patterns: the contract

You write block patterns for the WordPress plugin at `/home/user/launch-builder` (git repo, branch `v2-simplify`).
Write ONLY the files named below in `/home/user/launch-builder/patterns/`. Never edit any other file in the repo, never
commit, never push.

## What these patterns are

The plugin's "Design hub" offers each design PIECE in three LOOKS. Choosing one writes a native Kadence object from
the pattern (a Kadence block header, a Kadence Element, block widgets). The site uses the Kadence theme, Kadence Blocks
3.8 + Kadence Blocks Pro 2.8 (Query Loop Adv), Kadence Theme Kit Pro (Elements) and Meta Box.

Content model: services (post type `lb_service`, hierarchical: a service can have sub-services) and service areas
(post type `lb_area`, hierarchical: an area's child pages are its "area service pages", e.g. "AC Installation in
Rockledge, FL"). Pages are normal pages.

## Looks (slug: character)

- `auto-shop` (Auto shop): bold, industrial, high contrast. Dark charcoal bands (palette3 backgrounds with palette9
  text), red/orange action colour (palette1/palette2), uppercase headings with heavy weight (800-900), letter-spacing on
  small labels, square or barely rounded corners (0-4px), strong buttons. Feels like a performance garage / repair shop.
- `home-services` (Home services, the basic service-industry look): clean and trustworthy. White and light-tint
  sections (palette9, palette7/palette8), a confident blue action colour (palette1), navy headings (palette3), rounded
  corners (8-12px), soft shadows, check-mark lists. Think HVAC / plumbing / electrician sites.
- `landscaping` (Landscaping): natural, airy, organic. Lots of white space, green action colour (palette1), warm gold
  accent (palette2), deep forest headings (palette3), very rounded shapes (16-24px, pill buttons), softer weights
  (600-700), sentence-case headings, light green tints (palette7/palette8).

Colours: ONLY Kadence palette slugs `palette1`..`palette9` (palette1 action, palette2 accent, palette3 darkest
headings/dark bands, palette4 body text, palette5-6 muted text, palette7-8 light tints, palette9 white). Never hex.
Never set font families. Buttons: Kadence `kadence/singlebtn` with `"inheritStyles":"inherit"` (Theme Base) or
`"inheritStyles":"secondary"`/outline variants (they follow the site's button settings); you may set a radius per look.
Rows: `kadence/rowlayout` with `"inheritMaxWidth":true` and `"align":"full"` for bands.

## Pieces: 12 per look, one file each = `patterns/{piece}-{look}.php`

| piece | what it is | where Launch Builder places it |
| --- | --- | --- |
| `header` | site header: logo, main menu (`kadence/navigation`), click-to-call phone, CTA button; mobile slide-out | a Kadence block header (`kadence_header` post) |
| `hero-home` | home page hero: big headline (H1), sub line, two buttons (call + CTA), background image placeholder | Element replacing the title area on the front page |
| `hero-service` | hero for services and sub-services: page title as H1 + summary + buttons | Element replacing the title area on `lb_service` pages |
| `hero-area` | hero for service areas and area service pages: page title as H1, a "Serving {{area.name}}" style line, summary, buttons | Element replacing the title area on `lb_area` pages |
| `hero-page` | hero for ordinary pages (About, Contact...): page title as H1, optional summary | Element replacing the title area on pages (not the front page) |
| `breadcrumbs` | a slim breadcrumb bar under the hero | Element right after the hero on every page but the front page |
| `subservices` | "Our {{page.title}} services" heading + a LIST of the sub-services of the service being viewed (checklist grid of linked titles, see the screenshots description below) | Element on service pages (top/middle/bottom of the content) |
| `areas` | the list of service areas for the `/service-areas/` hub page: heading + grid of linked area names (with a map-pin icon) | Element on the areas hub page |
| `area-services` | on an area page: "Services in {{area.name}}" heading + LIST of that area's service pages | Element on area pages |
| `cta` | before-footer band: testimonials (3, `kadence/testimonials`, sample text) + a call to action (headline, phone, CTA button) | Element before the footer on every page |
| `footer` | site footer: logo/name + one-line about, a services column (LIST of top-level services, compact links), contact column (phone, email, address, maps link), bottom bar "© {{site.year}} {{lb.name}}" | Element replacing the footer |
| `sidebar` | content for the theme's right sidebar on pages/services/areas: a contact card (phone, email, CTA button) and a "Our services" LIST (top-level services, compact links) | block widgets in the theme sidebar |

Screenshot description (the user's examples, `home-services` look): the sub-service list is a 3-column grid of light
cards; each card has a round blue check icon and the sub-service name as a link ("Installation and Replacement",
"Repair", "Maintenance"...), with the H2 "Our Air Conditioning Services". The service-areas hub lists towns in a grid of
cards with a pin icon ("Rockledge, FL", "Melbourne, FL"...). An area page lists its services the same way.

## File header (exact keys)

```php
<?php
/**
 * Title: Hero: service (Auto shop)
 * Slug: launch-builder/hero-service-auto-shop
 * Description: One sentence a person choosing designs reads.
 * Categories: launch-builder-heroes, launch-builder
 * Keywords: hero, service, auto
 * Inserter: no
 * Piece: hero-service
 * Look: auto-shop
 */
?>
<!-- block markup -->
```

Categories by piece: header -> `launch-builder-headers`; hero-* -> `launch-builder-heroes`; breadcrumbs ->
`launch-builder-sections`; subservices, areas, area-services -> `launch-builder-lists`; cta -> `launch-builder-cta`;
footer -> `launch-builder-footers`; sidebar -> `launch-builder-sidebars`. Always add `launch-builder` too.
`Inserter: no` for header, the heroes, breadcrumbs, footer and sidebar; leave it out for the others.
The header piece may add `Header-Meta: {json}` (post meta for the kadence_header post, e.g.
`{"_kad_header_background":{"type":"normal","color":"palette9"},"_kad_header_isSticky":"1"}`; copy the shape from the
old header patterns). Title format: `Header (Auto shop)`, `Hero: home (Landscaping)`, `Sub-service list (Home services)`...

## Dynamic content rules

- Page title / summary in heroes for services, areas and pages: use CORE blocks so they work without Launch Builder:
  `<!-- wp:post-title {"level":1,...} /-->` and `<!-- wp:post-excerpt {"moreText":"","excerptLength":30} /-->`
  (style them with `"textColor":"palette9"` etc. and `style.typography` / `fontSize` presets as core allows). The
  Element renders on a singular page, so these get the viewed post.
- Home hero: static editable text (a real-sounding headline for the look's trade, e.g. auto: "Honest Auto Repair,
  Done Right the First Time"); the business name via `{{lb.name}}` where natural.
- Business Info tokens (Launch Builder resolves them at render; keep them exactly):
  text `{{lb.name}}` `{{lb.phone}}` `{{lb.email}}` `{{lb.address}}` `{{lb.cta_label}}` `{{site.year}}`
  links (as an href / button `link` value) `#lb-phone_link` (tel:), `#lb-maps_url`, `#lb-cta_url`.
  Area/service context: `{{area.name}}` (the area being viewed), `{{page.title}}`.
- Breadcrumbs: the trail is the self-closing block `<!-- wp:rank-math/breadcrumb /-->` (Rank Math's block; Launch
  Builder prints Kadence's trail through it when Rank Math is off). Wrap it in a slim row/column; nothing else.
- Images: placeholders via PHP, e.g.
  `<?php echo esc_url( LB_URL . 'assets/placeholders/hero.svg' ); ?>` (files: hero.svg, wide.svg, landscape.svg,
  portrait.svg, square.svg, neutral.svg). Hero backgrounds: rowlayout `bgImg` + overlay, as the old hero patterns do.
- Logo in header/footer: `kadence/identity` with `site-logo` + `site-title` (copy from old header patterns).
- Menu in the header: `<!-- wp:kadence/navigation {"uniqueID":"...","templateKey":"short"} /-->` with NO id (Launch
  Builder puts the main menu's id in).

## LISTS: the kadence/query placeholder (subservices, areas, area-services, footer services column, sidebar services)

Write a `kadence/query` block that carries an `lbList` attribute and holds the CARD TEMPLATE (what one item looks like)
as its inner blocks. Launch Builder turns it into a real Query Loop (Adv) (a `kadence_query` post + a
`kadence_query_card` post) when the design is placed:

```
<!-- wp:kadence/query {"lbList":{"mode":"children","postType":"lb_service","perPage":24,"columns":["3","2","1"],"columnGap":["20","",""],"rowGap":["16","",""]}} -->
<!-- (card template blocks: one item) -->
<!-- /wp:kadence/query -->
```

- `mode`: `children` (children of the page being viewed; on a page without children, its siblings), `top` (top-level
  entries of postType), `area-services` (the service pages of the area being viewed).
  subservices -> `{"mode":"children","postType":"lb_service"}`; areas -> `{"mode":"top","postType":"lb_area"}`;
  area-services -> `{"mode":"area-services","postType":"lb_area"}`; footer/sidebar services -> `{"mode":"top",
  "postType":"lb_service","perPage":8,"columns":["1","1","1"]}`.
- `columns`/`columnGap`/`rowGap`: [desktop, tablet, mobile] strings like Kadence stores them.
- The card template renders once per item with that item as the current post, so use core blocks that read the
  current post: `<!-- wp:post-title {"isLink":true,"level":3,...} /-->` (level 3 in grids; `"level":0` gives a <p>
  for compact link lists), `<!-- wp:post-excerpt /-->`, `<!-- wp:post-featured-image /-->`, inside
  `core/group` (flex row for icon + title) and Kadence blocks for the icon. For the check / pin icon use the Kadence Icon
  block (`kadence/icon` wrapping `kadence/single-icon`, icon names like `fe_check`, `fe_checkCircle`, `fe_mapPin`,
  `fas_check`); look up its exact block markup in Kadence Blocks
  (`/tmp/claude-0/-home-user-launch-builder/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad/premium/kadence-blocks.3.8.0.1/kadence-blocks/`, its `dist/blocks/icon` and
  `dist/blocks/single-icon` block.json + `includes/blocks/class-kadence-blocks-icon-block.php` /
  `class-kadence-blocks-single-icon-block.php`) and verify it renders (see Testing).
- Card styling (background, border, radius, padding) goes on the card template's outer `core/group` (core `style`
  attributes) or a Kadence column; keep it simple and valid.

## Kadence block markup: copy, don't invent

The plugin's previous patterns (tested, valid Kadence markup) are in git: list them with
`git -C /home/user/launch-builder ls-tree --name-only HEAD patterns/` and read one with
`git -C /home/user/launch-builder show HEAD:patterns/hero-service.php`. Useful sources: `header-main.php`,
`header-main-dark.php`, `header-utility.php`, `header-topbar.php` (headers); `hero-photo.php`, `hero-service.php`,
`hero-area.php`, `hero-page.php`, `hero-dark.php`, `hero-split.php` (heroes); `breadcrumb-bar.php`,
`breadcrumb-bar-tint.php`; `services-checklist.php`, `area-columns.php`, `area-pills.php` (lists look);
`testimonials.php`, `testimonials-carousel.php`, `cta.php`, `cta-phone.php`, `checklist-cta.php` (cta);
`footer-dark.php`, `footer-light.php`, `footer-licensing.php`, `footer-contact-bar.php` (footers);
`sidebar-contact.php`, `sidebar-services.php` (sidebar). Old patterns used `launch/*` blocks (`launch/entries`,
`launch/breadcrumbs`, `launch/rating`, `launch/menu-links`) and tokens such as `{{lb.hours}}`, `{{lb.license}}`,
`{{lb.city}}`, `#lb-facebook`: these NO LONGER EXIST; never use them (use the list placeholder, rank-math/breadcrumb and
the tokens above). Old `Roles:`, `Slot:`, `Style:`, `Variant-Of:` header lines are gone too.

Kadence uniqueIDs: every Kadence block needs a `uniqueID` unique across ALL patterns. Use the prefix
`{piece-initials}{look-initial}_` plus a counter, e.g. `hsa_0`, `hsa_1` for hero-service/auto-shop... Make them unique per
file and across files (include the look letter: a = auto-shop, h = home-services, l = landscaping). The class names in
the saved HTML (`kadence-column{uniqueID}`, `kt-adv-heading{uniqueID}`...) must match the uniqueID, exactly as the old
patterns do.

## Quality bar

- Each piece in a look must look like it belongs to that look (colours, radius, weights, spacing), and the three looks
  must be clearly different from each other, not recolours.
- Mobile: rows collapse sensibly (Kadence `tabletLayout`/`mobileLayout`), font sizes have tablet/mobile values.
- Accessibility: one H1 only in heroes (none elsewhere), real link text, sufficient contrast with the look's palette.
- Keep each file reasonably small (no 40 KB attribute dumps; drop the empty `markBorderStyles` noise where Kadence does
  not need it — but only if it still renders identically).

## Testing (required before you finish)

A WordPress test site with the full stack is at
`/tmp/claude-0/-home-user/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad/site`. Run PHP inside it with
`php /tmp/claude-0/-home-user/11232c14-57a0-54ec-aa81-cacca5372bef/scratchpad/bin/run.php <site dir> <your-script.php>`
(boots WordPress as user 1). Use YOUR OWN database copy so you never collide with other workers: copy
`site/wp-content/database/wp.sqlite` to `site/wp-content/database/{look}.sqlite` once and prefix every run with
`LBT_DB={look}.sqlite`. Put your scripts in your own scratch directory (not in the repo, not in the site).

For every file: (1) `php -l`; (2) parse the rendered pattern content with `parse_blocks()` and check every block name is
registered (`WP_Block_Type_Registry`) except `rank-math/breadcrumb` (Rank Math is not installed); (3) `do_blocks()` the
content with a sample `lb_service` / `lb_area` / page as the global post (`$GLOBALS['post'] = get_post(ID); setup_postdata(...)`)
and check: no PHP warnings/notices (WP_DEBUG is on; read `site/wp-content/debug.log`), the H1 count, Kadence CSS is
generated for each uniqueID; (4) for lists, render the CARD TEMPLATE alone once per sample entry the same way and
check the linked title prints. Get pattern content with:
`$p = WP_Block_Patterns_Registry::get_instance(); LaunchBuilder\Plugin::register_patterns(); $p->get_registered('launch-builder/<slug>')['content']`
(delete the transient `lb_pattern_headers` first so new files are picked up).

Finish with a short report: the 12 file names, one line per file on its design, the test results, and anything you
could not verify.
