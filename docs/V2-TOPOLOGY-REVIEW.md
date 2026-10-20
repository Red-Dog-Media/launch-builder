# V2 topology review — 2026-10-20

Applied the linked Topology Navigator approach to the changed design, list, navigation and removal paths. This is a review by the implementing agent, not independent approval.

## Anchors and bridges

| Entry point / anchor | Bridge and stored state | Contract to verify |
| --- | --- | --- |
| `Builder::build` | Meta Box sync → Business Info → entry/page posts → menu → `Design_Hub::set` | Repeated builds preserve content; editor changes are retained. |
| `Design_Hub_Admin::handle` / `Design_Hub::set` | Pattern → native header, Element or sidebar; ledger rows and placement option | Permissions and nonce precede admin writes; native objects retain the intended display rules. |
| `Query_Lists::materialize` | List placeholder → query post → card post | References must not recurse into their own posts; query/card editor ownership needs review as a separate bridge from Element ownership. |
| `Query_Lists::query_vars` | Kadence query metadata + viewed-page context → WordPress query arguments | Automatic current-post exclusions may be removed for top-level lists; explicit editor exclusions must remain. REST-style context must match front-end context. |
| `Nav::set_navigation` / `set_nav_id` | Header reference → shared navigation post → responsive metadata | Mobile orientation belongs to the navigation post; saved editor orientation choices survive. |
| `Design_Hub::render_crumbs` | Registered fallback block → namespaced Kadence template tags | The namespace must match the real theme entry point; the callback disappears when Launch Builder is removed. |
| `Launch::prepare_removal` | Meta Box registration + batched token baking + URL map + standalone query filter | Persistence of database rows is distinct from unchanged rendering after deactivation. Incomplete batches and runtime dependencies must remain visible. |

## Confirmed additional finding and fix

The initial top-level-list fix stripped the viewed post from `post__not_in` even when Kadence's saved `exclude` setting explicitly named that post. Kadence combines its automatic exclusion and editor exclusions before calling Launch Builder's filter, so distinguishing those inputs is necessary.

A regression using a string ID in the saved `exclude` metadata reproduced the failure. The runtime filter and removal snippet now normalize those IDs and preserve explicit exclusions. The seeded test matrix expanded from 28 to 56 cases and passes. Unrelated excluded IDs also remain excluded.

## Open review and acceptance boundaries

- Query/card ownership was confirmed broken by a failing regression, then fixed with per-post ownership records, conservative handling of missing baselines, and dependency snapshots. Sidebar widget edits are protected too. Thirty-six ownership/undo checks now pass.
- Removal now exports the required runtime replacement. Sidebar Business Info baking was also missing; its dry-run and undo path is covered by four checks. Eight pages pass after deactivation and again after uninstall cleanup, with the exported companion installed. Custom contextual tokens and external redirect installation still need explicit handling.
- Browser interaction, editor block validation, visual distinction between looks, and actual mobile layout remain unapproved.
- GitHub's integration still lacks write access; this review and its fixes are local.

## Agent-browser feasibility

Read the user-linked skill page and loaded the installed CLI's matching `core` guide. Agent-browser 0.27.0 was acquired through a workspace-local npm cache. Its offline quick diagnostic found Chrome. An HTTP-only launch against the disposable WordPress site failed before navigation: Chrome exited with code -36863 because its crash server could not launch (`TransactNamedPipe`, 0x6D). The full offline diagnostic reproduced that launch failure.

No browser assertions or screenshots passed. No local-file policy was bypassed, security flags were not relaxed, and no cloud tunnel was created.

References: [Topology Navigator guidance](https://gist.githubusercontent.com/acidgreenservers/001185d63e5cd65f9fbe6f7a1c70a200/raw/a61644a35607b69cc4ca0b19a7673804ceee4cae/AGENT.md), [agent-browser skill](https://www.skills.sh/vercel-labs/agent-browser/agent-browser).
