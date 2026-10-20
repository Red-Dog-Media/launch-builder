# V2 verification follow-up — 2026-10-20

The branch is suitable for continued review, not a release sign-off. No version tag was published.

## Fixed

- Breadcrumb fallback calls the actual namespaced `Kadence\kadence()` function. Checking the global name always failed on Kadence 1.5.2.
- Generated navigation gets vertical tablet/mobile defaults in the navigation post's own metadata. Existing saved orientation choices are preserved.
- Top-level service lists include the viewed service in runtime queries, previews and empty-list checks. The standalone removal snippet has the same behavior.
- All 15 patterns with list placeholders declare `Requires: kadence-blocks-pro`. The other looks already used the corrected core palette slugs and button inheritance values.
- Removal scans Query Loop and card posts as designs, returns the actual `detokenize.remaining` data, and tells users to finish all batches and resolve outstanding content before deactivating.
- Surface snapshots work after removal of `Entries::CAT`; the old taxonomy is explicitly checked as absent.
- Pattern specification examples now use valid button inheritance and core palette names.
- Design reapplication protects edited query posts, card templates and sidebar widgets. Native query/card ownership is recorded and snapshotted; explicit replacement can be undone. Lists from older builds without an ownership baseline are kept until explicitly replaced.
- Removal now bakes sidebar block widgets with an undo snapshot. Its exported `runtime_snippet` includes query behavior, empty-list visibility, the breadcrumb fallback and contextual `page.title`/`area.name` rendering. Install this companion mu-plugin instead of the list-only snippet.

## Executed checks

Disposable WordPress 7.1.3 with PHP 8.3.30 and SQLite integration 3.1.0; Kadence 1.5.2, Kadence Blocks 3.8.0.1, Blocks Pro 2.8.20.1, Theme Kit Pro 1.2.6, and Meta Box AIO 2.1.0. The Pro Elements module was enabled. No production site was used.

The Windows sandbox makes PHP's native `is_readable()` report false on files PHP can open. Only the disposable test runtime used a replacement that probes actual read access with `fopen`; no shipped plugin code or vendor sources were changed for this. External HTTP requests were disabled in that test site's mu-plugin.

- All repository PHP files lint on PHP 7.4.33 and PHP 8.3.30; the generated class map is current (52 classes). These are local checks, not GitHub CI results.
- Existing version-consistency check passes for 0.12.0.
- A seeded build created all 12 pieces. A repeated build produced identical post and Business Info fingerprints.
- Full WordPress template rendering through a CLI front-end harness passed 24 cases: eight page types in each of auto shop, home services and landscaping. Each had one H1; breadcrumbs appeared except on home; expected list entries and the current service in the footer appeared; no unresolved tokens were found. These are server-render checks, not visual checks.
- `tools/dev/query-regression.php` passed 56 seeded query/removal-snippet parity cases, including REST-style viewed-post parameters, explicit editor exclusions, and preservation of unrelated exclusions. The explicit-exclusion case initially failed and was fixed during the topology review.
- All six admin screen callbacks rendered, including Design hub. This does not verify browser interactions.
- `tools/dev/design-ownership.php` passed 36 checks for separate query/card edits on Elements and sidebars, widget edits, explicit replacement and undo. `tools/dev/detokenize-widgets.php` passed four dry-run/bake/undo checks.
- Surface snapshots were compared against original commit `05fccf3` on the same restored database. The fixed snapshot instrumentation was used for both revisions because the original tool references the removed taxonomy constant. The diff contains the expected changed methods/constants and tool description; runtime routes, module registry and hook inventory are unchanged.
- On a database copy, `prepare-removal` ran in dry-run and apply modes, the exported runtime snippet loaded, and Launch Builder was deactivated. Eight native-URL page renders passed. After running uninstall cleanup on the copy, the same eight renders passed again, with one H1, the breadcrumb fallback, contextual text and lists intact. Meta Box still registered both types; six services, five area pages and Business Info remained. MCP was absent, as expected. The exported redirects must still be installed; actual Apache/Rank Math redirect responses were not tested.
- Rank Math 1.0.280 with breadcrumbs enabled passed eight sample-page render checks. This checks the Rank Math function/output path, not every SEO setting or a visual editor interaction.

## Still required before release

- Browser visual review of all looks at 1440 and 390 pixels, block-editor validation, live previews, mobile slide-out interaction, and Design hub buttons/placement controls. The local HTTP server was unreachable from the browser; opening local render files was rejected by browser security policy. No screenshots or visual approval are claimed.
- Browser verification of the exported URL redirects; custom page-dependent tokens beyond the two built-in contextual tokens still require manual resolution before removal.
- Push the branch, run GitHub CI, choose the release version, run the release tool, and complete release review after the remaining checks pass. GitHub writes are currently blocked by the integration's repository access.

The user-suggested agent-browser CLI was also tried for HTTP browser tests. Its full diagnostic reproduced a Chrome crash-server launch failure before navigation, so browser acceptance remains incomplete. See `docs/V2-TOPOLOGY-REVIEW.md` for the mapped contracts and follow-up finding.

## MCP dependency

Launch Builder implements its own MCP server in `includes/ai/class-mcp.php`. A separate WordPress MCP Adapter plugin is not required. `/mcp` accepts a Launch Builder bearer token; `/wp-json/launch-builder/v1/mcp` also supports WordPress Application Password authentication.
