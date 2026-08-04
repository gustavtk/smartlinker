# SmartLinker — session handoff

Paste this into a new chat to pick up where we left off.

---

## What this is

**SmartLinker** — an original WordPress internal-linking plugin, built from scratch.
LinkWhisper (free + premium copies in `~/Downloads/` and `Claude Code/link-whisper free version`)
is used **only as a functional reference** — no code is copied. I declined to strip the
licence from the paid plugin; this is a clean-room build.

- **Plugin:** `/Users/gustavtk/Claude Code/smartlinker` ← the deliverable
- **Current version:** `0.5.7` · **DB version:** `5`
- Bump `SLK_VERSION` in `smartlinker.php` on every CSS/JS change — it cache-busts assets.

## Conventions

- Class prefix `Slk_`; autoloader maps `Slk_Foo_Bar` → `core/Slk/Foo/Bar.php`
- Text domain `smartlinker`; options prefixed `slk_`; constants `SLK_*`
- Tables: `slk_links`, `slk_autolinks`, `slk_clicks`, `slk_url_changes`,
  `slk_target_keywords`, `slk_gsc`, `slk_external`
- Services registered in `core/Slk/Init.php` (each has a `register()`)
- Every page is wrapped by `Slk_Admin::render()` (shell) — templates live in `templates/`

## Features (all built and live-tested)

| Area | Features |
|---|---|
| Suggestions | Outbound, inbound, AI (OpenAI), external-site, taxonomy targets |
| Architecture | Topic clusters — treemap + heatmap, pillar detection, optional AI theming |
| Automation | Auto-linking rules (+ per-rule link counts), target keywords, bulk CSV Link Map |
| Maintenance | Links report, orphaned posts, broken links (404/redirect/timeout split), URL Changer + 301s |
| Data | CSV import/export, Search Console priority report, domain report, click tracking |
| Editor | Compact suggestion rows, block-native + classic insertion |

## UI system

- **Shell:** in-page sidebar (15 sections) + dark header + panel. Native WP submenu hidden
  via `admin_head` CSS — **not** `remove_submenu_page` (that breaks access authorisation).
- **SPA:** the only full page load is opening the plugin. `js/admin.js` intercepts nav +
  form submits, swaps `.slk-main`, uses `history.pushState`. Saves don't scroll — they show
  a bottom-right toast.
- **Brand:** violet→blue gradient. All colour lives in `:root` tokens at the top of
  `css/admin.css` (`--slk-grad-from/-to`, `--slk-primary`, `--slk-tint*`, `--slk-radius-btn: 6px`).
  **Re-theming the whole plugin = change two variables.**
- **Tooltips:** `Slk_Admin::help($tip|array, $align)` → large multi-paragraph panel beside a `?`.
- Font: **Work Sans** everywhere — plugin pages (`.slk-app`, `.slk-panel`) and both editor
  panels (`.slk-metabox`, `.slk-sb`, hoisted scan bar). One Google Fonts request in
  `Slk_Base::admin_assets`, weights 400/500/600/700 plus italic 400, which is exactly what
  the stylesheet declares. Inter was removed, not left loading — nothing references it.
  Note the editor panels need their own rule: they sit outside `.slk-app`/`.slk-panel` and
  would otherwise inherit the WordPress admin font.
- Buttons: gradient, uppercase, bold, 6px radius — except the suggestion panel, which is
  flat blue (see the editor panel section).

## Semantic index (`core/Slk/Embedding.php`, v0.9.0)

One OpenAI embedding per post — the similarity signal behind suggestions. This is what
lets "which grinder should I buy" match "Choosing a Burr Grinder": two pages sharing no
vocabulary at all, which word-overlap scoring can never connect.

- `text-embedding-3-small` at **512 dimensions** (the v3 models accept a `dimensions`
  parameter; shortening costs little accuracy and a lot of storage).
- Stored in **post meta**, base64 of packed float32 — ~2.7 KB per post, no schema
  migration, and WordPress deletes it with the post. `all()` reads every vector in one
  query; per-post meta lookups would be far too slow for hundreds of pairwise comparisons.
- Cached by a content hash of title + body + model. `save_post` clears only that post's
  hash, so editing one post re-embeds one post.
- Built in batches of 50, each batch its own request/redirect, so a large site never hits
  the PHP time limit. Results are mapped back by the API's `index` field, never array order.
- **Graceful fallback per pair:** if either post lacks a vector, that comparison uses
  TF-IDF. A half-built index still works.
- Embedding cosines live in a different band from TF-IDF ones — own constants
  (`FLOOR` 0.25, `GOOD` 0.70). Do not reuse `good_similarity` for them.

Measured on the demo: wanted pairs 0.52–0.60, unwanted 0.20 — a clean gap. The TF-IDF
equivalent had a wanted pair at 0.061 *below* an unwanted one at 0.074, which no threshold
could separate. That gap is the whole reason embeddings are worth the API call.

## How anchors are chosen (v0.8.0) — the core of the plugin

**An anchor must name its destination.** Anchors are derived from the target's own
identity, never from its body vocabulary. Drawing them from body words is what produced
"equipment" → a car-wash page: the word was in that article, but it is not what the
article is *called*.

Four phases, best first (`Slk_Suggestion::pick_anchor`):

1. **exact keyword** — the target's focus keyword, verbatim. Read **live from the SEO
   plugin** (Rank Math → Yoast → AIOSEO → SEOPress), so a newly written post works with
   no import step; falls back to SmartLinker's own Target Keywords. Which wins is the
   `focus_keyword_source` setting, defaulting to the SEO plugin, because that is where
   people actually maintain keywords and a stale imported copy should not override it.
2. **partial keyword** — longest 2+ word run of it
3. **title phrase** — the target's title with weak words trimmed **from the ends, never
   split on**. "How to Make Pour Over Coffee" must yield `pour over coffee`; splitting on
   "over" leaves only fragments. At least one content word must survive, so
   "The Ultimate Guide" yields nothing.
4. **salient word** — one word from the TITLE (never the body), rejected only if more than
   `max_anchor_doc_share` (50%) of posts use it. **Deliberately a share of posts, not an IDF
   floor:** a site's core nouns are frequent by definition, so a rarity threshold blocked
   "grinder" and "espresso" on a coffee site — the very anchors you want. The title
   requirement is what actually keeps anchors honest.

**No usable anchor ⇒ the candidate is dropped before scoring.** Scoring only ever ranks
links that could actually be written.

Confidence = `0.75 × similarity + 0.15 × keyword overlap + 0.10 × same-cluster`, then
multiplied by a tier weight (exact 1.00 → salient 0.70): a weaker anchor needs stronger
topical evidence. Shown as a percentage with a plain-English reason.

**Similarity is scaled against an absolute reference (`good_similarity`), not ranked
against the other candidates.** A percentile always crowns a best candidate, so an article
with nothing related still got a confident-looking top suggestion.

