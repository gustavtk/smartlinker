# SmartLinker

A WordPress internal-linking plugin — original code, no license gating. Built as a clean-room implementation inspired by the feature set of internal-linking tools.

## Phase 1 (implemented)

- **Internal link suggestions** — in the post editor, scan content and get ranked suggestions for internal links to your other posts/pages, with one-click insertion. Keyword matching uses title + weighted uni/bi-gram extraction with stop-word filtering.
- **Auto-linking rules** — keyword → URL rules applied on the frontend via `the_content` (never rewrites stored posts). Options: case sensitivity, partial match, new tab, nofollow, max links per post, pause/activate.
- **Reports** — sitewide totals (internal/external links, orphaned content) and a per-post table of outbound/inbound internal links and click counts. Full re-scan action.
- **Click tracking** — logs clicks on internal links via a lightweight `sendBeacon` ping.
- **Settings** — post types, suggestion limits, ignore words, excluded IDs, link attributes, feature toggles.

## Architecture

- `smartlinker.php` — bootstrap, constants, autoloader (`Slk_` → `core/Slk/…`), lifecycle hooks.
- `core/Slk/Init.php` — service registry.
- `core/Slk/Base.php` — menus, meta box, asset enqueue, AJAX routing.
- `core/Slk/Query.php` — DB schema (`slk_links`, `slk_autolinks`, `slk_clicks`).
- `core/Slk/Post.php` — content/keyword extraction.
- `core/Slk/Link.php` — link parsing, safe in-content insertion, indexing.
- `core/Slk/Suggestion.php` — the suggestion engine + editor meta box.
- `core/Slk/Keyword.php` — auto-linking rules + frontend application.
- `core/Slk/ClickTracker.php` — click logging.
- `core/Slk/Report.php` — reporting page.
- `core/Slk/Settings.php` — settings storage + page.

## Phase 2 (implemented)

- **Inbound-link suggestions** — `SmartLinker → Inbound Links`: pick any post/page (orphans flagged ⚠) and find other content that could link *to* it, inserted with one click into the source post. Reverse of the outbound engine; skips sources that already link to the target.
- **Broken-link detection** — `SmartLinker → Broken Links`: scans indexed links in bounded batches. Internal links checked via post resolution + HTTP; external via HEAD with GET fallback (403/405/transport errors retried). Broken count also shown on the Reports dashboard.

## Phase 3 (implemented)

- **URL Changer** — `SmartLinker → URL Changer`: replace a URL everywhere it appears in your content in one operation (changed permalinks, moved pages, updated affiliate links). Reports posts/occurrences changed, keeps a change-history log, and re-indexes affected posts.
- **301 redirects** — optionally leave a trailing-slash-insensitive 301 from the old URL to the new one (`template_redirect`, priority 1), so existing bookmarks/search results keep working. Redirects can be removed individually while keeping the history record.

## Phase 4 (implemented)

- **CSV export** — one-click export of auto-link rules, the internal-links report, and broken links (`Slk_CSV`), streamed as UTF-8 CSV (with BOM for Excel), nonce-protected and capability-gated.
- **CSV import** — bulk-create auto-link rules from a CSV upload. Auto-detects a header row (or accepts positional columns), skips invalid rows, reports imported/skipped counts. Columns: `keyword, url, case_sensitive, partial_match, new_tab, nofollow, max_per_post, active`.

## Phase 5 (implemented)

- **Target Keywords** — `SmartLinker → Target Keywords`: assign a focus keyword to a post, and SmartLinker finds every other post that mentions that keyword but does not yet link to it. Each row shows a live opportunity count; "Find opportunities" lists the source posts and inserts a keyword-anchored internal link to the target in one click (reuses the inbound insert endpoint). Stored in the `slk_target_keywords` table.

## Phase 6 (implemented)

- **AI suggestions (OpenAI)** — optional. Add your OpenAI API key in `SmartLinker → Settings` (stored in the site DB, entered by you — never hardcoded) and enable AI. An "AI Suggestions" button then appears in the post editor: it sends the content + a candidate list of your pages to the Chat Completions API (`gpt-4o-mini` by default) and returns semantically-ranked internal-link suggestions. Every anchor is validated to appear verbatim in the content before it's shown; suggestions insert with the same one-click flow. Calls are billed to your own OpenAI account.

