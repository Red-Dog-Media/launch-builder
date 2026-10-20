# Launch Builder v2: the simplification plan

The aim is to simplify everything: fewer moving parts, fewer choices, and a site that keeps working if Launch Builder is
removed. This plan maps what we have, what we keep, what goes and what replaces it. It was drawn from a read of every
part of the plugin and of Kadence Blocks 3.8.0.1, Kadence Blocks Pro 2.8.20.1, Theme Kit Pro 1.2.6, the Kadence theme
1.5.2 and Meta Box AIO 2.1.0.

## Status

All four phases are built on branch `v2-simplify` and tested on WordPress with Kadence 1.5, Kadence Blocks 3.8 + Pro
2.8, Theme Kit Pro 1.2 and Meta Box AIO 2.1 (all running unlicensed). Two choices made along the way:

- Area service pages use the service's slug (`/service-areas/rockledge-fl/installation-and-replacement/`); give the
  service a shorter slug (`ac-installation`) for shorter URLs.
- Lists show as a grid of cards (checklist, cards or compact links per look). The same list can be moved above, in
  the middle of or below the page text from the Design hub.

## Should we take a step back?

Yes, for the core, not for everything. Launch Builder runs its own version of things Kadence and Meta Box now do
natively: its own header placement next to Kadence's, its own list block next to Kadence's Query Loop, its own style
layer (Style DNA) next to Kadence's new Style Library, its own page-type system for placing designs next to Kadence
Elements' display rules. Each of those is a second path to maintain, and each is why a simple change ("services can
have sub-services") touches fifteen places today.

So v2 rebuilds the **core** (content model, designs, builder) on native Kadence and Meta Box, and **keeps** what already
works on its own: the AI tools and connection, undo and snapshots, URLs and redirects, and the optional modules
(duplicate, media hygiene, local avatars, login links, branding).

## The rule

**Launch Builder sets the site up. Kadence and Meta Box run it.** Everything Launch Builder creates is a native object:

| What | Lives in | Survives removing Launch Builder |
| --- | --- | --- |
| Services, service areas | Meta Box post types | yes |
| Their fields (icon, the service an area page is about) | Meta Box field groups | yes |
| Business Info | a Meta Box settings page | yes |
| Header | a Kadence block header (Customizer "Enable Block Header") | yes |
| Heroes, breadcrumb bar, sub-service and area lists, before-footer CTA, footer | Kadence Elements with Show On rules | yes |
| Lists of services and areas | Kadence Query Loop (Adv) | yes, with a 10-line snippet (below) |
| Colours, type, spacing, buttons | Kadence Style Library | yes |
| Page layouts and sidebar | Customizer, per post type, plus block widgets | yes |

Three things need a hand-off when the plugin is removed. A "Prepare to remove Launch Builder" tool does them:

1. **Tokens** such as `{{lb.phone}}` are written into the content as their values (this exists today as detokenize).
2. **Root URLs for services** (`/air-conditioning/...`) are a Launch Builder feature; WordPress alone would serve them
   under `/services/`. The tool writes 301 redirects (Rank Math) or hands over the URL snippet.
3. **"Children of this page" lists**: Kadence's Query Loop has no "children of the current post" setting; Kadence's own
   documented fix is a filter. The tool hands over that filter as a snippet.

## Content model

| Type | URL | Notes |
| --- | --- | --- |
| Service (parent) | `/air-conditioning/` | Hierarchical services replace service categories. |
| Sub-service | `/air-conditioning/installation-and-replacement/` | A child of its service. |
| Service area | `/service-areas/rockledge-fl/` | Hub page `/service-areas/` lists them. |
| Area service page | `/service-areas/rockledge-fl/ac-installation/` | A child of the area, with a field naming the service it is about. Title "AC Installation in Rockledge, FL". |

- Service categories go away. Moving an existing site: each category becomes a parent service (its landing page's
  text becomes the service's text) and its services become its sub-services.
- "Services offered in an area" is simply the area's child pages, so the separate services-offered field goes away.
- All of it is created in Meta Box (types, field groups, the Business Info settings page). Without Meta Box, Launch
  Builder still registers the two types itself so no content ever disappears.

## Business Info

Eight values, on a Meta Box settings page, each with a token:

| Field | Token | Note |
| --- | --- | --- |
| Company name | `{{lb.name}}` | |
| Phone | `{{lb.phone}}` | |
| Phone link | `{{lb.phone_link}}` / `#lb-phone_link` | `tel:` built from the phone unless set |
| Email | `{{lb.email}}` | |
| Address | `{{lb.address}}` | one line |
| Maps link | `{{lb.maps_url}}` / `#lb-maps_url` | Google Maps search for the address unless set |
| Call to action | `{{lb.cta_label}}` | button text |
| Call to action link | `{{lb.cta_url}}` / `#lb-cta_url` | |

Everything else (hours, socials, licences, reviews and the 40-odd other fields) is dropped from the screen and from
tokens. Stored values are left in the database untouched.

## Designs: each piece in three looks

Three looks: **Auto shop**, **Home services** (the basic service-industry look in the screenshots) and **Landscaping**.
Each piece comes in all three, and any piece can use any look.

| Piece | Native object | Where it shows |
| --- | --- | --- |
| Header | Kadence block header + navigation | every page |
| Home hero | Element, Template: replace the title area | front page |
| Service hero | same | single services and sub-services |
| Area hero | same | single areas and area service pages |
| Standard page hero | same | pages (not the front page) |
| Breadcrumb bar (one design) | Element after the hero; Rank Math's breadcrumbs (the theme's own without Rank Math) | every page but the front page |
| Sub-service list | Element with a Query Loop (Adv): children of this service | services that have sub-services |
| Area list | Query Loop (Adv): top-level areas | the `/service-areas/` page |
| Area services list | Element with a Query Loop (Adv): children of this area | areas |
| Before-footer CTA (testimonials + call to action) | Element before the footer | every page |
| Footer | Element, replace the footer | every page |
| Sidebar sections (contact card, call to action, services list) | block widgets in the theme sidebar | pages, services, areas |
| Look (colours, type, spacing, buttons) | a Style Library set per look | site-wide |

