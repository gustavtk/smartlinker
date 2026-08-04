=== SmartLinker ===
Contributors: gustavtk
Tags: internal links, seo, linking, anchor text, broken links
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.54.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Suggests internal links as you write, finds the ones you are missing, and reports on the linking you already have.

== Description ==

SmartLinker reads what you have already published and works out which of your posts should be linking to each other.

It answers two questions. While you write: *what should this post link to?* And the harder one, the moment you hit publish: *which of my existing posts should link to this new one?* A new post starts with zero inbound links, which is exactly why it lands on an orphan report a week later. SmartLinker shows you the answer in the editor, while you are still there.

= What it does =

**Suggestions in the editor.** A sidebar in the block editor and a meta box in the classic editor. Both work in two directions — outbound links for the post you are writing, and inbound links from posts that already mention it. One click inserts the link.

**Anchor text chosen, not guessed.** Four passes, in order of confidence: an exact focus keyword, a partial keyword, a phrase from the target's title, then the most distinctive word available. Every suggestion tells you which pass produced it, so you can judge it rather than trust it.

**Reports that answer a question.** Link overview, anchor text, broken links, clicks, outbound domains, link equity, cannibalisation, link placement, and trends over time. The last one is the only one that tells you whether the linking is getting *better*.

**Link equity.** PageRank across your own internal links, plus click depth from the front page. Pages nothing links to surface immediately, and the suggestion engine quietly prefers them when two targets are equally relevant.

**Cannibalisation.** Finds posts on your own site competing for the same query — the ones splitting your links between them so neither wins.

**Auto-linking.** Keyword rules applied across the site, with a preview of exactly what each rule would change before anything is written.

**An undo log.** Every link the plugin inserts is recorded with the post as it was beforehand, and can be reversed from the Activity page.

= Nothing happens without you =

No link is ever inserted automatically. Suggestions are suggestions until you apply them, and everything applied can be undone.

= Works with your SEO plugin =

Focus keywords are read live from Rank Math, Yoast, All in One SEO or SEOPress if any of them is installed. Without one, you can set target keywords yourself, or SmartLinker falls back to post titles.

== External services ==

SmartLinker contacts exactly one third-party service, and only if you switch it on.

**OpenAI — optional, and off by default.** The AI features are disabled until you enter your own OpenAI API key. Nothing is sent anywhere until you do, and the plugin works fully without them. When enabled, post titles and content are sent to `api.openai.com` to generate embeddings and suggestions, billed to your own account. Terms: https://openai.com/policies/terms-of-use — privacy policy: https://openai.com/policies/privacy-policy

Everything else the plugin loads — stylesheets, scripts and the Work Sans typeface — is served from the plugin itself. Nothing is fetched from a CDN, so no third party sees your administrators' IP addresses.

No data is sent to the plugin author, and there is no telemetry of any kind.

== Installation ==

1. Upload the `smartlinker` folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New.
2. Activate it.
3. Open **SmartLinker → Dashboard** and work through the short checklist. The first step indexes the links you already have; the reports are empty until it runs, because nothing has been looked at yet.

== Frequently Asked Questions ==

= Will it change my posts without asking? =

No. Every suggestion waits for you to apply it. Auto-linking is applied when a page is displayed and never written to your database, so switching it off restores the original text immediately.

The two operations that do rewrite many posts at once — the Link Map and the site-wide URL Changer — show you a preview first, and both are recorded on the Activity page, where the whole operation can be undone in one click. A post you have edited since is refused rather than overwritten, so your later work is never discarded.

= What happens to my links if I delete the plugin? =

They stay. A link SmartLinker inserted is an ordinary link in your post content — it is not held together by the plugin. Deleting SmartLinker removes its own reports and indexes, never your content.

= Does it delete its database tables when removed? =

Only if you ask it to. That setting is off by default, and stays off, because people delete a plugin to reinstall it, to move hosts, or to test a conflict — and none of those should destroy a link index and an undo history.