Tunable: `min_confidence` (0.30), `good_similarity` (0.12), `min_relatedness` (0.10),
`min_anchor_idf` (0.5). **These defaults were calibrated on a 20-post demo — validate them
on a real site before trusting them.**

## Suggestion quality (v0.7.2)

Fixed a real complaint: generic anchors pointing at unrelated pages
("equipment" → a car-wash page, "documents" → a life-insurance page). Layered defences,
because no single one is reliable across site sizes:

1. **A one-word anchor must appear in the destination's TITLE.** Corpus-independent, and it
   alone killed all three reported cases. A word merely occurring in the target's *body* is
   not enough — that was the original bug.
2. **Built-in stop-word list** (`Slk_Settings::base_stop_words()`), merged with the user's
   list rather than replacing it, so existing installs benefit without re-saving. Kills
   filler anchors like "our guide" and "before you".
3. **IDF rarity weighting** — real effect only on sites with enough posts (see gotchas).
4. **TF-IDF topic gate** between source and target, and between source and any category.
5. **One link per destination** in both engines — several anchors to the same page is
   cannibalisation, and the AI happily produced it.
6. **The AI now receives a ~180-char excerpt of each candidate**, not just its title; the
   prompt requires the anchor to describe the destination and to return fewer links rather
   than fill a quota.

## Fixing in place (orphans + broken links)

Both reports repair without leaving the page — the editor is for writing, not chores.

- **Orphans → Fix** expands inline, runs the inbound engine, and applying writes the link
  into the SOURCE post via the server-side insert (no editor is open to hold the change,
  so it is live immediately — a sharper action than the editor equivalent).
- **Broken links → Fix** offers three repairs, because a broken link has three honest
  outcomes: repoint it, keep the words but drop the link, or fix the anchor text.
  Suggested replacements come from, in order of confidence: a rename recorded in the URL
  Changer, where a redirect currently lands, a published post with the same slug, then
  posts whose **title** contains the anchor.
- **Title match, never WordPress's `s` search** — `s` reads body content, so every post
  merely mentioning the phrase comes back, including the post being fixed. Generic anchors
  ("here", "this page") are skipped entirely via the weak-word list.
- `rewrite_anchor()` edits whole `<a>` elements through a callback, matching on exact href
  plus anchor text. **Never string-replace a URL** — `?p=99` is a substring of `?p=999`.
  Unit-verified: repointing `?p=99` leaves `?p=999` untouched, remove keeps the words,
  and an anchor-text edit preserves `rel`/`target`.

## Outbound links (`core/Slk/Outbound.php`) — BUILT BUT DORMANT

AI-suggested external links to authoritative sources. Complete and tested, but **no UI
triggers it** — outbound links are sourced manually by choice. To enable: add a button
that posts to the `slk_get_outbound` AJAX action; results already render in the standard
cards.

Why it exists in this shape: the model **invents plausible URLs**, and prompting does not
fix that. So every proposed URL is fetched before display and anything not returning 200 is
dropped and logged. Measured on the demo, 2 of 3 proposals were rejected —
`baristahustle.com/blog/espresso-brewing-guide/` (404, never existed) and a ScienceDirect
page (403, real but unreadable without a subscription). **Expect roughly a third to
survive.** That yield is why the feature was left off, and any future version must keep
the verify step; a version that trusts the model will publish dead links.

## Keyword import (`core/Slk/KeywordImport.php`)

Pulls focus keywords out of Yoast, Rank Math, AIOSEO and SEOPress into Target Keywords.

- **Detected by data, not by active plugin** — the meta rows survive deactivation, and
  someone migrating away from Yoast is exactly who needs this.
- Storage differs per plugin, which is the whole difficulty:
  - Yoast → `_yoast_wpseo_focuskw` (single value)
  - Rank Math → `rank_math_focus_keyword` (**comma-separated**, first is primary)
  - SEOPress → `_seopress_analysis_target_kw` (comma-separated)
  - AIOSEO v4 → its **own table** `{prefix}aioseo_posts`, column `keyphrases` as JSON
    (`focus.keyphrase`); v3 used `_aioseo_keywords` meta, kept as a fallback
- **Primary keyword only.** All four can store extra keyphrases; importing five per post
  buries the one that matters, and Target Keywords drives link-building.
- Preview before commit — nothing is written until the POST. Dedupe is case-insensitive on
  (post_id, keyword), so re-running an import is a no-op.
- Published posts of enabled types only; drafts and blank values are skipped.

## Topic Clusters (`core/Slk/Cluster.php`, `templates/clusters.php`)

- **Clusters come from the site's own categories by default** — the page is useful with no
  API key and no setup. An optional one-call AI pass regroups the same posts into editorial
  themes with TOFU/MOFU/BOFU stages, stored in the `slk_clusters_ai` option (no new table,
  so no DB-version bump). "Use my categories" deletes it and falls back.
- Everything on the page is **measured, never estimated**:
  - *Pillar* = the most-linked-to post in the cluster; no inbound links means no pillar.
  - *Linked up* = share of a cluster's posts that a **sibling in the same cluster** links to.
    That is the "hole" the page is for — posts sitting together without referencing each other.
- `internal_edges()` is keyed **target => sources**, not source => targets. Every caller asks
  "who links to this?", and the other direction turns the health calc into O(posts x links).
- The treemap is a **squarified** layout (`squarify()` in `js/admin.js`). Slice-and-dice
  produces unreadable slivers as soon as one cluster dwarfs the rest, which is the normal case.
- **The renderer must run on `slk:loaded` as well as ready** — `swap()` sets `innerHTML`, so
  inline scripts in a swapped page never execute. Any future canvas/chart page needs the same.
- Tile colours are CSS classes (`--slk-cat-1..8`, `--slk-heat-*`), never literals in JS.
- The taxonomy fallback bucket is called **"No category"**, deliberately not "Uncategorised" —
  WordPress already ships "Uncategorized" and two near-identical tiles looks like a bug.

## Block-editor sidebar (`js/editor-sidebar.js`)

Its own toolbar tab (link icon) — not a panel inside the Post sidebar — so suggestions
are reachable without opening the collapsed meta-box drawer.

- Written against `wp.element.createElement` directly. **No npm/JSX build step**, and
  adding one for a single panel isn't worth the weight. Keep it that way.
- `PluginSidebar` comes from `wp.editor` (WP 6.6+) with a `wp.editPost` fallback.
- Hooked to `enqueue_block_editor_assets`, so it never loads on the classic editor —
  there the meta box remains the only UI.
- **Cards emit the same `.slk-sg*` markup as the meta box**, so both surfaces inherit one
  stylesheet **including the type scale** — 16px title / 13.5px anchor / 13px reason
  everywhere. `.slk-sb` overrides layout only (badge stacks under the title, buttons split
  the width); **do not add font-size rules there**, or the two surfaces start to drift.
- Same Apply Link / Reject / Load More behaviour as the meta box; Reject persists via the
  same `slk_reject_suggestion` endpoint.
