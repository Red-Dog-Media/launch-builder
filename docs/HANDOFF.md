# Handoff: where v2 stands and what is left

Branch `v2-simplify`. Read docs/V2-PLAN.md (the plan), docs/ARCHITECTURE.md (how it fits together) and
docs/PATTERN-SPEC.md (the contract the 36 design patterns were written to).

## Done and verified (on a WordPress 7.1 test site: Kadence 1.5.2, Kadence Blocks 3.8.0.1 + Pro 2.8.20.1, Theme Kit Pro 1.2.6, Meta Box AIO 2.1.0, all unlicensed)

- Old parts removed; plugin boots (30 modules); every changed file lints (PHP 7.4 compatibility: only the known `trim()` advisory).
- Services are hierarchical at root URLs (`/repairs/leak-repair/`), old `/services/...` URLs 301; old service categories migrate into parent services; area service pages (`/service-areas/rockledge-fl/installation-and-replacement/`) work.
- Business Info (8 fields) is a Meta Box settings page; `sync-meta-box` puts both post types, three field groups and that settings page into Meta Box (works with the Builder 4.x in Meta Box AIO).
- `build-site`: dry run, real build, second run changes nothing (fingerprint test), edited text is kept.
- Header piece placed from a pattern renders with the live two-level Services menu and tokens resolved.
- All admin screens render without PHP notices; 39 audit checks run.
- Style Library: `Design_Hub::style_library()` saves and activates a set (`lb-home-services`) after the class-name fix (no leading backslash in container lookups).

## OPEN, fix first: HTTP 500 with all pieces placed

On a copy of the build database, `set-design {"look":"home-services"}` places all 12 pieces fine, but every front-end page then
dies with `Maximum execution time of 30 seconds exceeded` inside `wp-includes/class-wp-block-parser*.php`. With only the
header placed, pages are fine (200). So one of the Elements, lists or the sidebar loops. Suspects, in order:

1. `Query_Lists::render_placeholder` / `materialize` / the list Elements (`subservices`, `areas`, `area-services`): a Query Loop whose
   card renders a list again, or `kadence/query` placed by reference inside an Element that the query's own card also prints.
2. `Query_Lists::element_display` (filter `kadence_element_display`) calling `get_posts` per Element on every page.
3. The `cta` Element (testimonials) or `footer` Element containing a `kadence/query` (footer/sidebar services list) that renders itself.
4. `Design_Hub::crumbs_block()` registering `rank-math/breadcrumb` and Kadence's trail filter recursing.

Bisect: `tools/dev/run.sh tools/dev/tool.php set-design '{"pieces":{"<piece>":"<look>"}}'` one piece at a time on a scratch DB, load a page
after each (`tools/dev/fetch.sh /air-conditioning/`), and read `wp-content/debug.log`.

## Left to do

1. The 500 above; then re-run `tools/dev/check_designs.py` (one H1 per page, breadcrumbs everywhere but home, lists show the right entries, no `{{tokens}}` left) for each look, and screenshot with `tools/dev/shots.mjs` at 1440 and 390 px.
2. The 36 patterns (`patterns/{piece}-{look}.php`) were written by helpers and only linted by me; each helper was asked to render-test its own (spec section "Testing") but their reports were not read. Review visually: the three looks should differ clearly, not be recolours. Check list cards (`kadence/query` with `lbList`) render in the Design hub previews.
3. Design hub screen (`Design_Hub_Admin`) renders but was not clicked through in a browser: previews are scaled iframes of `?lb_preview=<slug>`; check them, the "Use this look" and per-piece buttons, and the placement (top/middle/bottom) selector.
4. `Design_Hub::apply_layouts`: confirm the Customizer keys (`page_title_layout` "above", `*_layout` "right", `*_sidebar_id`) behave on the test site.
5. Rank Math is not installed on the test site; the breadcrumb bar's fallback (Kadence trail through a registered `rank-math/breadcrumb` block) is untested.
6. `prepare-removal` and `Query_Lists::snippet()` are written but untested; test removing the plugin on a copy.
7. Surface-snapshot diff (tools/surface-snapshot.php) was not re-baselined for v2.
8. Release: bump the version (tools/release.mjs), changelog is already in `[Unreleased]`; open a PR from `v2-simplify` when ready.

## Gotchas learned

- Container lookups into Kadence Blocks (`kadence_blocks()->get( Foo::class )`) need the class name WITHOUT a leading backslash.
- Meta Box AIO ships Meta Box Builder 4.x: field groups save via `MBBParser\Parsers\MetaBox` (no `MBB\RestApi\Save::parse`).
- Kadence Elements hook values are in Theme Kit Pro `dist/elements/elements-init.php` (`replace_hero_header`, `kadence_hero_header`,
  `kadence_before_footer`, `replace_footer`, `kadence_single_before/after_entry_content`, `kadence_inside_the_content_after_p2`).
- Kadence Query Loop (Adv): the page being viewed is passed in REST as `{ql_id}_query_exclude_post_id`; our filter
  `kadence_blocks_pro_query_loop_query_vars` reads it.
- Style Library: tokens are DTCG JSON in table `kb_design_tokens`; active library slug is option `kadence_blocks_design_tokens_active_library`.
- The sample site's "Home" page was a draft in the seed data (so `/` 404s there); publish it first.

## Dev tools (tools/dev, copied from the session scratchpad; paths inside need adjusting)

- `run.sh <script.php> [args]`: boot WordPress as user 1 and include a script (needs a WordPress test site with the SQLite integration plugin; the original ran at `127.0.0.1:8899` with `LBT_DB=<file>.sqlite` selecting a database copy via a one-line change to `DB_FILE` in wp-config.php).
- `tool.php <tool-name> '<json>'`: call a Launch Builder tool (`wp_get_ability( 'launch-builder/<name>' )`).
- `build.php <brief.json> [apply]`, `brief.json` (sample brief), `migrate.php`, `audit.php`, `admin_pages.php`, `fp.php` (post fingerprint, for idempotency checks).
- `serve.sh <db>` / `router.php` / `fetch.sh` / `check_designs.py` / `shots.mjs`: dev server, page fetch, per-page design check, Playwright screenshots.
- `refscan.php <plugin dir>`: lists `Class::member` references that no longer resolve (useful after removing classes).
- `php tools/classmap.php [--check]` after adding or removing a class.

## Skills / process notes

No Claude skills were created in this session. The process that worked: map anchors and bridges first (docs/ARCHITECTURE.md), cut
in one pass with `refscan.php` as the checklist, test on a scratch database copy, run a build twice and compare fingerprints.