Lists can be placed above, in the middle of or below the content: each list Element has a placement setting, and the
same list can also be dropped into a page as a block. List styles: checklist grid (as in the screenshots), cards and
compact links; Kadence's Query Loop has no accordion or dropdown, so those two are left out.

Layouts stay in the Customizer: pages, services and areas get the sidebar layout, the front page and conversion pages
full width. The title area is the hero.

**The Design hub becomes one screen**: one row per piece, three thumbnails, the current choice ticked. Choosing one
writes the native object (or updates it if nobody changed it in Kadence since; a changed one is kept unless you say
replace). About 35 patterns replace today's 126.

## The builder

A brief becomes six short sections:

```json
{
  "business": { "name": "", "phone": "", "email": "", "address": "", "cta_label": "", "cta_url": "" },
  "look": "home-services",
  "colors": { "primary": "#2a46c8", "accent": "#18b6b0", "dark": "#0f1c4d" },
  "services": [ { "title": "Air Conditioning", "summary": "", "icon": "", "text": "", "children": [ { "title": "Installation and Replacement" } ] } ],
  "areas": [ { "title": "Rockledge, FL", "summary": "", "services": [ "installation-and-replacement", "repair" ] } ],
  "pages": [ { "title": "About", "text": "" }, { "title": "Contact", "type": "contact" } ]
}
```

Steps, in order: Meta Box setup, Business Info, look, services, areas and their service pages, pages, menus, layouts.
Dry run first, idempotent (running the same brief twice changes nothing), never overwrites what someone edited, one
undo. The SEO, legal, media, migration, branding and conversions sections of today's brief go.

## What goes

| Part | Lines today | Replaced by |
| --- | --- | --- |
| Rating widget: block, class, 12 patterns, platform logos, review fields | ~1,000 | nothing |
| Page chrome system (Chrome, Globals, Chrome_Admin) | ~3,500 | Elements written once, with Show On rules |
| Design hub | ~2,600 | one-screen picker (~400) |
| Style DNA (Style, dna.css) | ~2,200 | Kadence Style Library |
| Launch list blocks (entries, menu-links, post-body) and their layouts | ~1,500 | Kadence Query Loop (Adv) |
| Page roles | ~800 | post type + Elements rules |
| Service categories everywhere | spread | parent services |
| Builder (Brief, Brief_Validate, Brief_Build, Brief_Export, Blueprint) | ~6,100 | builder v2 (~1,200) |
| Patterns | 126 files | ~35 |
| Kadence Conversions module | ~1,800 | nothing (not in the stack) |
| Business Info beyond eight fields | ~2,000 | nothing |

## What stays

Core plumbing (modules, migrations, ledger, locks, snapshots, cache, updater), the AI tools and MCP connection, Meta Box
sync (extended to field groups and the settings page), URLs and redirects (they give services their root URLs),
duplicate, media hygiene, local avatars, login links, branding, the site check and go-live (trimmed to the new model),
and the Rank Math settings Launch Builder owns (trimmed: sitemap and schema for services and areas).

## Phases

Each phase leaves a working plugin, tested on WordPress 7.1 with the real Kadence and Meta Box stack.

1. **Content and Business Info.** Hierarchical services at root URLs, area service pages, categories moved into parent
   services, the eight-field Business Info on a Meta Box settings page, rating widget removed.
2. **Designs.** The pieces in three looks as native Kadence objects, Style Library sets, the one-screen Design hub;
   the chrome system, Style DNA, old patterns and Launch list blocks removed.
3. **Builder.** Builder v2 on the six-section brief; the old builder removed.
4. **Hand-off.** Prepare to remove Launch Builder; export and import of a site's setup with Meta Box.

## Assumptions (say if any is wrong)

- Sites built with 0.12 are moved over: their data is migrated (categories, Business Info), the designs are re-applied
  from the new set, and the site check lists any old Launch blocks left inside page content to fix by hand.
- Kadence Conversions is dropped (it is not in your stack).
- Rank Math stays the SEO plugin.
- The optional modules (duplicate, media hygiene, local avatars, login links, branding) stay as they are.