- **Insertion is not duplicated.** `window.SlkBridge.insert()` (exported from the bottom
  of `js/admin.js`) owns anchor-building and boundary-safe replacement; the sidebar calls
  it. Never reimplement that logic — a naive replace corrupts URLs and nests anchors.
- AI lock copy comes from `Slk_AI::availability()` via `SLK.ai`, the same source the meta
  box uses, so the two surfaces cannot drift.

## Editor panel (v0.10.2 — card layout)

One card per suggestion, destination first:

```
→ Target Title                            Confidence: 87%
Anchor: "exact phrase"   /path/
Post similarity 0.78, keyword overlap 41%, same topic cluster. Anchor matches target keyword exactly.
sentence with the anchor highlighted
[ Apply Link ]  [ Reject ]
```

- **Both engines produce the identical reason line** via `Slk_Suggestion::explain()`. The
  AI picks the anchor, but "how related are these two posts" is measured the same way
  regardless of who picked it — two different explanations for the same pair would be
  indefensible. The AI's closing sentence is the model's own rationale; the keyword
  engine's is the anchor tier.
- The reason line names all three signals. Cluster wording is **"same topic cluster"** (1.0)
  or **"related cluster"** (0.5, parent/child categories), omitted when unrelated. The last
  sentence names the anchor tier — see `tier_phrase()`.
- The **title never wraps**: it truncates with an ellipsis (full text in the `title`
  attribute). Wrapping pushed the confidence badge out of line and made a column of cards
  hard to scan.
- Shows 3, then **Load More**. **Reject** is stored in the `_slk_rejected` post meta and
  honoured by `for_post()`, so a turned-down link never comes back.
- **Target keywords are edited in the panel** — chips with remove, and
  "✨ Re-extract keywords for this post (1 OpenAI call)". Keywords live here because the strongest anchor
  tier is an exact keyword match: when results look thin, a missing keyword is usually why.
  A chip marked SEO comes live from Rank Math/Yoast/AIOSEO/SEOPress and is managed there.
- **`.slk-suggestion` is a JS hook only** — it still carries the old compact-row layout
  (flex/centre), so the card resets `display`/`align-items`. Don't style through it.
- The panel uses a **flat blue** accent (`--slk-sg-accent`), not the violet gradient. This
  surface is dense, decision-heavy UI and a gradient competes with the confidence colours.
  It is the one place in the plugin that deliberately steps outside the brand gradient.
- Card classes are `.slk-sg*`, **not `.slk-card`** — that name was already taken by the
  dashboard stat card, and reusing it silently centred every card and shrank it to content
  width. Check for an existing class before naming a new one.

## Editor panel (the old compact rows)

One compact row per suggestion, ~70px:
`[✓] sentence with highlighted anchor ✏️ | target title + coloured score / URL ✏️ | ADD`

- Scan buttons are **hoisted by JS into the postbox header** (same line as the meta box title)
- Pencils reveal inline inputs; `×` (only visible while editing) discards that edit
- Score badge is traffic-lit: green ≥75%, amber ≥50%, red below
- Bulk select + "Add selected (N)", header progress bar, shimmer skeletons, staggered row entrance
- `prefers-reduced-motion` disables all animation

## Memory ceiling — chunked content walks (`core/Slk/Post.php`, v0.43.0)

Four places read the body of **every post on the site** in one query with no
LIMIT: `Slk_Placement::build()`, `Slk_Keyword::link_counts()`, and both
`Slk_URLChanger::preview()` and `replace_sitewide()`. On a small site that is
invisible. At a few thousand posts it is a fatal memory error, and the person
hitting it does not experience "the placement report is heavy" — they
experience the plugin being broken, on a page that gives no clue why.

`Slk_Post::walk_content($ids, $callback, $columns, $chunk)` replaces all four.
Ids are collected first and held for the whole walk (100,000 ids is a few
hundred KB of integers — it is the CONTENT that has to be bounded); content is
then fetched one slice at a time and released before the next.

**The object cache was the larger half of the fix.** Chunking the query alone
did NOT work. A callback calling `get_permalink()` or `get_edit_post_link()` —
which the placement report does for every row — makes WordPress load that post
into its in-memory object cache, content and all, and that cache is never
trimmed inside a request. So the slicing bounded the query while the object
cache quietly re-accumulated the whole corpus behind it. Measured at 1,200
posts: chunking alone brought the walk to +14MB and the object cache put +82MB
straight back. `walk_content()` now evicts the ids it touched after each
slice, using `wp_cache_delete()` rather than `clean_post_cache()` — the latter
fires actions other plugins listen to, and a cache eviction is not a post edit.

**Measured, on a 1,200-post / 76MB corpus:**

| | peak memory |
|---|---|
| old, one unbounded query | **+80 MB** |
| new, chunk 200 | **+28 MB** |
| new, chunk 25 | +2 MB (content only) |
| new, chunk 1500 (> corpus) | +82 MB — reproduces the old bug |

Peak tracks the slice size, not the corpus size. That is the property: at
chunk 200 the content cost is the same whether the site has 1,200 posts or
120,000. Output was byte-identical before and after on the demo.

`replace_sitewide()` resolves its ids up front for a second reason: each
update changes the very content its `LIKE` matches on, so a query re-run
per slice against live data would shift underneath itself and skip posts.

`walk_columns()` is split out and unit-tested. Column names cannot be
`prepare()` placeholders — they are interpolated into the SQL — so anything
off the allow-list is dropped rather than escaped.

## CSV export for every report (`core/Slk/CSV.php`, v0.42.0)

Export covered three datasets (auto-link rules, broken links, the links
report). The six reports built since then now have it too: **Anchor Text,
Link Equity, Cannibalisation, Placement, Domains, Trends**.

**A registry, not a switch.** `Slk_CSV::datasets()` maps `type =>
[capability, method]`, so a new report cannot ship a button that leads to a
silent no-op. A test asserts every entry names a method that exists.

**Exports carry MORE than the screen does** — post ids, urls, and the raw
values the tables hide because they would make a page unreadable (raw
PageRank alongside the relative figure; every individual link position, not
just the average). The reason to open a CSV is the work the admin screen
cannot do.

**Anchor Text exports one row per anchor→target pair**, not per anchor. The
reason to open it in a spreadsheet is almost always ambiguity — one phrase
pointing at several posts — and collapsing that to a "3 targets" cell throws
away the only column you would sort on. Anchor-level figures repeat down the
group so a pivot table works.

**`defuse()` — CSV formula injection.** Excel and Sheets read a leading `=`,
`+`, `-`, `@`, tab or CR as the start of a formula. Post titles and anchor
text are content, so a post titled `=HYPERLINK("http://evil","Click")`
becomes a live link in whatever spreadsheet the export is opened in — on a
machine that never visited the site. Quoting does not help; the character has
to stop being first. A leading apostrophe is the conventional fix.
Applied centrally in `stream()`, so it **also fixed the three original
exports**, which had the same exposure. Bare negative numbers are left alone
— prefixing `-5` would turn a numeric column into text and break every SUM in
the sheet, making the export safe and useless.

