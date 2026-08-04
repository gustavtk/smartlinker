<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helpers for reading post content and extracting searchable text/keywords.
 */
class Slk_Post
{
    /** Cached site-wide phrase document-frequencies (see corpus()). */
    const CORPUS_TRANSIENT = 'slk_corpus_df';

    /**
     * Posts whose content is loaded at once by walk_content().
     *
     * Sized for the worst realistic case rather than the average: a few
     * unusually long posts landing in the same slice. At 200 × ~50KB that is
     * roughly 10MB of peak content, which sits comfortably inside WordPress's
     * default memory limit alongside everything else already loaded.
     */
    const WALK_CHUNK = 200;

    /**
     * Walk post content in fixed-size slices.
     *
     * Several places here need to look at the body of every post on the site:
     * the placement report, the auto-link rule counts, the URL rewriter. Each
     * used to do it with one query and no LIMIT, which loads the entire
     * corpus into a single PHP array. On a small site that is invisible. On a
     * few thousand posts it is a fatal memory error — and the person hitting
     * it does not experience "the placement report is heavy", they experience
     * the plugin being broken, on a page that gives no clue why.
     *
     * The ids are collected first and held for the whole walk. That is
     * deliberate and cheap: 100,000 ids is a few hundred KB of integers. It
     * is the CONTENT that has to be bounded, and this loads one slice at a
     * time and lets each go before fetching the next.
     *
     * @param array    $ids      post ids, from the caller's own query
     * @param callable $callback receives (object $row) with ID, post_content
     *                           and any extra columns asked for
     * @param array    $columns  extra columns to select alongside content
     * @param int      $chunk    override the slice size
     * @return int number of posts walked
     */
    public static function walk_content(array $ids, callable $callback, array $columns = [], $chunk = self::WALK_CHUNK)
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return 0;
        }

        $select = self::walk_columns($columns);

        $chunk = max(1, (int) $chunk);
        $walked = 0;

        foreach (array_chunk($ids, $chunk) as $slice) {
            $ph = implode(',', array_fill(0, count($slice), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT {$select} FROM {$wpdb->posts} WHERE ID IN ($ph)",
                $slice
            ));

            foreach ($rows as $row) {
                $callback($row);
                $walked++;
            }

            // Let the slice go before the next one is fetched. Without this
            // the peak is the whole corpus again and the chunking is theatre:
            // $rows would stay referenced until it is reassigned, so the old
            // and new slices would briefly coexist.
            unset($rows);

            // And drop what WordPress cached along the way.
            //
            // This is not belt-and-braces, it is the larger half of the fix.
            // A callback that calls get_permalink() or Slk_Admin::edit_url()
            // — which the placement report does, for every row — makes
            // WordPress load that post into its in-memory object cache,
            // content and all. That cache is never trimmed inside a request.
            // So the slicing above would bound the query and the object cache
            // would quietly re-accumulate the entire corpus behind it:
            // measured at 1,200 posts, chunking alone brought the walk down
            // to +14MB and the object cache put +82MB straight back.
            //
            // Only ids this walk touched are cleared, and wp_cache_delete is
            // used rather than clean_post_cache() because the latter fires
            // actions that other plugins listen to — a cache eviction is not
            // a post edit and must not look like one.
            foreach ($slice as $id) {
                wp_cache_delete($id, 'posts');
                wp_cache_delete($id, 'post_meta');
            }
        }

        return $walked;
    }

    /**
     * Invalidate the post-meta object cache after a direct SQL delete.
     *
     * WordPress caches post meta. A `$wpdb->delete()` against the postmeta
     * table removes the rows and leaves that cache untouched, so anything
     * reading back with get_post_meta() keeps returning values that no longer
     * exist. Without a persistent object cache the mistake hides — the cache
     * dies with the request. With Redis, Memcached or LiteSpeed it persists,
     * and a "clear" button that clearly worked does nothing visible.
     *
     * That shipped twice: clearing the semantic index, and clearing cached AI
     * results. Hence one helper rather than two fixes.
     *
     * Nothing is passed in on purpose. Deriving the list from the rows you
     * just deleted is circular — if a previous buggy clear already removed
     * them there is nothing to find, nothing gets invalidated, and the stale
     * cache survives forever. That is exactly how the second bug was
     * reported.
     */
    public static function flush_meta_cache()
    {
        if (function_exists('wp_cache_supports') && wp_cache_supports('flush_group')) {
            wp_cache_flush_group('post_meta');
            return;
        }
        foreach (self::candidate_targets(0, 5000) as $p) {
            wp_cache_delete((int) $p->ID, 'post_meta');
        }
    }

    /** Columns walk_content() will select beyond ID and post_content. */
    const WALK_COLUMNS = ['post_title', 'post_type', 'post_status', 'post_name', 'post_date'];

    /**
     * Build the SELECT list for a walk.
     *
     * Column names cannot be passed to $wpdb->prepare() as placeholders — they
     * are interpolated into the SQL string — so anything not on the allow-list
     * is dropped rather than escaped. Every caller today passes a hardcoded
     * array, but "the only caller is trusted" is a property that quietly stops
     * being true, and the cost of the list is one comparison.
     *
     * @return string a safe comma-separated column list
     */
    public static function walk_columns(array $columns)
    {
        $select = ['ID', 'post_content'];
        foreach ($columns as $c) {
            if (in_array($c, self::WALK_COLUMNS, true)) {
                $select[] = $c;
            }
        }
        return implode(', ', array_unique($select));
    }

    /**
     * Ids of published posts in the enabled types — the cheap half of a walk.
     *
     * @param array $statuses post statuses to include
     */
    public static function ids_for_walk(array $statuses = ['publish'])
    {
        global $wpdb;

        $types = Slk_Settings::enabled_post_types();
        if (empty($types) || empty($statuses)) {
            return [];
        }

        $type_ph = implode(',', array_fill(0, count($types), '%s'));
        $status_ph = implode(',', array_fill(0, count($statuses), '%s'));

        // Ordered so the slices tile the same set every time; without it a
        // later slice could repeat or skip rows.
        // phpcs:ignore WordPress.DB.PreparedSQL
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status IN ($status_ph) AND post_type IN ($type_ph)
             ORDER BY ID ASC",
            array_merge($statuses, $types)
        )));
    }

    /**
     * Strip a post's content down to plain, lowercased words for matching.
     */
    public static function plain_text($content)
    {
        $content = wp_strip_all_tags((string) $content);
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $content));
    }

    /**
     * Plain text with the contents of existing <a> elements removed.
     *
     * plain_text() only strips the tags, which leaves the anchor text behind —
     * so a phrase that is already a link would still be offered as a
     * suggestion. Dropping the whole element makes linked text invisible to
     * the matcher, which is what we want everywhere we look for new links.
     */
    public static function linkable_text($content)
    {
        $content = preg_replace('#<a\b[^>]*>.*?</a>#is', ' ', (string) $content);
        return self::plain_text($content);
    }

    /**
     * The sentence containing $phrase, so the editor can show where a
     * suggested link would actually land. Falls back to a trimmed window
     * around the match when no sentence boundary is found.
     */
    public static function sentence_containing($plain, $phrase, $window = 160)
    {
        $plain = trim((string) $plain);
        if ($plain === '' || $phrase === '') {
            return '';
        }

        foreach (preg_split('/(?<=[.!?])\s+/u', $plain) as $sentence) {
            if (mb_stripos($sentence, $phrase) !== false) {
                $sentence = trim($sentence);
                if (mb_strlen($sentence) <= 320) {
                    return $sentence;
                }
                break;
            }
        }

        // Long sentence (or none found): return a window around the match.
        $pos = mb_stripos($plain, $phrase);
        if ($pos === false) {
            return mb_substr($plain, 0, $window) . '…';
        }
        $start = max(0, $pos - (int) ($window / 2));
        $out = mb_substr($plain, $start, $window + mb_strlen($phrase));
        return ($start > 0 ? '…' : '') . trim($out) . '…';
    }

    /**
     * IDs this post already links to, plus the raw URLs, so we never suggest
     * a second link to somewhere the post already points.
     *
     * @return array ['ids' => [id => true], 'urls' => [normalised url => true]]
     */
    public static function existing_link_targets($content)
    {
        $ids = [];
        $urls = [];
        foreach (Slk_Link::parse($content) as $link) {
            $urls[rtrim(strtolower($link['url']), '/')] = true;
            list($type, $target_id) = Slk_Link::classify($link['url']);
            if ($target_id > 0) {
                $ids[(int) $target_id] = true;
            }
        }
        return ['ids' => $ids, 'urls' => $urls];
    }

    /**
     * Build a keyword frequency map from a post's title + content, minus
     * ignore words and short tokens. Returns [phrase => weight].
     */
    public static function keywords($post, $max = 40)
    {
        $ignore = Slk_Settings::ignore_words();
        $min_len = (int) Slk_Settings::get('min_keyword_length', 3);

        $text = self::plain_text($post->post_title . ' ' . $post->post_content);
        $text = mb_strtolower($text, 'UTF-8');

        // Tokenize on non-word (unicode aware).
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return [];
        }

        $unigrams = [];
        $bigrams = [];
        $prev = null;
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) < $min_len || isset($ignore[$tok]) || is_numeric($tok)) {
                $prev = null;
                continue;
            }
            $unigrams[$tok] = ($unigrams[$tok] ?? 0) + 1;
            if ($prev !== null) {
                $bg = $prev . ' ' . $tok;
                $bigrams[$bg] = ($bigrams[$bg] ?? 0) + 2; // weight phrases higher
            }
            $prev = $tok;
        }

        $all = $bigrams + $unigrams;
        arsort($all);
        return array_slice($all, 0, $max, true);
    }

    /**
     * Candidate target posts (published, enabled types), excluding one id.
     *
     * @return array of row objects: ID, post_title, post_content, post_type
     */
    public static function candidate_targets($exclude_id = 0, $limit = 500)
    {
        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return [];
        }

        $excluded = self::excluded_ids();
        $excluded[] = (int) $exclude_id;
        $excluded = array_filter(array_unique($excluded));

        $type_ph = implode(',', array_fill(0, count($types), '%s'));
        $sql = "SELECT ID, post_title, post_content, post_type
                FROM {$wpdb->posts}
                WHERE post_status = 'publish'
                  AND post_type IN ($type_ph)";
        $args = $types;

        if (!empty($excluded)) {
            $ex_ph = implode(',', array_fill(0, count($excluded), '%d'));
            $sql .= " AND ID NOT IN ($ex_ph)";
            $args = array_merge($args, $excluded);
        }
        $sql .= ' LIMIT %d';
        $args[] = (int) $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    /**
     * How many posts each phrase appears in, across the whole site.
     *
     * Without this, term frequency alone decides everything: a word that
     * appears in every article ("equipment", "documents", "municipal") looks
     * exactly as meaningful as one that appears in two. That is what makes an
     * article about spaza shops link to a car wash franchise.
     *
     * @return array{docs:int, df:array<string,int>}
     */
    public static function corpus()
    {
        $cached = get_transient(self::CORPUS_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        $docs = 0;
        $df = [];
        foreach (self::candidate_targets(0, 1000) as $post) {
            $docs++;
            // Distinct terms only — we want "in how many documents", not
            // "how many times". Both forms are recorded: the raw phrases that
            // anchor scoring looks up, and the stemmed single words that
            // term_vector() / relatedness() look up.
            $seen = [];
            foreach (array_keys(self::keywords($post, 60)) as $phrase) {
                $seen[$phrase] = true;
            }
            foreach (array_keys(self::term_vector($post, 200)) as $term) {
                $seen[$term] = true;
            }
            $title = mb_strtolower(trim(wp_strip_all_tags($post->post_title)), 'UTF-8');
            if ($title !== '') {
                $seen[$title] = true;
            }
            foreach (array_keys($seen) as $term) {
                $df[$term] = isset($df[$term]) ? $df[$term] + 1 : 1;
            }
        }

        $corpus = ['docs' => $docs, 'df' => $df];
        set_transient(self::CORPUS_TRANSIENT, $corpus, HOUR_IN_SECONDS);
        return $corpus;
    }

    public static function flush_corpus()
    {
        delete_transient(self::CORPUS_TRANSIENT);
    }

    /**
     * How distinctive a phrase is, from 0 (in every post — worthless as an
     * anchor) to 1 (unique to one post).
     */
    public static function idf($phrase, ?array $corpus = null)
    {
        if ($corpus === null) {
            $corpus = self::corpus();
        }
        $docs = max(1, (int) $corpus['docs']);
        if ($docs < 2) {
            return 1.0; // nothing to compare against yet
        }
        $seen = isset($corpus['df'][$phrase]) ? (int) $corpus['df'][$phrase] : 0;
        $idf = log(($docs + 1) / ($seen + 1));
        return max(0.0, min(1.0, $idf / log($docs + 1)));
    }

    /**
     * Single-word frequency map for a post, for topic comparison.
     *
     * Deliberately NOT keywords(): that is dominated by two-word phrases,
     * which almost never match verbatim between two documents, so every pair
     * of posts looks unrelated. Topic similarity has to be measured on the
     * vocabulary the two articles genuinely share.
     *
     * @return array<string,int> word => count
     */
    public static function term_vector($post, $max = 200)
    {
        $ignore = Slk_Settings::ignore_words();
        $min_len = (int) Slk_Settings::get('min_keyword_length', 3);

        $text = mb_strtolower(self::plain_text($post->post_title . ' ' . $post->post_content), 'UTF-8');
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return [];
        }

        $tf = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) < $min_len || isset($ignore[$tok]) || is_numeric($tok)) {
                continue;
            }
            $tok = Slk_Word::stem($tok);
            $tf[$tok] = isset($tf[$tok]) ? $tf[$tok] + 1 : 1;
        }
        arsort($tf);
        return array_slice($tf, 0, $max, true);
    }

    /**
     * How much two posts are actually about the same thing: TF-IDF cosine
     * over their shared vocabulary. Words that appear all over the site carry
     * almost no IDF, so sharing "equipment" barely moves the number while
     * sharing "grinder" does.
     *
     * @return float 0..1
     */
    public static function relatedness(array $a_tf, array $b_tf, ?array $corpus = null)
    {
        if ($corpus === null) {
            $corpus = self::corpus();
        }
        if (empty($a_tf) || empty($b_tf)) {
            return 0.0;
        }

        $weigh = function (array $tf) use ($corpus) {
            $v = [];
            foreach ($tf as $term => $count) {
                // Damped term frequency: a word used 20 times is not 20x the
                // signal of one used once.
                $v[$term] = (1 + log($count)) * self::idf($term, $corpus);
            }
            return $v;
        };

        $a = $weigh($a_tf);
        $b = $weigh($b_tf);

        $dot = 0.0;
        foreach ($a as $term => $w) {
            if (isset($b[$term])) {
                $dot += $w * $b[$term];
            }
        }
        if ($dot <= 0) {
            return 0.0;
        }
        $na = 0.0;
        foreach ($a as $w) { $na += $w * $w; }
        $nb = 0.0;
        foreach ($b as $w) { $nb += $w * $w; }
        if ($na <= 0 || $nb <= 0) {
            return 0.0;
        }
        return $dot / sqrt($na * $nb);
    }

    /**
     * Post IDs the user has excluded in settings.
     */
    public static function excluded_ids()
    {
        $raw = (string) Slk_Settings::get('excluded_post_ids', '');
        $ids = array_filter(array_map('intval', preg_split('/[\s,]+/', $raw)));
        return array_values(array_unique(array_merge($ids, self::ids_in_excluded_terms())));
    }

    /**
     * Post IDs belonging to any excluded category/tag (Content Ignoring).
     * Terms are configured as a comma-separated list of term IDs or slugs.
     */
    public static function ids_in_excluded_terms()
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];

        $raw = trim((string) Slk_Settings::get('excluded_terms', ''));
        if ($raw === '') {
            return $cache;
        }
        $tokens = array_filter(array_map('trim', preg_split('/[\s,]+/', $raw)));
        if (empty($tokens)) {
            return $cache;
        }

        $taxes = get_taxonomies(['public' => true], 'names');
        unset($taxes['post_format']);
        $tax_query = ['relation' => 'OR'];
        foreach ($taxes as $tax) {
            $ids = [];
            $slugs = [];
            foreach ($tokens as $t) {
                if (ctype_digit($t)) {
                    $ids[] = (int) $t;
                } else {
                    $slugs[] = $t;
                }
            }
            if (!empty($ids)) {
                $tax_query[] = ['taxonomy' => $tax, 'field' => 'term_id', 'terms' => $ids];
            }
            if (!empty($slugs)) {
                $tax_query[] = ['taxonomy' => $tax, 'field' => 'slug', 'terms' => $slugs];
            }
        }
        if (count($tax_query) < 2) {
            return $cache;
        }

        $cache = get_posts([
            'post_type'   => Slk_Settings::enabled_post_types(),
            'post_status' => 'publish',
            'numberposts' => 1000,
            'fields'      => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery
            'tax_query'   => $tax_query,
        ]);
        return $cache;
    }
}
