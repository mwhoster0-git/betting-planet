# Betting Tips 1.3.0

Native WordPress plugin for one manually entered betting tip per post. No dependencies,
external endpoints, fixtures, or statistics dashboard are included.

## Architecture

- `betting-planet-tips.php`: bootstrap and hooks.
- `includes/class-post-type.php`: CPT and activation/deactivation rewrite flushing.
- `includes/class-fields.php`: field schema, league choices, validation, meta registration and native metadata guards.
- `includes/class-leagues.php`: native league taxonomy, dynamic choices, term assignment and legacy migration.
- `includes/class-meta-boxes.php`: editing UI and secure save orchestration.
- `includes/class-settlement.php`: reusable calculation and persistence.
- `includes/class-admin.php`: read-only performance and per-user/per-post validation notices.
- `includes/class-statistics.php`: reusable settled totals and request-local caching.
- `includes/class-shortcodes.php`: calculation handlers and display shortcode routing.
- `includes/class-shortcode-catalog.php`: read-only native Shortcodes taxonomy library.
- `includes/class-import-export.php`: admin JSON export/import for moving tips between sites.
- `includes/class-open-bets.php`, `templates/open-bets.php`, `assets/open-bets.css`, `assets/open-bets.js`: dynamic open-tip card deck based on the supplied HTML mockup.
- `includes/class-performance-board.php`, `templates/performance-board.php`, `assets/performance-board.css`: dynamic settled-tip performance table.
- `tests/integration.php`: CLI integration checks using the local WordPress installation; database changes roll back. Requires InnoDB tables and an administrator. Run only on local/test installations without persistent object caching or external save-hook side effects.

## Metadata contract

All keys are single-value strings, not exposed in REST.

| Key | Storage |
| --- | --- |
| `_bpt_season` | Sanitized text, e.g. `2026/27` |
| `_bpt_match_datetime` | `Y-m-d H:i:s`, WordPress local wall time |
| `_bpt_home_team` | Sanitized text |
| `_bpt_away_team` | Sanitized text |
| `_bpt_bet_selection` | `1`, `0`, `2` |
| `_bpt_stake` | Integer string `1`–`10` |
| `_bpt_odds` | Fixed two-decimal string, greater than `1.00`, maximum `100000.00` |
| `_bpt_match_result` | `pending`, `1`, `0`, `2` |
| `_bpt_bet_status` | Computed `pending`, `won`, `lost` |
| `_bpt_return` | Computed two-decimal units; absent while pending/incomplete |
| `_bpt_profit` | Computed signed two-decimal units; absent while pending/incomplete |
| `_bpt_yield` | Computed signed two-decimal percentage; absent while pending/incomplete |

Title, analysis, image, and author use native WordPress fields. Native revisions cover
title/content; betting metadata is intentionally not revisioned in this release. Restoring
a content revision does not restore historical betting inputs or results.

## Saving and validation

`save_post_betting_tip` checks post type, capabilities, revisions, autosaves, and a
re-entry guard. Submitted betting forms require a post-specific nonce and every field.
Fields may be blank to support incomplete drafts; blank fields delete metadata, except
blank result becomes `pending`. A valid stake can never be below 1 or above 10.
Any invalid field rejects the entire betting field group, retaining previous values and
showing a notice after redirect. WordPress title/content saves are independent.
Calculated fields have no form inputs and submitted calculated values are ignored.

Odds accept strict decimal notation with up to two decimal places: `2`, `2.5`, `2.50`.
Exponent notation, commas, nonfinite values, negatives, zero, odds at/below 1, and
extra fractional digits are rejected. The upper bound keeps integer calculations safe
on 32-bit PHP. Native add/update metadata calls also reject invalid or noncanonical
input values; integrations should call `Fields::validate()` first, use `wp_slash()`
when passing text to `update_post_meta()`, and delete empty metadata explicitly.

Authorized saves without a betting form (e.g. Quick Edit) resettle existing inputs.
Direct metadata updates alone do not trigger settlement: an authorized integration
must call `Settlement::settle()` after all inputs are written, or save the post.
Trusted server code can write calculated metadata through WordPress APIs; it should
instead use the settlement service. No client-editable calculated fields are exposed.

