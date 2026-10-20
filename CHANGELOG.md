# Changelog

All notable changes to Launch Builder are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Meta Box sync: Services, Service areas and Service categories can be listed under Meta Box > Post Types and Taxonomies, and the Card details fields (the icon, and the services offered in each area) under Meta Box > Custom Fields, so they are edited there like the site's other types. A notice on those Meta Box screens adds them in one click; the sync-meta-box tool and `wp launch-builder meta-box-sync` do the same, with a dry run first. Your services, areas, categories and their values stay as they are. Launch Builder keeps only the few settings its lists and URLs need, never overwrites a type or field group changed or made in Meta Box unless asked, and an undo moves its copies to the Trash. Needs MB Custom Post Types and MB Builder (both in Meta Box Lite and Meta Box AIO).
- The site audit warns when one of these types was renamed in Meta Box (its lists would then use a new, empty type).
- docs/ARCHITECTURE.md: how the plugin fits together, what must not change and how to check a refactor (tools/surface-snapshot.php and tools/surface-diff.php).

### Changed
- Services, service areas and service categories that something else registers first (Meta Box, for example) keep that registration instead of being overwritten by Launch Builder, apart from the settings Launch Builder relies on. Saving one of them in Meta Box refreshes the site's URLs on the next page load.
- The code is organised in folders by part of the plugin (core, AI tools, Business Info, content, design, briefs, launch, URLs, integrations, admin, client experience), one class per file. Nothing changes on a site: hooks, tools, settings and stored data are the same.

## [0.12.0] - 2026-10-19

Preview release. This round tightens Business Info, finishes Branding and makes a site built from a brief come out right the first time.

### Added
- Business Info: a Meta Box panel shows the settings pages and fields Meta Box registers, what each one fills and lets you use one as the source for a value (with undo). Typical field names are matched automatically.
- Local avatars: sixteen more ready-made pictures with a picture grid on the profile screen and pictures from the Media Library, and the module is on for new sites and local-only by default.
- Branding: dashboard colours, and a stored colours stylesheet that is checked before it prints.
- Footers that are built from the site's own menus, service list and service areas, with the licence line and no demo links, and a menu-links block for them.
- A neutral placeholder for pictures: once the site is public, a spot with no picture yet shows a soft tinted panel and not a stock icon. While the site is hidden from search engines the placeholder pictures stay, so you can see where photos go.
- A brief can include a branding section: switch Branding on or off, set the dashboard colours, the "Website by" line on the sign-in screen, who built the site and the site owner role.
- Reviews and offers written in a brief fill the testimonial and offers designs wherever they are placed, including the reviews page, and a page that shows them is rebuilt when they change. A review without a name, or an offer with only a title or only a text, is fine.
- A category's summary in a brief is used as the category text, on the category and on its landing page.
- The exit-intent popup takes its wording from the brief: the small line, the headline, the sentence and the button.
- The build report and the go-live report each end with one list of what only a person can do (logo, site icon, real photos, form code, legal review, sample wording still on a page).
- The site audit flags a placeholder form embed, a missing logo or site icon, invented review claims and default sample content.
- Test suites for the first build of a whole site from a brief, Meta Box and the new Business Info screen.

### Changed
- Business Info is down to 27 inputs, everyday fields first (Country and a second office address sit under More options). The name, tagline and site icon are read from WordPress, other profiles live in one box, and an update step moves old values over without losing icons or logos.
- Branding no longer has the plugin-name and Kadence-names sections or the Kadence White Label bridge. Dashboard colours take their place: set them from your brand palette or a ready-made scheme.
- inspect-page no longer checks internal links unless you ask for it (check_links), so a page check is quick.
- Map designs follow Business Info: the map shows the address, the second office address or the service area it sits on.
- The default header keeps everything on one row, and its phone number and button never wrap.
- Contrast and spacing: light-on-dark buttons keep their contrast on hover and focus, testimonial cards read on the dark band, card grids stay centred and the review summary notes that its numbers are computed.
- A site built from a brief is more honest and settles on the second run: sample pages are removed when untouched, a site that is already live (even one that went live earlier) is never asked to hide from search engines again, designs that would carry demo prices or quotes are left out with a reason, and re-running the same brief changes nothing.
- The testimonial and service-area designs carry neutral sentences, so a new page never shows instructions meant for the editor.