= Do I need an OpenAI key? =

No. Everything except the AI features works without one: suggestions, all the reports, auto-linking, equity, and the editor panels. The key adds semantic matching — finding posts that mean the same thing in different words — and it is billed to your own OpenAI account, not to us.

= Does it slow down my site? =

The front end loads nothing except a small click-tracking script, and only if click tracking is on. Everything else runs in the admin. Heavy work — link indexing, equity, opportunity scanning — runs in batches with a progress bar, so it does not time out on large sites.

= Will it work on a large site? =

The reports that read every post do so in slices rather than loading the whole site into memory at once. On a 1,200-post site the placement report peaks at about 28 MB.

= Can I translate it? =

Yes. All 1,108 strings, in PHP and JavaScript, are translatable, and `languages/smartlinker.pot` is included.

== Changelog ==

= 0.54.0 =
* The plugin now costs zero database queries on a front-end page view when auto-linking and redirects are not in use. Previously it ran one query per request plus one per post displayed.
* Fixed auto-link rules imported from CSV not taking effect until the cache expired.

= 0.53.0 =
* Link Opportunities are now ranked by likely impact rather than anchor relevance alone, and each row explains why it sits where it does.
* Search Console data now feeds the ranking. Pages sitting on page two of Google with real impressions are surfaced first, because those are where an internal link actually moves something.
* Redirecting links can be repointed at their destination in bulk, undoable in one click. Only permanent (301/308) redirects are followed.

= 0.52.0 =
* Bulk changes can now be undone. The Link Map and the site-wide URL Changer record every post they rewrite, and the Activity page can reverse a whole operation in one click.

= 0.51.1 =
* Fixed CSV exports being corrupted on PHP 8.4. A deprecation notice was being written into the downloaded file ahead of the header row.

= 0.51.0 =
* The Work Sans typeface is now bundled with the plugin instead of loaded from Google Fonts. No administrator's IP address is sent to a third party, and the plugin no longer loads any external asset.

= 0.50.0 =
* Added readme.txt and a changelog. Guards added so the version and the declared support floors cannot drift between the plugin header and the readme.

= 0.49.2 =
* Added guards ensuring uninstall removes every table, cron event and meta key the plugin creates.

= 0.49.1 =
* Fixed seven PHP 8.4 deprecation notices caused by passing a null edit-link to `esc_url()`.
* Audited against WordPress coding standards: no XSS, SQL injection or CSRF issues found.

= 0.49.0 =
* Added JavaScript tests covering the code that rewrites post content.

= 0.48.0 =
* Click log now has a retention setting (default one year), an index, and a rate limit. Previously it grew without bound.

= 0.47.0 =
* Admin CSS and JavaScript are now minified — 42% smaller over the wire.

= 0.46.0 =
* The admin JavaScript is now translatable.

= 0.44.0 =
* Added a translation template. Fixed a plugin header that had drifted 27 versions out of date.

= 0.43.0 =
* Bounded memory on the four reports that read every post, which could exhaust memory on large sites.

= 0.42.0 =
* CSV export for every report. Fixed spreadsheet formula injection in the three existing exports.

= 0.41.0 =
* Added trend history: the reports can now show whether linking is improving, not only what it is now.

= 0.40.0 =
* Added a sortable Links column to the Posts list.

== Upgrade Notice ==

= 0.53.0 =
Link Opportunities are re-ordered by impact. If you have imported Search Console data, near-ranking pages now come first.

= 0.52.0 =
Adds undo for bulk operations. Previously the Link Map and URL Changer could not be reversed.

= 0.51.0 =
Removes the last third-party asset. The admin font is now served locally rather than from Google.

= 0.43.0 =
Fixes a potential memory exhaustion on sites with more than a few thousand posts. Worth taking if your site is large.

= 0.42.0 =
Fixes a spreadsheet formula-injection issue in CSV exports. Recommended.