## Phase 7 (implemented)

- **Search Console** — `SmartLinker → Search Console`: import your GSC "Pages" performance export (CSV) and get a priority report that cross-references impressions with each page's inbound internal-link count, flagging high-visibility, under-linked pages (impressions ≥ 100 and < 3 inbound links) with a jump to add inbound links. (Storage is OAuth-ready; a live Search Analytics fetch can populate the same table later.)
- **External Sites** — `SmartLinker → External Sites`: import another site's XML sitemap (handles sitemap indexes), deriving page titles from slugs. Those URLs fold into outbound suggestions (marked external), so scanning a post can propose relevant links to a sister site.
- **Block-native editing** — inserting a suggestion in the block editor now edits the editor's own content (client-side) and marks the post dirty, so it appears immediately and saves with the post. This fixes the prior conflict where a server-side write could be overwritten by an open editor with unsaved changes. The classic editor still falls back to the server-side insert.

## Phase 8 (implemented) — parity upgrades

- **Keyword stemming** (`Slk_Word`) — matches word variants (plurals, tenses) when finding suggestions, so "coffee grinders" matches a "Coffee Grinder" target. Returns the exact original substring for the anchor. Toggle in Settings → Smart matching. Wired into outbound suggestions, external + taxonomy matching, and the Link Map.
- **Taxonomy / term linking** (`Slk_Term`) — category, tag, and custom-taxonomy archive pages become suggestion targets (marked with their taxonomy). Toggle in Settings.
- **Dashboard** (`Slk_Dashboard`) — the new landing page: internal-link coverage %, links detected, orphaned/broken counts, a 30-day click trend, a qualitative link-health message, and quick actions.
- **Bulk Link Map** (`Slk_LinkMap`) — upload a keyword → URL CSV and insert those links into matching content across the whole site in one pass, using stem-aware matching, skipping self-links and posts that already link to the target.

## Interface

SmartLinker uses a self-contained, single-page admin UI (inspired by Perfmatters): an in-page sidebar lists every section, a dark header bar shows the section title + version, and boolean settings are real toggle switches. The native WordPress submenu under "SmartLinker" is hidden — navigation lives in the in-page sidebar (`Slk_Admin` renders the shell around each page; all pages stay registered and URL-reachable). Typography is Inter with deep-styled tables, form fields, and buttons.

**Single-page behavior:** the only full page load is opening the plugin from the WordPress sidebar. After that, a lightweight SPA layer (in `js/admin-ui.js`) intercepts sidebar navigation, in-panel action links, and form submissions, fetching and swapping just the content column via `fetch` + `history.pushState` — so you can click through sections, add/delete items, and save settings with no page refresh. It degrades gracefully to normal navigation if anything fails.

## Phase 11 (implemented) — feature-page parity

Built after a gap analysis against LinkWhisper's published feature list, imitating their report layouts (stat tiles, filter tabs, status badges, ranked lists):

- **Orphaned Posts report** (`smartlinker_orphans`) — dedicated page listing every published item with zero inbound internal links, oldest first, with outbound counts and a Fix action.
- **Broken Links upgrade** — links now store the HTTP `status_code` and a `broken_type`, so 404s, redirects (3xx), timeouts and 5xx are reported separately, with stat tiles, filter tabs and colour-coded status badges. Redirects are surfaced (they cost crawl budget) without being counted as broken.
- **Link Clicks report** (`smartlinker_clicks`) — top clicked internal links ranked #1…#n with anchor text, source-post counts, and 7-day / 30-day / all-time filtering.
- **Domain report** (`smartlinker_domains`) — every external domain you link to, with link/post counts and a per-domain breakdown. Links now record their `host`.
- **Money Pages** (`smartlinker_money_pages`) — mark revenue-critical pages; tracked by inbound link count, weakest first, with health badges.
- **Content Ignoring** — exclude whole categories/tags (by ID or slug) from suggestions, alongside the existing post-ID exclusions.
- **URL Changer preview** — see exactly how many links across how many posts a change will affect before committing.

## Phase 12 (implemented) — dashboard + AI panel redesign