### Fixed
- A one-line address is no longer cut down to the city when only the city, state and ZIP boxes are filled.

## [0.11.0] - 2026-10-13

Preview release: the plugin is not in everyday use yet, so expect rough edges and report them.

### Added
- Design hub: every card says which pages a change reaches (with real page titles), a "This will change" line sits next to Apply, and Change design opens a window with live previews of each design before you choose. Breadcrumbs can be limited to the page types you pick, and a page can be given a hero of its own from the editor.
- Elements and synced patterns have outline names ("Launch Builder · Hero · Service pages"), a "Launch Builder" view and a "Used for" column in the Elements list, and a note on each design's edit screen that says what it covers and links to the Design hub.
- Business Info is shorter and friendlier: the everyday fields first, the rest under More options, and values that are not valid are refused with a reason instead of wiping what was saved.
- "Fill Rank Math from Business Info": a preview of exactly which Rank Math Local SEO settings would change, then a fill that never overwrites what you set and can be undone.
- Meta Box fields can be shown with {{meta.field}} tokens (pictures, rich text, embeds, posts and categories included) and only fields Meta Box registers for the page can be read.
- Tokens now also resolve in page titles, classic menu labels and links, legacy text widgets, image alt text and structured data, on the server, so visitors and search engines never see a raw token. {{LB.Phone}} works like {{lb.phone}}. The audit also flags mistyped tokens in titles and SEO fields.
- go-live fetches the home page and one page, service and area, and refuses to launch while unresolved tokens or copy placeholders are printed.
- Rating widget: the real platform logos from their own sources (see assets/logos/LOGOS.txt), and on phones the content is centred inside boxed styles, with an "On phones" setting.
- Duplicate and URLs have the same look as the other Launch Builder screens.

### Changed
- A blank Business Info field prints nothing once the site is public; demo text shows only while search engines are asked to stay away (Settings > Reading).
- Detokenize bakes fallbacks, blank values on a public site and tokens typed into SEO fields.
- Opening Launch Builder screens is faster: the dashboard caches its compatibility facts, update checks never hold up a page, and Site health reads its posts in one query.
- The LaunchLocal mark is now the plugin logo: the admin menu icon, the header of every Launch Builder screen and the icon in update notices.
- Local avatars: people without a photo of their own now get the LaunchLocal mark by default, and ready-made pictures can be chosen for the whole site or for each person.

### Fixed
- Branding: the Save button works again. The sign-in preview no longer cuts the settings form short, and the screen wording is clearer.

## [0.10.0] - 2026-10-12