Buttons are gated on there being rows, except Anchor Text, which is gated on
*any* anchors existing rather than on the current sub-tab having matches —
the export always carries the full set.

## Trend history (`core/Slk/History.php`, `templates/trends.php`, v0.41.0)

Every other report is a photograph of right now. This is the only thing in the
plugin that answers **"is my internal linking getting better?"**

**Storage.** `wp_slk_history`, one row per day (`SLK_DB_VERSION` 6 → 7).
`taken_on DATE` carries a **UNIQUE key** — capture is called from three places
and without it a busy day would produce a jagged line made of the same
afternoon sampled six times. A same-day capture **overwrites**, so a row is
always the latest reading for that date. Pruned at `KEEP_DAYS = 730`.

**When it captures** — three independent triggers, deliberately:
1. `slk_daily_snapshot`, its own daily WP-Cron event at 00:20 site time.
   **Not** tied to the digest: the digest is off by default and can be switched
   off at any time, and history that stops silently when an unrelated setting
   changes is worse than no history, because the gap is invisible on a chart.
2. `Slk_Scan::ajax_batch()` when any job reports `$done` — a finished scan is
   both the moment the numbers are most accurate and the one time someone
   deliberately made them change.
3. `Slk_Schedule::run()`, right after `Slk_Equity::build()`, so the equity
   cache is warm and `avg_depth` is real rather than 0.

**Capture is cheap on purpose.** `Slk_Report::summary()` (five COUNTs) plus a
cached `Slk_Opportunity::all()` read. `avg_depth` is taken from the equity
transient *only if it happens to be warm* — a daily snapshot must never trigger
a PageRank pass.

**Where it shows.**
- **Reports → Trends** — four clickable metric tiles with sparklines, a full
  SVG line chart for the selected metric, 30/90/365-day ranges, a plain-English
  verdict line, and a table of every reading with per-row deltas.
- **Dashboard → "Last 30 days"** — the same four tiles, linking through. Hidden
  entirely until two readings exist.

**Direction is per-metric, and stated.** More internal links is good; more
orphans, broken links or unapplied opportunities is not. A chart that colours
every rise green teaches the wrong thing. `good` is `true` / `false` / **`null`
when nothing moved** — without the null a stationary metric shows a confident
tick for a number that never changed.

**Empty state is honest.** Under two readings it says so and shows today's raw
numbers, rather than drawing a flat line through one point and implying a
stability that was never measured.

**Drawing is inline SVG generated in PHP** (`spark()`, `chart()`). A charting
library would be 60KB of JavaScript to draw four polylines, and there is no
build step. Two traps handled: a flat series has no range to scale against
(divide by zero → NaN in the markup), and the chart's y-axis starts at zero
*unless* the values sit far from it — a 980→1020 move plotted against a zero
baseline is a flat line.

`compute_trends(array $rows)` is split out from `trends($days)` so the
direction logic is testable without a database.

**Uninstall** drops `slk_history` and clears `slk_daily_snapshot`.

## Posts-list "Links" column (`core/Slk/PostsColumn.php`, v0.40.0)

A **Links** column on edit.php showing inbound ↓ / outbound ↗ / external 🌐 per
post — the reports' numbers, on the screen you already open to pick something to
edit.

Three decisions:

- **Zero inbound is the only number shouted.** Three grey figures per row is
  noise you learn to skip. A post nothing links to is an orphan: red, plus an
  ORPHAN badge. The other two stay quiet, external quietest of all.
- **The column SORTS** (`orderby=slk_inbound`), which is what turns a read-out
  into a worklist — sort by fewest inbound and work down the list in the editor
  you were opening anyway. Implemented in `posts_clauses`, because the value is
  a count over another table, not a column or meta key.
- **One query for the whole screen.** `counts_for()` unions an outbound/external
  GROUP BY with an inbound GROUP BY over the ids in `$wp_query->posts`, cached
  per request. **Verified: 5 posts → 1 query**, where three sub-selects per row
  would have been 15. edit.php is already among the heaviest screens in wp-admin.

**The CSS is inlined on `admin_head-edit.php`, not in admin.css.** The plugin's
stylesheet is 77KB and is deliberately not loaded outside SmartLinker's pages
and the editor; pulling it in so one column can be 13px and red would be a bad
trade. Under 1KB, and gated on the screen's post type being one SmartLinker
handles.

Verified: renders on posts and pages, survives trash and other `orderby` values,
sorts both directions, zero PHP notices.

## Inbound in the meta box, and the AI engine (v0.39.1)

**Meta box** now has a third scan button, **Links In**, beside Find Link
Suggestions and AI Suggestions. It renders through the same
`renderSuggestions()` as the outbound scan, so inbound rows keep everything the
meta box adds over the sidebar — **Reject**, the reason line, and the editable
anchor/destination. `suggestionCard()` already branched on `source_id`, so the
row automatically headlines the SOURCE post and applies via
`.slk-insert-inbound` (a server-side write to that other post).

**Both surfaces now expose the AI engine for inbound.** `Slk_AI::inbound_for()`
and `engine=ai` on `slk_get_inbound` already existed; the sidebar was simply
hardcoding `'standard'`. Both now show the `Standard | AI` switch the reports
use, locked with an explanation when there is no key.

The AI engine earns its keep here: on the same pair it chose the anchor
**"spaza shop stock list"** at 80% where the standard engine chose
"spaza shop stock" at 76% — the fuller, more natural phrase.

**Bug caught during the browser test:** the engine switch resolved the box via
`$b.closest('.slk-metabox').find('.slk-inbound-here')`, but the scan buttons are
**hoisted into the postbox header**, so that lookup found nothing and clicking
AI silently did nothing. `runInboundHere()` now takes the BOX; the button
handler uses `metaboxFor()` (which exists for exactly this) and the switch —
which really is inside the box — uses `closest()`. Third time this hoisting has
bitten; see also the `.slk-scan` handler note.

## Inbound suggestions in the editor (v0.37.1)

Third sidebar button, **Links in**, beside Link and AI. Answers the reverse
question — *which existing posts should link TO the one I am editing* — which is
the one that matters right after publishing, when a post has no inbound links
and is therefore an orphan. Backed by the existing `slk_get_inbound` endpoint.

**The critical difference, and why the UI keeps saying so:** an outbound
suggestion edits the post you are in, via the editor bridge, and is part of your
next save. An **inbound** suggestion edits ANOTHER post, server-side, saved the
instant you click. So in inbound mode:

- the card headlines the SOURCE post (the destination is always the post you are
  editing — repeating it says nothing). Same rule the meta box uses.
- the button reads **"Add to that post"** / "✓ Added there", not "Apply Link".
- a note above the results, and the footer after applying, both say the write
  is already saved and point at Activity for undo.

