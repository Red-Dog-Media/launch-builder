# Changelog

All notable changes to Launch Builder are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/Red-Dog-Media/launch-builder/compare/v0.10.0...HEAD
[0.10.0]: https://github.com/Red-Dog-Media/launch-builder/compare/v0.9.0...v0.10.0
[0.9.0]: https://github.com/Red-Dog-Media/launch-builder/releases/tag/v0.9.0
