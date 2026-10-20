# Launch Builder

**Launch Builder turns a business brief into a finished, conversion-ready website on WordPress and Kadence.**

It is the site-building toolkit LaunchLocal uses for local service businesses: plumbers, HVAC, roofing, auto shops,
IT providers and the like. Instead of rebuilding the same pages by hand for every client, a site starts from a proven
structure, a curated pattern library and one set of business facts, and stays consistent as it grows.

> Launch Builder is in active development. Version 0.10 is an early release for our own client builds.

## Why it exists

Most local business sites repeat the same things on every page: the phone number, the service list, the hero, the
breadcrumb trail, the call to action. When those are copied by hand, they drift. Launch Builder keeps each of them in
one place and lets every page follow.

- **One source of truth.** Business name, phone, address, hours, licences and calls to action live in Business Info.
  Change a value once and every page, header and footer updates.
- **Services and service areas that maintain themselves.** Services and the cities you serve are real content types.
  Every list, card grid and link section on the site is live, so adding a service or a new city shows up everywhere
  it should.
- **A consistent page structure.** Service pages, service-area pages, category pages and standard pages each get the
  right hero and breadcrumb trail automatically, placed once through Kadence Elements rather than pasted onto every page.
- **A design system, not a pile of layouts.** A library of premade Kadence sections (heroes, service grids, reviews,
  calls to action, maps, FAQs, footers and headers) shares one palette, one button style and one spacing rhythm, so a
  site can be restyled from a single place.
- **A dashboard that shows where a site stands.** A launch runway (business facts, AI assistant, design, content, site
  check, live), the optional modules as switches that save as you click, recent activity and the design in use, with a
  Design hub that shows the header, hero, breadcrumbs, footer and call bar of every page type, live.
- **Edit services and areas in Meta Box.** On a site with Meta Box, Services, Service areas and Service categories can be
  listed under Meta Box's Post Types and Taxonomies and the card fields under Custom Fields, in one click from those
  screens. Labels, menus, icons and URL bases are then edited in Meta Box while every list and URL keeps working.
- **Optional modules, off until you want them.** URLs and redirects, safe duplication, media hygiene, local avatars, login
  links for client accounts, Kadence Conversions popups and a client-friendly Branding module each switch on and off on
  their own, and a module that is off loads nothing.
- **One brief in, a whole site out.** A brief can describe the business, brand, services, areas, pages, menus and SEO; the
  builder checks it, shows what it would change, builds in bounded steps and can export the site back as a brief.
- **Built for working with an AI assistant.** Launch Builder includes a secure connection for MCP-compatible AI
  assistants, so a full site, from brand colours and Business Info to pages, services and menus, can be planned, built
  and maintained through conversation, with every change logged and undoable.

## Built on Kadence

Launch Builder is made for the Kadence theme and Kadence Blocks, and uses Kadence Pro features such as Elements when
they are available. It sits comfortably alongside the rest of a typical professional WordPress stack, including Rank
Math SEO, Meta Box, Perfmatters and Kadence Conversions.

## Code

The plugin's code lives in `includes/`, in a folder per part of the plugin. [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
explains how the parts fit together, what must not change and how to check a refactor.

## Updates

Launch Builder updates through the normal WordPress plugin update screens. New versions are published as releases in
this repository.

## License

Launch Builder is free software, released under the [GNU General Public License v2.0 or later](LICENSE).

## About

Launch Builder is developed by [LaunchLocal Digital Marketing](https://golaunchlocal.com).