Two bugs caught in a screenshot and fixed before shipping: the footer said
"Remember to update the post to save" in BOTH modes — plainly false for inbound
— and the third button truncated to "INBOU…" in a narrow sidebar (label
shortened, buttons tightened; the arrow icon carries the direction).

Verified end to end: 3 suggestions, applying one wrote the link into
*Spaza Shop Profit Margins*, indexed it, logged it to Activity, and took the
edited post off the orphan list — while the editor stayed clean (not dirty),
proving the write did not touch the open post.

## Scan progress bars (`core/Slk/Scan.php`, v0.36.2)

All four long scans — link index, broken links, opportunities, cannibalisation
— now run in slices through one AJAX endpoint and draw a bar: label, "40 of 79
· 51%", a running found-count, and a rough time estimate.

- **The link re-index used to be unbatched**, indexing every published post in
  a single request. On a large site that is a timeout with no partial result.
  It runs in slices like the others now.
- `Slk_Scan::jobs()` is the whole registry: label, batch size, a counter and a
  runner per job. Runners return TOTAL processed so far, so the browser only
  echoes back the number it was last given.
- Batch sizes differ by cost: 25 for broken links (one HTTP request each) vs 40
  for the rest.
- **`$done` also trips when a runner reports no forward progress**, or a
  browser loop would call the same offset forever.
- **The old redirect-chain URLs are untouched** and remain each button's
  `href`. JS intercepts the click; with JS off the scan still runs the old way.
  Verified all four fallback links still return 200.

**The bar's insertion point is deliberately not `.closest('p, div')`.** Several
of these buttons sit in a flex toolbar, and a bar dropped there becomes a flex
ITEM — a 240px sliver beside the button instead of a full-width bar under it. I
shipped that bug and caught it in a screenshot. `scanBarAnchor()` climbs until
the parent lays out as a block. Same family as the `.slk-card`/`.slk-suggestion`
traps already in this file.

## Dead code removed (v0.35.0)

Verified unreferenced before deletion, not guessed at:

- **`core/Slk/Outbound.php`** — 275 lines. AI-proposed external links; built,
  never wired to any route, AJAX action or template. Its registration in
  `Init.php` and its phantom `outbound_limit` setting (in the schema, never
  rendered, read only by the dead class) went with it. **External Sites
  (`Slk_Sitemap`) is a different feature and stays** — it is live and feeds the
  suggestion engine.
- `Slk_ClickTracker::clicks_for_target()`, `Slk_Error::is_broken()` (a
  back-compat wrapper with nothing left calling it), `Slk_Error::count_broken()`,
  `Slk_Rejection::forget()`, `Slk_Sitemap::has_sites()` — zero references each.
- `markContext()` in `admin.js` and the `.slk-context` CSS rules — the card
  redesign stopped emitting that markup; nothing produced it any more.

**Detection notes for next time.** A naive `grep 'Class::method'` gives false
positives: it misses `self::` calls and callables passed by reference
(`.map(suggestionCard)`, `$(enhanceAllSelects)`). Those four JS functions looked
dead and are not. Count external calls + `self::`/`static::` + quoted-string
references together.

## False orphans — host normalisation (v0.34.1)

**Reported from a live site: pages with inbound links were listed as orphans.**

`Slk_Link::classify()` compared hosts raw. A site reached at both example.com
and www.example.com is one site, but `strcasecmp('www.example.com',
'example.com')` fails — so every link written with the other form was stored as
`type = 'external'`, `target_post_id = 0`. The orphan report is built purely
from those two columns, so its target became an orphan. `index_post()` had
always stripped `www.` when storing `host`, so the inconsistency was sitting in
adjacent lines.

Second, smaller cause: an uppercase host classified as internal but
`url_to_postid()` returned 0, because it matches against `home_url()` and gives
up on anything that does not look like it.

Fixed with two helpers on `Slk_Link`:
- `normalise_host()` — lowercase, strip a **leading** `www.` only. Tested that
  `wwwexample.test` and `my.www.test` are untouched, and that lookalikes
  (`example.test.evil.com`, `notexample.test`, `shop.example.test`) stay
  external.
- `canonicalise()` — rebuild the URL on the site's own scheme/host/port before
  resolving. **Path is kept verbatim**, or a subdirectory install would have
  its prefix doubled.

11 URL forms now resolve; before the fix, `www.` and uppercase-host did not.
Pinned in `LinkClassifyTest`.

**Existing installs must re-scan** — the fix only affects indexing, so rows
already stored keep their wrong `type`/`target_post_id` until Reports →
Overview → Run a link scan.

## Uninstall cleanup (`uninstall.php`, v0.34.0)

Deleting the plugin used to leave 8 tables, every option and transient, and 5
post-meta keys on every post, permanently and with no way to remove them.

**Cleanup is opt-in and always will be.** `delete_data_on_uninstall`, default
0. The asymmetry decides it: leaving tables behind costs a few megabytes, while
deleting them by surprise destroys a link index, a rejection history and an undo
log. People delete plugins to reinstall them, to move hosts, to test a conflict
— all of those must be safe.

- `uninstall.php` runs with the plugin NOT loaded, so it references no `Slk_`
  class and no `SLK_` constant. Every name is spelled out.
- **Tables are listed explicitly, not wildcarded.** Dropping a table cannot be
  undone, so the one irreversible step gets no pattern matching. Options,
  transients and post meta ARE prefix-matched (with `esc_like`), because a
  stale row is harmless while a missed one lingers forever.
- Multisite loops sites and checks each site's own setting — a network admin
  must not wipe a sub-site whose owner never opted in.
- Also clears the `slk_scheduled_scan` cron event.

Verified both ways against the demo, with a database backup taken first:
setting OFF left `tables=8 options=9 transients=8 postmeta=4` untouched;
setting ON took all four to zero while **49 posts and the 38 links written into
them survived** — inserted links are ordinary content, not plugin data.

## First-run checklist (`core/Slk/Setup.php`, v0.33.0)

A fresh install lands on a dashboard of zeroes with no hint that four things
must happen first, in an order nobody can guess. This panel sits above the
snapshot on the Dashboard and says what they are and why.

- **Steps report their own state** by inspecting the site (links indexed,
  focus keywords present, opportunities scanned, embeddings built). Nothing is
  ticked by hand, so the list cannot claim something is done when it is not.
- **The semantic index is marked optional** and never blocks completion — it
  costs the user money, and everything else works without it.
- **The panel disappears on its own** once the required steps are done. A
  permanent checklist is clutter, and one that must be dismissed to stop
  nagging teaches people to dismiss things. "Hide this" exists only for
  someone who genuinely will not do a remaining step.
- Step 2's copy adapts: it names the detected SEO plugin when there is one
  (via `Slk_KeywordImport::sources()`), and offers Target Keywords when not.

Verified against a simulated fresh install (links + target keywords cleared,
transients flushed): 0 of 3, all actions present, pressing step 1 indexed 39
links and ticked it. Demo restored afterwards.

