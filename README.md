# Launch Builder

**Launch Builder turns a business brief into a finished, conversion-ready website on WordPress and Kadence.**

It is the site-building toolkit LaunchLocal uses for local service businesses: plumbers, HVAC, roofing, auto shops,
IT providers and the like. Instead of rebuilding the same pages by hand for every client, a site starts from a proven
structure, a curated pattern library and one set of business facts, and stays consistent as it grows.

> Launch Builder is in active development and used for our own client builds.

## How it works

Launch Builder sets a site up; Kadence and Meta Box run it. Everything it creates is a native object (Meta Box post
types and settings, a Kadence block header, Kadence Elements, Query Loops, Customizer settings), so a site keeps working
if the plugin is ever removed.

- **Business Info in one place.** Company name, phone, phone link, email, address, maps link and the call to action live
  on a Meta Box settings page. Pages print them through tokens such as `{{lb.phone}}`, so one change updates every page.
- **Services, sub-services and service areas.** Services are a hierarchy at the root of the site
  (`/air-conditioning/installation-and-replacement/`); service areas list their own service pages
  (`/service-areas/rockledge-fl/ac-installation/`). All of them are Meta Box post types.
- **A Design hub with three looks.** The header, four heroes, the breadcrumb bar, the sub-service and area lists, the
  before-footer call to action, the footer and the sidebar, each in an Auto shop, Home services and Landscaping look.
  Choosing one writes the Kadence object; a whole look also sets the colours, buttons (Kadence Style Library) and layouts.
- **One short brief in, a site out.** Business, look, colours, services, areas and pages: build-site checks it, shows
  what it would do, builds it, and running it again changes nothing.
- **Built for working with an AI assistant.** A secure MCP connection lets an assistant plan, build and maintain the
  site through conversation, with every change logged and undoable.
- **Optional modules, off until you want them.** URLs and redirects, safe duplication, media hygiene, local avatars,
  login links and client branding each switch on and off on their own.

## Built on Kadence

Launch Builder is made for the Kadence theme, Kadence Blocks and Kadence Blocks Pro (Query Loop), Kadence Theme Kit Pro
(Elements) and Meta Box AIO. It sits alongside Rank Math SEO and Perfmatters.

## Code

The plugin's code lives in `includes/`, in a folder per part of the plugin. [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
explains how the parts fit together, what must not change and how to check a change.

## Updates

Launch Builder updates through the normal WordPress plugin update screens. New versions are published as releases in
this repository.

## License

Launch Builder is free software, released under the [GNU General Public License v2.0 or later](LICENSE).

## About

Launch Builder is developed by [LaunchLocal Digital Marketing](https://golaunchlocal.com).