### Added
- A new Launch Builder dashboard: a launch runway that shows what is done and what comes next, the optional modules as switches that save as you click, the AI assistant connection, the site check, recent activity and the design in use. A dedicated Modules page with search and categories, a compact widget on the WordPress Dashboard, and one refreshed look across every Launch Builder screen (Business Info is now a set of cards with a save bar that stays in view).
- Shared designs per page role: header, notice bar, hero, breadcrumbs, service and area templates, 404 body, before-footer stack, footer and a mobile call bar are placed once, through Kadence Elements or by the plugin, and every page of that role follows. One map to read and edit.
- Breadcrumbs by role with a single BreadcrumbList for search engines, a contained default bar and calmer spacing.
- A dynamic text engine: {{tokens}} for Business Info, site, page, entry, area, service and Meta Box values, with fallbacks. Empty social profiles disappear instead of leaving dead icons.
- Rating widget v2: platform logos, a combined score and review count from Business Info, sizes and styles; blocks saved before keep rendering exactly as they did.
- Style presets: radius, shadow, spacing, type, surfaces, buttons and the hero overlay as CSS variables, with on-dark button states and a contrast guard.
- Live service and area menus: menu items follow the entries, including renamed categories and areas.
- A site audit and page inspection (what a visitor and a search engine would see), preview links and a sync tool, plus module switches for the optional parts.
- Compatibility for Rank Math, Yoast, Kadence, Meta Box and Perfmatters: SEO templates and variables, sitemap and schema settings, safe writes.
- Kadence Conversions items (exit-intent popup, slide-in, banner) kept up to date by key and shown by page role.
- URLs: custom URLs per page, entry and term, URL patterns per type, a lookup table and automatic redirects when a URL changes.
- Media hygiene (off by default): removes GPS location and other metadata from uploads and imports without re-encoding.
- Duplicate: safe copies of pages, posts, services, areas and synced patterns as drafts, from row actions, bulk actions, the admin bar and the editor.
- Local avatars (off by default): profile photos from the Media Library instead of Gravatar.
- Login links (off by default): expiring, revocable one-click sign-in links for client accounts.
- Design hub (Launch Builder > Design hub): the header, notice bar, hero, breadcrumbs, page template, 404 content, before-footer sections, footer and mobile call bar of every page type, shown on a real page at desktop, tablet and phone widths. Each one says whether it is applied and where it lives, links to edit its Kadence Element or synced pattern and to the pattern in the library, and can be switched for another design with one click and undone right after. Kadence Conversions popups and banners are listed beside them. The pattern gallery (search, groups, widths) is the second tab.
- Build a whole site from one brief: `build-from-brief` checks the brief and lists every problem with its path, shows a plan first, then builds in resumable steps that are safe to repeat. Pages and entries that still need copy are created as drafts. `export-brief` writes the site back as a brief.
- `go-live` audits the site, refuses to launch while it reports errors, then makes the site visible to search engines. Rank Math Instant Indexing submissions are held back until then.
- Privacy policy, terms and accessibility statement generated from Business Info, with the text message consent wording business texting requires. They stay drafts until the client has reviewed them.
- `detokenize` bakes Business Info into the content before the plugin is removed. `convert-page-to-entry` turns a page into a service or service area and redirects its old URL.
- Page recipes for thank-you, privacy, terms, accessibility, booking and landing pages.
- Branding (off by default): a branded sign-in screen, a client-friendly Site Owner role with a simplified admin menu, a welcome panel, admin footer and email sender name taken from Business Info, and an opt-in bridge to Kadence White Label. Administrators are never restricted.
- New designs generated for every page type: heroes for pages, services, areas, categories, hubs, conversion and thank-you pages, a mobile call bar, a notice bar, a 404 body, main-row-only headers (light, dark and transparent) and Kadence Conversions designs (exit-intent popup, slide-in and banner).

### Changed
- "Pattern preview" is now the Pattern library tab of the Design hub. Existing links to it keep working.

### Fixed
- Opening hours such as "9am" (without minutes) are understood.
- Rating colours written as rgba() values are kept on current WordPress.
- Adding or re-building service areas no longer creates a second copy of an area that already exists on current WordPress.
- Service areas below a parent are found by their parent on current WordPress, so area lists, menus and templates follow the hierarchy.
- Looking up service areas by slug and path works on WordPress 7.

## [0.9.0] - 2026-10-08

First public draft.

### Added
- A library of about 100 premade Kadence sections: heroes, service and service-area sections, text and media, maps and contact, calls to action, reviews, rating widgets and footers, plus 9 header designs.
- Business Info: one place for the business name, phone, address, hours, licences, buttons and form embed. Patterns use dynamic text, so one change updates every page, header and footer.
- Services and service areas as content types, with live lists, entry templates and breadcrumbs that understand categories and parent areas.
- Design controls for theme buttons, menus and dropdowns, the page title banner, block defaults and the sidebar, with snapshots so changes can be undone.
- Whole-site builds from one blueprint, with shared designs placed through Kadence Elements (Kadence Pro) or synced patterns.
- Page roles: every page knows whether it is a standard, legal, conversion or thank-you page, a hub or a service category, set from the editor sidebar.
- A secure MCP endpoint and REST tools, so an AI assistant or a dashboard can build and maintain the site, with an activity log.
- Updates from GitHub releases through the normal WordPress update screens, including automatic updates and a "Check for updates" link.

[Unreleased]: https://github.com/Red-Dog-Media/launch-builder/compare/v0.12.0...HEAD
[0.12.0]: https://github.com/Red-Dog-Media/launch-builder/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/Red-Dog-Media/launch-builder/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/Red-Dog-Media/launch-builder/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/Red-Dog-Media/launch-builder/releases/tag/v0.9.0