## Saving feedback (v0.32.1)

The Save button spins while the request is in flight — a round, rolling border
spinner (`.slk-btn-loading`, `border-radius: 50%`, `animation: slk-spin`) — and
the result then appears **beside the button as plain text**: a drawn tick and
"Settings saved.", no background, no border, no chip. It is a sentence, not a
control, so it is not dressed as one. Fades after 4s.

- `.slk-save-status` in the form is the slot. `swap()`'s notify block writes
  there when it exists and only falls back to the floating toast when it does
  not — every other form (auto-links, target keywords, url changer…) still
  toasts, because they have nowhere better to put it.
- The slot is cleared on submit, so it never holds a stale "saved" from last
  time. Even hidden, a screen reader would read it on the next focus.
- The tick is drawn with two borders rather than a dashicon: it inherits the
  text colour and needs no icon font to have loaded.

## Where admin load time actually goes (measured, v0.31.1)

Measured on the demo, so nobody re-litigates this from a hunch:

| | |
|---|---|
| Entry page, cold | ~1.3s |
| Entry page, warm | ~550ms |
| Sidebar section click | 55–154ms |
| Settings tab click | 2.5–9.5ms, **0 requests** |

**The entry cost is WordPress, not this plugin.** Our page loads 54 scripts /
3.19MB; WordPress's own `options-general.php` loads **77 scripts / 3.67MB** on
the same install, including the whole block-editor stack (`block-editor.min.js`
1MB, `components.min.js` 787KB) that core pulls in for the command palette.
SmartLinker's own share is `admin.css` 77KB + `admin.js` 81KB. Assets are
correctly gated to our pages and `post.php`/`post-new.php` only.

**The spinner is delayed 250ms on purpose** (`LOADING_DELAY` in the SPA block).
Most navigations finish under 150ms, and a spinner shown for that long tells
you nothing you did not know — it just flashes and makes a fast page *feel*
like it buffered. Below the threshold nothing appears and the panel simply
swaps. Verified: no navigation flashes it.

`preconnect` hints for the two Google Fonts hosts, added via `wp_resource_hints`
— a third-party font costs DNS + TLS before the first byte, usually more than
the download.

## Instant settings tabs (v0.31.0)

Settings tabs cost **nothing** to switch — every panel is rendered up front and
toggled in the browser. 2.5–9.5ms per click, **zero network requests**.

- Each tab is its own `<form>` inside `[data-slk-panel]`. That is not
  cosmetic: with one shared form, saving any tab posted every tab's fields, and
  WordPress checkboxes absent from a POST count as unticked — so saving the AI
  tab could silently clear `use_stemming` on the General tab. Separate forms
  make cross-tab clobbering structurally impossible. (This bit me for real
  earlier in development, via curl.)
- Tab links carry `data-slk-tab`; `isSectionLink()` explicitly ignores them so
  the SPA never fetches for a tab switch.
- `pushState` keeps tabs linkable; `popstate` and a `slk:loaded` handler
  restore the right panel on back/forward and after a section swap.
- Cost of rendering all four instead of one: **70KB → 96KB, ~72ms → ~80ms**,
  once.

**Do NOT copy this to the Reports tabs.** Those run real queries — PageRank,
all-pairs cosine, full post scans — and rendering all eight would make the
first load far slower than the clicks it saves. Reports keep the
`Slk_Base::ajax_section()` path.

## SPA navigation cost (v0.30.0)

Clicking a section used to `fetch()` the whole of `admin.php` and discard all
but `.slk-main` and `.slk-nav` — **70KB to use 8KB**, and the real cost was not
bytes: that request builds the entire admin menu and runs every other plugin's
admin bootstrap, none of which survives the swap. On a site with many plugins
that is seconds per click.

`Slk_Base::ajax_section()` renders one section through `admin-ajax.php`
instead. On the demo: **70KB → 12KB**, and `admin.php` ~72ms vs `admin-ajax`
~21ms before the section is even rendered.

- The route table moved out of `add_menu()` into `Slk_Base::routes()` so the
  endpoint can resolve and capability-check a page without the admin menu.
- The endpoint replays the requested query string into `$_GET`, because
  renderers read `tab`/`view`/`filter`/`paged` directly. `_wpnonce`, `action`
  and `nonce` are stripped first.
- **A nonce in the URL means it is an ACTION, not navigation** — a scan, undo,
  rebuild — and those keep the full admin path so their `admin_init` handlers
  and redirects behave exactly as before. Every action link in this plugin is
  nonce-protected, which is what makes that rule safe; keep it that way.
- Any failure falls back to the full page load. A fast path is only worth
  having if it cannot strand you.

## Link placement (`core/Slk/Placement.php`, v0.29.0)

Eighth Reports tab. Where each internal link sits inside its post, 0–1.

**Position is measured in VISIBLE TEXT, never raw HTML.** Markup is unevenly
distributed — a post opening with a gallery or a table of contents carries far
more tags per word at the top — so measuring source offsets reports a link as
halfway down a post the reader experiences as near the start. `positions()`
strips tags from the prefix before each `<a>` and divides by total stripped
length. Pinned in `PlacementTest::test_markup_does_not_shift_the_measurement`.

- `bottom` (buried) = 2+ links averaging past 0.60; `top` = averaging under 0.40.
- **A single link gets no shape verdict** (`single`). One link is a position,
  not a pattern, and calling it bottom-heavy is an overreach the author cannot
  act on.
- External links excluded — they have their own report and mixing them makes
  "how deep are my internal links" unanswerable. Relative hrefs count as internal.
- The track in the table is one dot per link, left = start of post. Green zone
  is the first quarter, amber the last.

Cached an hour, flushed on `save_post` (unlike Opportunities there is no
worklist state to lose).

## Cannibalisation (`core/Slk/Cannibal.php`, v0.28.0)

Seventh Reports tab. Finds posts on the site competing for the same query.
Two signals that mean different things, merged into one list:

- **Same focus keyword** — an intent clash you created. `O(n)` grouping on
  `Slk_Anchor::normalize()`, so it always runs, no scan and no API key needed.
  Folding matters: "Spaza Shop." and "spaza shop" must group, or the keyword
  half silently finds nothing and looks like a clean site.
- **Semantic overlap** — the posts mean the same thing regardless of keywords.
  Needs the embeddings index. `O(n²)`, so it is batched by offset against a
  wall clock like the opportunities scan, walking only the upper triangle.

`SEMANTIC = 0.86`, deliberately far above `Slk_Embedding::GOOD` (0.70). GOOD
means "related enough to link"; cannibalisation is a much stronger claim. If
this ever drifts toward GOOD the report flags every neighbouring article as a
rival — pinned in `CannibalTest`.

Severity → recommendation: `direct` (same keyword + near-identical) → merge;
`keyword` → re-target one; `overlap` → differentiate or merge.