- **Dashboard rebuild** — time-aware greeting, prioritised recommendation cards (with Impact: High/Medium badges and a Review action), four stat tiles (posts crawled with an indexed progress bar, clicks tracked, links created, time saved — each with a 30-day-vs-previous delta), a **Link Distribution** stacked bar (internal vs external with an interpretation note), five score cards (**Site Health Score** 0–100, **Link Quality Score** 0–10, Link Coverage, Orphaned Posts, Broken Links — each rated Excellent / Good / Needs Work / Poor), plus **Quick Actions** and **Features you're not using** (auto-detected from your configuration).
- **Links-created tracking** — `Slk_Link::log_insertion()` records every link SmartLinker inserts, so "Links created (30d)" and "Time saved" are real measured numbers rather than estimates.
- **AI suggestions with % match** — the model now returns a 0–100 relevance score per suggestion, sorted best-first and filtered below 50. Suggestions render as cards: target title + colour-coded **% match** badge, an **Anchor text** pill, the reason, the destination path, and an **Add Link** button.
- **Dedicated AI Suggestions page** (`smartlinker_ai`) — AI is now its own sidebar section, not just an editor button. It shows connection status, the model in use, and whether the editor button is on; pick any post, run the analysis, and insert links straight from the page. Also linked from the dashboard's Quick Actions.

## Phase 13 (implemented) — tabbed settings + functional AI Settings

- **Tabbed settings screen** — horizontal tabs (General Settings · Content Ignoring · AI Settings). Each tab submits only its own fields and saving **merges** over the stored option, so saving one tab never wipes another (`Slk_Settings::schema()` declares each tab's fields and checkboxes; checkboxes need listing because they are absent from POST when unticked).
- **AI Settings tab — every control is wired to real behaviour:**
  - *Connection* — status, **Disconnect** (clears the key and turns AI off), API key field (only overwritten when a new value is typed), and model selector.
  - *Suggestion behaviour* — Use AI-powered suggestions; **Prefer page title as anchor** (uses the target's title when it appears verbatim in your text, producing much tighter anchors); Only show top suggestions + how many; **Minimum match score** slider; Don't process posts older than.
  - *Performance & data* — **Cache AI results** (reuses results until the post's content changes so you are not billed twice), request timeout, and **Clear cached AI data**.
  - *Content processing status* — live counts of processable / analysed / remaining posts and logged errors.
  - *System error log* — real OpenAI failures with timestamp, message and HTTP code, plus a clear action.

  Display-time filters (minimum match, top N) are applied to cached results, so tuning them never triggers a new API call.

## Phase 14 (implemented) — modern Links Report

- **Rebuilt as a real data table** (`Slk_Report::table_rows()` does search, filter, sort and pagination in one query): sortable columns (title, inbound, outbound, external, clicks) with `?` tooltips, a search box, filter presets (All / Orphaned / No outbound links / Money pages), per-page selector, and pagination.
- **Stats stay on top** — published items, internal, external, orphaned and broken links, with the last two linking to their reports.
- **Expandable rows** — the `+` on any row loads that post's actual links via AJAX: inbound links (with the post they come from) and every link in the post (external chips, broken-status badges).
- **Modern presentation** — colour-coded metric pills (red for zero inbound), post-type and ★ Money page chips, publish date, and icon actions (edit / view / add inbound links).

### Bug fixed in this phase

`URL Changer` used `str_replace()`, so replacing `?p=99` also corrupted `?p=999999`, and `/guide` would have hit `/guide-2`. Replacement and preview now use a boundary-aware pattern (`Slk_URLChanger::match_pattern()`) that requires a delimiter after the URL and tolerates an optional trailing slash.

## Status

The plugin implements the full core plus the advanced tier of an internal-linking product, at feature parity with (and in places beyond) LinkWhisper's free version. Remaining ideas are optional: live Google OAuth for Search Console, and per-page-builder (Elementor/Divi) content adapters.
- Google Search Console + AI (OpenAI) suggestions
- Page-builder (Gutenberg/Elementor/Divi) content support

## Install

Copy the `smartlinker` folder into `wp-content/plugins/` and activate. Then run **SmartLinker → Reports → Re-scan site** to index existing links.