Match time is a local wall time in Settings → General's timezone, not UTC. Changing
the site timezone later changes its interpretation; ambiguous DST times use PHP's
timezone interpretation.

## Leagues

Leagues use the native `bpt_league` taxonomy attached to `betting_tip`. Manage them at
**Betting Tips → Leagues**, or use the Manage leagues link below the edit-screen dropdown.
The dropdown queries all terms, including unused leagues, and keeps the original slug
values. Saving assigns one existing term by ID; blank selection clears the relationship.
Term names and slugs can be edited without losing assignments; deleting a term removes
its assignments and dropdown option. No duplicate taxonomy meta box is displayed.
Standard `manage_categories` permissions control term management; `edit_posts` and
the tip's `edit_post` capability control assignment. No league frontend archive or REST
endpoint is enabled.

On activation or the next admin request, the plugin creates the seven original league
terms once and migrates `_bpt_league` values into taxonomy relationships in batches of
100. Legacy metadata is removed only after successful assignment; existing taxonomy
relationships take precedence. Additional legacy slugs are preserved as terms. Deleted
defaults are not re-created after setup. Migration progress is recorded in WordPress
options and resumes on subsequent admin requests if interrupted.

`_bpt_league` is no longer registered or writable as metadata. The former
`bpt_league_choices` filter is retired: manage terms with native WordPress term APIs.
Integrations can read `Leagues::selected()` / `Leagues::choices()` and assign a slug
using `Leagues::assign()` with an authorized current user. Settlement is unaffected.

## Settlement

Settlement revalidates its required inputs and writes metadata only, never recursively
saving the post. A pending result or missing/invalid selection, stake, or odds sets
status to `pending` and deletes return/profit/yield. Such tips must be excluded from
future settled performance totals. A final result can be corrected or reset to pending;
the next save replaces/clears prior performance. Match date does not gate settlement.

Amounts use integer hundredths with fixed two-decimal string storage, avoiding binary
floating-point rounding. Whole-unit stakes and two-decimal odds need no rounding.

- Win: return = stake × odds; profit = return − stake; yield = profit ÷ stake × 100.
- Loss: return = 0.00; profit = −stake; yield = −100.00%.
- 5 Units at 2.50, selection/result 1: won; return 12.50, profit +7.50, yield +150.00%.
- 5 Units, selection 1/result 2: lost; return 0.00, profit −5.00, yield −100.00%.
- 5 Units at 1.65, winning: return 8.25, profit +3.25, yield +65.00%.

Future aggregates must sum settled stakes and returns: total profit = total returns −
total stakes; overall yield = total profit ÷ total stakes × 100 (undefined with no
settled stake). Never average individual yields. 1000 staked and 1080 returned gives
80 profit and 8% overall yield. No aggregate dashboard is implemented.

## Operational notes

### Shortcodes

Find all eight codes under **Betting Tips → Shortcodes**. This is the native
`bpt_shortcode` taxonomy: eight built-in terms store their copyable code in protected
`_bpt_shortcode` term meta, plus usage descriptions. The library is read-only, has no
tip assignment box, and exposes no frontend taxonomy archive or REST endpoint.
The plugin registers shortcode handlers independently of the library; no stored code
or term description is executed. Installation seeds entries once on activation or the
next admin request.

| Shortcode | Output |
| --- | --- |
| `[bpt_total_tips]` | Number of published tips, including pending/incomplete tips |
| `[bpt_total_wins]` | Number of settled published tips calculated as won |
| `[bpt_total_losses]` | Number of settled published tips calculated as lost |
| `[bpt_yield]` | Total profit / total stakes × 100, e.g. `8.00%` |
| `[bpt_win_ratio]` | Wins / settled tips × 100, e.g. `45.00%` |
| `[bpt_profit]` | Settled profit, e.g. `+7.50 Units` or `-5.00 Units` |
| `[bpt_open_bets]` | Swipeable open-tip card deck |
| `[bpt_performance_board]` | Settled-tip performance table |


All aggregate figures exclude drafts, private, trashed, future, and password-protected
tips. Performance uses only valid settled inputs with a matching stored won/lost
status; return/profit are derived through the settlement engine, without writing data
during rendering. Pending/incomplete/inconsistent tips count toward total tips only.
There is no averaging of individual yields. With no settled bets, percentages display
`0.00%` as a UI convention and profit displays `0.00 Units`.