**Which one to keep is decided by the equity graph.** Link value already earned
is the least arbitrary tie-break available and the most expensive thing to
rebuild elsewhere. Nothing is auto-fixed — the fix is editorial.

## Equity-aware suggestions (v0.27.0)

The engine now uses the PageRank it computes: between two comparably relevant
targets, the one nothing links to is offered first.

**The design rule, which must not be undone:** the boost changes the ORDER
(`score`) and never the DISPLAYED CONFIDENCE (`match`). Confidence answers "is
this the right link" — a claim about correctness. How starved a page is answers
"is this a useful link" — a claim about value. Folding the second into the
first would make the percentage on screen a lie: a weak match to a neglected
page would read as a strong match.

- `Slk_Equity::need_map()` → post id => 0..1. `need = clamp((1 - relative)/0.75)`,
  so at or above an average page's share the need is 0, at a quarter or below
  it is 1, linear between.
- `priority = confidence × (1 + equity_boost × need)`, sorted on; `match` untouched.
- Setting `equity_boost`, default 0.15, capped 0.5, 0 = off.
  At 0.15 a fully starved page overtakes a rival scoring up to 15% higher —
  enough to reorder near-equals, never enough to lift a poor match above a good
  one. Bounds pinned in `EquityBoostTest`.
- The reason line says "Ranked up: few links point here." only when need ≥ 0.5,
  so it never carries noise.
- `Slk_Schedule::run()` rebuilds the equity cache, so the engine finds it warm
  rather than paying for a PageRank pass inside an editor request.

## Link equity + click depth (`core/Slk/Equity.php`, v0.26.0)

Sixth Reports tab. Two independent measures over the internal link graph:

- **Equity** — PageRank, d=0.85, 40 iterations with early exit at 1e-6.
  Dangling nodes (no outbound links) spread their rank across all nodes, or
  value leaks out and the total stops summing to 1. Shown as a multiple of an
  average page (`1.00×`), because a raw score of 0.0004 means nothing to anyone.
- **Depth** — BFS from the front page. Static front page = one seed; blog index
  = the posts on page 1.

Verified against the published three-node example (A 0.3878 / B 0.2148 /
C 0.3974) — see `EquityTest`. Total value summing to 1.0 is asserted on five
graph shapes; that invariant is what catches a broken damping or dangling term,
since wrong maths still produces plausible-looking numbers.

**Wording matters here.** BFS correctly reports many posts as unreachable on a
blog-index site, because older posts are only reachable via pagination or
archives — which SmartLinker does not index. Calling that "Unreachable" reads
as "invisible to Google" and is alarmist. The tab says **"No content-link
route"** and states plainly what is excluded. Do not "fix" the number; the
number is right.

Cached in a transient for an hour, flushed on `deleted_post`, with a
Recalculate button. Safe to flush freely — unlike the Opportunities worklist
there is no user state to lose.

## "Why not?" diagnostic (`core/Slk/Diagnose.php`, v0.25.0)

Own sidebar page. Two searchable dropdowns → replays the pipeline for one
source→target pair and stops at the first gate that rejects it, showing the
real figures and what would change the outcome.

**It calls the engine's own helpers rather than reimplementing the rules.** A
diagnostic that drifts from the engine is worse than none — it sends you
looking in the wrong place. That is why `Slk_Suggestion::pick_anchor()`,
`focus_keywords()`, `cluster_map()`, `cluster_relation()`, `keyword_overlap()`
and `existing_anchor_texts()` are now **public**: there is a second legitimate
consumer inside the plugin. Do not re-narrow them without moving the logic.

Gates, in engine order — `candidate`, `linked`, `rejected_here`, `anchor`,
`learned`, `similarity`, `common_anchor`, `confidence`, `limit`, `ok`.

The `anchor` verdict is the valuable one, because it is the most common and was
previously the least actionable: it lists the phrases the cascade actually
looked for ("it looked for “burr grinder” and found none of them in your
text"), which turns "no suggestion" into a specific editing decision.

## Learned rejections (`core/Slk/Rejection.php`, v0.24.0)

Rejection used to be per-post (`_slk_rejected` = list of target IDs) and stored
only the destination, never the anchor. So turning down "equipment" meant
saying no once per post forever, and nothing recorded that the WORD was the
problem. Both halves are now kept site-wide.

- Same anchor→target on **2 distinct posts** → that pairing is retired.
- Same anchor on **3 distinct posts**, any target → the anchor is retired.
- **Distinct posts, not events.** Rejecting twice on one post is one opinion.
- Both engines honour it — `Slk_Suggestion::for_post()` and `Slk_AI::suggest()`.
  Rejecting in Standard and being re-offered by AI would make it pointless.
- Reports → Anchor Text → **Suppressed** lists everything blocking, the posts
  that caused it, and a Restore button. A "On their way" table shows rejections
  below threshold, so a suppression is never a surprise.

**Restore semantics, both learned the hard way (see `RejectionTest`):**
- Restoring an anchor must **cascade to its pairs**. The first version released
  the site-wide block but left the narrower pair block in force — the anchor
  read as restored while still being silently suppressed.
- Restore **clears the evidence**, it does not set a permanent exemption. The
  first version made restore a one-way door: reject it fifty more times and it
  would still be offered.

The per-post `_slk_rejected` meta is untouched and still does its old job.

## Reports page (`core/Slk/Reports.php`, v0.23.0)

Five reports — Overview, Anchor Text, Broken Links, Link Clicks, Domains — live
under one `smartlinker_reports` page with a tab strip, styled like Settings.
Sidebar went from 18 items to 14.

**The old slugs still render.** `?page=smartlinker_broken` etc. remain
registered and resolve to their tab, because they are linked from the
dashboard, from inside other reports, and from every digest email already sent.
Rewriting those would break links already out in the world.

- `Slk_Reports::current_tab()` — explicit `?tab=` wins over the legacy slug, so
  a tab link works mid-navigation from a legacy URL.
- `Slk_Reports::on_tab($tab)` — handlers must use this, **not** a page-slug
  test. `Slk_Error::handle_scan()` and `Slk_Report::handle_rescan()` were
  checking `$_GET['page'] === 'smartlinker_broken'`, which silently stops
  firing once the same report is also reachable as a tab.
- `Slk_Reports::url($tab, $args)` — canonical URL builder; use it for any new
  link to a report.
- `Slk_Admin::open()` folds the five slugs onto the one nav item, or an old
  bookmark highlights nothing and titles the page "SmartLinker".
- CSS hides the per-report `<h1>` inside the shell (`.slk-reports-tabs + .wrap
  > h1`) — the lit tab already names the page. The templates were not touched.

Orphaned Posts is deliberately NOT a tab: it edits posts in place, which makes
it a worklist like Link Opportunities rather than a report.

## Tests (`tests/`, v0.22.0)

```bash
composer install && ./vendor/bin/phpunit --testdox
```

193 tests, no database, no WordPress, runs in ~40ms.

`tests/bootstrap.php` deliberately does **not** load WordPress. The usual plugin
harness needs MySQL and a WP checkout, which makes the suite slow and
environment-dependent — so nobody runs it. Instead it stubs the handful of WP
string helpers (`wp_strip_all_tags`, `__`, `sanitize_*`) and loads only the three
classes that are genuinely DB-free: `Slk_Word`, `Slk_Settings`, `Slk_Anchor`,
`Slk_Suggestion`. `Slk_Test_Post` stands in for `WP_Post`.

What is covered, and why it is the right thing to cover: this is the logic where
a regression silently degrades **suggestion quality** rather than throwing an
error. Anything touching `$wpdb`, hooks or HTTP is out of scope and is verified
by exercising the real plugin on the demo site.

- `WordTest` — tokenizing, phrase matching, stemming. Pins the **hyphen bug**
  in both directions (`pour-over` ↔ `pour over`) and the fact that `find()`
  returns the author's original casing, not the phrase searched for.
- `AnchorCascadeTest` — the four-phase cascade, asserted by **tier name**, so
  the priority order itself is under test. Pins the **title-splitting bug**
  ("How to Make Pour Over Coffee" must not be destroyed by "over"). Also pins
  the invariant that the returned anchor exists verbatim in the source, which
  is the contract the insert step depends on.
- `AnchorReportTest` — `normalize()` folding 11 spelling variants, and the
  distinction between a bare "read more" and "read more about grinders".

`pick_anchor()` is protected and reached by reflection, rather than widening the
real class's visibility for a test's convenience.

**The suite was verified to have teeth**: reintroducing the hyphen bug fails 2
tests; reintroducing the title-splitting bug fails 2 different ones. A suite that
has never been seen to fail is not evidence of anything.

## Demo environment (throwaway)

WordPress 7.0.2 on **SQLite** (no MySQL/Docker on this Mac), run via wp-cli:

```
/private/tmp/claude-501/-Users-gustavtk-Claude-Code/<session>/scratchpad/wpsite
```

`http://localhost:8899/wp-admin` · **admin / admin123** · plugin symlinked into `wp-content/plugins/`

Restart the server with `scratchpad/wpsite-start.sh`; re-seed content with
`wp eval-file scratchpad/seed-content.php` (10 interlinked coffee posts + 3 pages,
deliberately shipped with **no** internal links so there is plenty to suggest).

`scratchpad/plant-broken.php` plants one link of every failure class (404, missing,
redirect, timeout, DNS error, empty `#`) plus working controls, so the Broken Links
report has something to sort. Idempotent — re-running replaces rather than duplicates.
Deterministic and offline-safe: `192.0.2.1` (TEST-NET-1) for the timeout, a `.example`
domain for the DNS error, everything else local.

**This lives in /tmp and will be wiped.** To recreate: `wp core download --skip-content`,
copy the sqlite-database-integration plugin's `db.copy` → `wp-content/db.php` (blank the
folder-path placeholder; it has a realpath fallback), `wp core install`, download a theme
(`--skip-content` ships none), symlink the plugin, `wp server --port=8899`.

Setup notes worth keeping:
- `wp db query` does **not** work on SQLite (it shells out to the `mysql` binary) — use
  `wp eval 'global $wpdb; ...'` instead.
- Disable the block editor's welcome-guide modal, or it covers the screen on every post:
  set `welcomeGuide => false` in user 1's `wp_persisted_preferences` meta.
- Meta boxes sit in a **collapsed** "Meta Boxes" drawer at the bottom of the block editor —
  expand it to reach the suggestions panel.

`wp server` is single-threaded — run broken-link scans via `wp eval 'Slk_Error::scan()'`,
never by clicking the button (it deadlocks on its own self-request).

## Gotchas learned the hard way

- PHP casts numeric array keys to ints — `$range === $key` fails for `'30'`; cast to string
- `.slk-edit { display:flex }` beats `[hidden]` — use `:not([hidden])`
- Core ships `.wp-core-ui .button-primary:disabled { background:#e2e2e2!important }` —
  no selector outranks it, so styling a disabled primary button needs `!important` too
- The block editor's meta-box drawer (`.edit-post-layout__metaboxes`) is ~150px with
  `overflow:auto` — it **clips** upward-opening `Slk_Admin::help()` bubbles in a hoisted
  postbox header. Use a visible `.slk-callout` in the panel body there instead
- Never hide a feature because it is unconfigured — it reads as a missing feature.
  Show it locked and say which setting is missing (see the AI button in `meta_box.php`)
- `stopPropagation` on a container kills delegated `$(document)` handlers
- Check CSS **rule order** before assuming a caching problem
- `url_to_postid()` returns the id from `?p=N` even when that post doesn't exist
- Never `str_replace()` a URL — use boundary matching (`?p=99` would corrupt `?p=999999`)
- `wp_strip_all_tags()` keeps anchor text — use `Slk_Post::linkable_text()` when finding new links
- **Never put a term's own name in its topic profile** (`Slk_Suggestion::term_is_related`).
  Include it and any article repeating that word outranks articles actually about the topic:
  a spaza-shop post scored 0.090 against a coffee "Equipment" category while a pour-over
  guide scored 0.029, because the guide says "grinder" and "kettle", not "equipment".
- **Relatedness must be measured on single words, not `Slk_Post::keywords()`.** That method is
  dominated by bigrams, which almost never match verbatim across two documents, so every pair
  of posts looks unrelated. Use `Slk_Post::term_vector()` (stemmed unigrams, TF-IDF cosine).
- **IDF is near-useless below ~100 posts.** On a 20-post corpus "our" and "single origin" both
  scored 0.545, and "equipment" tied with "gooseneck". Rarity thresholds must never be the only
  defence — pair them with corpus-independent rules. Don't tune these thresholds on a small
  demo site; it is fitting noise.
- **Auto-link rules are not independent.** Whichever runs first turns the text into an
  anchor and every later rule skips it. Anything that counts or previews rules must replay
  them in order, mutating as it goes (`Slk_Keyword::link_counts()` → `tally()`) — evaluating
  each rule against the untouched post lets overlapping rules both claim the same words.
- Auto-links are render-time only and never stored, so the Links column is **derived**, not
  read from a table. Cached in a transient, flushed on rule change and on `save_post`.
- When checking auto-link output, count only anchors carrying `data-slk="1"` — content can
  already contain hand-written links to the same URL, which will otherwise inflate the total.

## Not built (deliberate or remaining)

- **N/A by design:** AI credits, multi-site licensing, Shopify (user brings their own key; no licence gate)
- **Remaining ideas:** live Google OAuth for Search Console, Visual Sitemap, white-label/agency
  reports, Related Posts widget, Elementor/Divi adapters,
  Domain + Advanced settings tabs (only General / Content Ignoring / AI exist so far)

## Working style that worked

Build → run it in the live demo → verify by measuring the DOM, not eyeballing. That caught
every real bug in this session (URL corruption, already-linked suggestions, broken scans,
row heights). Keep doing that.