Integer hundredths are summed before percentage calculation and two-place formatting.
All aggregate shortcodes share a request-local snapshot, invalidated on post cache or
betting metadata changes; there is no persistent statistics cache. Queries run in
batches of 200. External full-page caches still need their normal purge after edits.

Paste codes into a WordPress Shortcode block or Elementor Shortcode widget. Calculation
outputs are escaped text; Open bets display renders the scoped card design. No pages
are edited automatically.

Activate **Betting Tips** through Plugins. The CPT uses standard post capabilities,
the classic WordPress editor, and existing theme templates for public URLs. This
release includes the open-bets shortcode layout. Featured images require theme thumbnail support.
Deactivation retains all tips and meta; no uninstall deletion is provided.

Run checks from the project root: `php wp-content/plugins/betting-planet-tips/tests/integration.php`.

### Import / Export

Use **Betting Tips → Import / Export** to download a JSON file from one site and
upload it to another site running this plugin. The file contains native post title,
content, excerpt, slug, status, dates, author login for reference, betting metadata,
league slug/name, calculated performance metadata, and a featured image URL for
reference. The importer does not sideload media; upload or map featured images
separately if the live site needs them.

Import creates missing league terms, validates every betting input with the same
rules as the edit screen, writes the betting metadata, and runs settlement so status,
return, profit, and yield are recalculated on the destination site. Each row includes
a stable UID. Imported posts store that UID in `_bpt_import_uid`, so importing the
same file again updates the existing destination tips instead of creating duplicates.
Post statuses are preserved for `publish`, `draft`, `pending`, `private`, and
`future`; unknown statuses import as drafts.

### Open bets display

Use `[bpt_open_bets]` in a WordPress Shortcode block or Elementor Shortcode widget.
The default maximum is 20 cards; `[bpt_open_bets limit="10"]` sets a different limit
(1–100). Cards are ordered by match date/time, earliest first. Published tips with a
pending (or missing) result/status are eligible; valid teams, match time, selection,
stake and odds are required. Drafts, private/password-protected tips, settled tips,
and incomplete betting entries are excluded. Past kickoff times are still shown if
the manually entered result remains pending. No eligible tips produces an empty message.

Cards use the native title, league taxonomy, season, site-timezone match date, teams,
selection, stake, odds and a 55-word plain-text excerpt of the native content. Embedded
shortcodes are stripped, not executed. Team circles show generated initials, as team
logo fields do not exist. The design uses the mockup's font families when available
from the host page and system fallbacks otherwise; it does not fetch external fonts.
The mockup's immutable-values claim is replaced by an accurate pending-result note.

The deck supports touch swipe, mouse click-and-drag, previous/next buttons, arrow-key
navigation, reduced motion, unique IDs and independent instances. Vertical touch
scrolling remains enabled. Card height adapts to text; only the active card is exposed
to screen readers. Without JavaScript, cards form a readable vertical list. CSS/JS are
loaded only when the shortcode renders. Mobile retains navigation buttons as an
accessible alternative to touch gestures.

The PHP suite covers query eligibility, escaping, limits, IDs and empty states.
Run `node wp-content/plugins/betting-planet-tips/tests/open-bets-js.cjs` for interaction
checks with DOM stubs, including touch/mouse events and gesture cancellation.

### Performance board

Use `[bpt_performance_board]` to render the settled-tip table. The default maximum is
20 rows; `[bpt_performance_board limit="10"]` sets a different loaded-record limit
(1-100). The board shows about five rows on screen and scrolls for additional rows. Rows are
ordered by match date/time, newest first. Published, non-password-protected tips are
eligible only when they have valid teams, match time, selection, stake, odds, final
result, and a matching settled status. Pending, incomplete, draft, private, password
protected, and inconsistent tips are excluded.

The table displays date, match, tip selection, stake, odds, German result labels,
profit, and yield. Profit and yield are recalculated through the settlement engine
during rendering, then escaped before output. The CSS uses the supplied `.perf-board`
structure and adds horizontal scrolling on narrow screens.
