<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The suggestion engine. Given a post, find relevant internal link targets by
 * matching phrases in the post's content against other posts' titles/keywords.
 */
class Slk_Suggestion
{
    public function register()
    {
        // Re-index links whenever a post is saved so reports stay fresh.
        add_action('save_post', [__CLASS__, 'on_save_post'], 20, 3);
        add_action('save_post', [__CLASS__, 'flush_corpus'], 21);
        add_action('deleted_post', [__CLASS__, 'flush_corpus']);
    }

    /**
     * Words that pad out titles without saying what a page is about. Filtered
     * when deriving an anchor from a title, so "The Ultimate Guide to Burr
     * Grinders" yields "burr grinders" rather than "ultimate guide".
     */
    public static function weak_words()
    {
        // Function words are weak by definition, so the two lists merge —
        // otherwise "Espresso Basics for Beginners" yields the anchor "for".
        return array_flip(array_merge(Slk_Settings::base_stop_words(), [
            'best','top','ultimate','complete','definitive','essential','comprehensive','perfect',
            'simple','easy','quick','fast','free','new','latest','updated','full','real','true',
            'guide','guides','tutorial','tutorials','review','reviews','overview','introduction',
            'intro','basics','beginner','beginners','advanced','tips','tricks','hacks','ideas',
            'examples','example','list','checklist','template','templates','steps','step',
            'ways','way','reasons','benefits','things','thing','stuff','everything','anything',
            'need','needs','know','knowing','must','should','can','will','does','doing',
            'how','what','why','when','where','which','who','whom','whose',
            'vs','versus','compared','comparison','alternatives','alternative','options','option',
            'cheap','cheapest','affordable','budget','price','prices','pricing','cost','costs',
            'buy','buying','purchase','choosing','choose','picking','pick','selecting','select',
            'using','use','used','make','making','made','get','getting','start','starting',
            'guide to','year','years','today','now','online','home','diy',
            'collectors','enthusiasts','accessories','performance','professional','expert','experts',
            'popular','common','important','useful','helpful','great','good','better','amazing',
            'awesome','powerful','effective','proven','ranked','rated','tested','honest',
            'small','big','large','major','minor','basic','general','specific','various','different',
            'more','most','less','least','many','much','few','several','other','another','same',
            'first','second','third','last','next','final','one','two','three','four','five',
            'about','into','with','without','from','over','under','above','below','between',
            'guideline','guidelines','method','methods','process','system','systems','solution',
            'solutions','service','services','company','companies','business','businesses',
            'people','person','users','user','customer','customers','client','clients',
            'part','parts','type','types','kind','kinds','sort','sorts','range','ranges',
            'read','reading','learn','learning','find','finding','see','look','looking',
            'here','there','everywhere','anywhere','somewhere','online','offline',
        ]));
    }

    /**
     * Choose the anchor for a target, in descending order of how well the
     * phrase represents that target:
     *
     *   1. the target's focus keyword, verbatim
     *   2. the longest 2+ word sub-phrase of that keyword
     *   3. a meaningful phrase from the target's title (weak words stripped)
     *   4. a single salient word from the title
     *
     * This is the heart of the fix. Previously anchors were drawn from the
     * target's most frequent BODY words, which is how "equipment" ended up
     * pointing at a car-wash page: the word was in that article, but it is not
     * what the article is called. An anchor has to name its destination.
     *
     * @return array{0:string,1:string}|null [matched text, tier label]
     */
    /**
     * Public because Slk_Diagnose replays this to explain a decision. A
     * diagnostic that reimplemented the rules would drift from the engine and
     * send you looking in the wrong place.
     */
    public static function pick_anchor($plain, array $tokens, $target, $focus_keyword, $use_stem, array $existing, $min_len)
    {
        $weak = self::weak_words();
        $title = trim(wp_strip_all_tags($target->post_title));

        $try = function ($phrase) use ($plain, $tokens, $use_stem, $existing, $min_len) {
            $phrase = trim((string) $phrase);
            if ($phrase === '' || mb_strlen($phrase) < $min_len) {
                return null;
            }
            if (isset($existing[mb_strtolower($phrase, 'UTF-8')])) {
                return null; // already an anchor in this post
            }
            $matched = Slk_Word::find($plain, $tokens, $phrase, $use_stem);
            if ($matched === null || isset($existing[mb_strtolower($matched, 'UTF-8')])) {
                return null;
            }
            return $matched;
        };

        // Phase 1 — the focus keyword, exactly.
        if ($focus_keyword !== '') {
            $hit = $try($focus_keyword);
            if ($hit !== null) {
                return [$hit, 'exact keyword'];
            }

            // Phase 2 — longest 2+ word run of the focus keyword.
            $kw_words = preg_split('/\s+/u', $focus_keyword, -1, PREG_SPLIT_NO_EMPTY);
            for ($len = count($kw_words) - 1; $len >= 2; $len--) {
                for ($i = 0; $i + $len <= count($kw_words); $i++) {
                    $hit = $try(implode(' ', array_slice($kw_words, $i, $len)));
                    if ($hit !== null) {
                        return [$hit, 'partial keyword'];
                    }
                }
            }
        }

        // Phase 3 — a phrase from the title, once the padding around it is
        // removed. Weak words are trimmed from the ENDS, never split on:
        // "How to Make Pour Over Coffee" has to yield "pour over coffee", and
        // splitting on "over" would leave only fragments. Requires at least
        // one content word to survive, so "The Ultimate Guide" yields nothing.
        $title_words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($title, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY);

        $is_weak = function ($w) use ($weak, $min_len) {
            return isset($weak[$w]) || is_numeric($w) || mb_strlen($w) < $min_len;
        };

        $start = 0;
        $end = count($title_words) - 1;
        while ($start <= $end && $is_weak($title_words[$start])) { $start++; }
        while ($end >= $start && $is_weak($title_words[$end])) { $end--; }

        if ($end > $start) {
            $span = array_slice($title_words, $start, $end - $start + 1);
            // Longest first, then progressively shorter, always ending on a
            // content word so the anchor never trails off in a preposition.
            for ($len = count($span); $len >= 2; $len--) {
                for ($i = 0; $i + $len <= count($span); $i++) {
                    $sub = array_slice($span, $i, $len);
                    if ($is_weak($sub[0]) || $is_weak($sub[count($sub) - 1])) {
                        continue;
                    }
                    $hit = $try(implode(' ', $sub));
                    if ($hit !== null) {
                        return [$hit, 'title phrase'];
                    }
                }
            }
        }

        // Phase 4 — one salient word from the title. Never a weak word, and
        // never a word that isn't in the title, which is what stopped
        // "equipment" linking to a page not called that.
        $singles = [];
        foreach ($title_words as $w) {
            if (isset($weak[$w]) || is_numeric($w) || mb_strlen($w) < $min_len) {
                continue;
            }
            $singles[$w] = mb_strlen($w);
        }
        arsort($singles); // prefer the longer, more specific noun
        foreach (array_keys($singles) as $w) {
            $hit = $try($w);
            if ($hit !== null) {
                return [$hit, 'salient word'];
            }
        }

        return null;
    }

    /**
     * Is this phrase good enough to be a link anchor at all?
     *
     * Rejects the two things users actually complain about:
     *   - single generic words ("equipment", "documents", "municipal") that
     *     merely occur in the destination's body;
     *   - filler phrases ("before you", "our guide") made entirely of words
     *     that are common across the whole site.
     *
     * The test is per-WORD rarity, not per-phrase: on a small site a two-word
     * phrase is almost always unique, so phrase-level rarity says nothing,
     * whereas "before" and "you" are demonstrably common either way.
     *
     * @param string $phrase       lowercased candidate anchor
     * @param string $target_title the destination's title
     */
    protected static function anchor_is_usable($phrase, $target_title, array $corpus)
    {
        $words = preg_split('/\s+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return false;
        }

        // One-word anchor: it has to be what the destination is called.
        if (count($words) === 1) {
            return mb_stripos((string) $target_title, $phrase) !== false;
        }

        // Multi-word: the phrase as a whole, or one of its words, must carry
        // real signal. Checking words alone wrongly rejects pairs like
        // "single origin", where neither word is rare but the pair is.
        $best = Slk_Post::idf($phrase, $corpus);
        foreach ($words as $w) {
            $best = max($best, Slk_Post::idf($w, $corpus));
            $best = max($best, Slk_Post::idf(Slk_Word::stem($w), $corpus));
        }
        return $best >= (float) Slk_Settings::get('min_anchor_idf', 0.5);
    }

    /**
     * Is the source article actually about this archive's topic?
     *
     * A term has no body text of its own, so it is profiled from the titles
     * of the posts filed under it — cheap, and enough to tell "coffee gear"
     * from "township retail".
     */
    protected static function term_is_related($term, array $source_terms, array $corpus, $min_rel)
    {
        static $profiles = [];
        $key = $term->taxonomy . ':' . $term->term_id;

        if (!isset($profiles[$key])) {
            $titles = get_posts([
                'post_type'   => Slk_Settings::enabled_post_types(),
                'post_status' => 'publish',
                'numberposts' => 30,
                'fields'      => 'ids',
                'tax_query'   => [[
                    'taxonomy' => $term->taxonomy,
                    'field'    => 'term_id',
                    'terms'    => (int) $term->term_id,
                ]],
            ]);
            // The term's own name is deliberately NOT in the profile. Include
            // it and any article that merely repeats the word scores highly:
            // a post about shop "equipment" then looks more related to a
            // coffee Equipment category than a pour-over guide does, because
            // the guide talks about grinders and kettles instead.
            $text = '';
            foreach ($titles as $id) {
                $text .= get_the_title($id) . ' ';
            }
            $fake = (object) ['post_title' => $text, 'post_content' => ''];
            $profiles[$key] = Slk_Post::term_vector($fake, 120);
        }

        if (empty($profiles[$key])) {
            return false;
        }
        return Slk_Post::relatedness($source_terms, $profiles[$key], $corpus) >= $min_rel;
    }

    /**
     * post id => its focus keyword.
     *
     * Two sources: the SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress), read
     * live so a newly written post works with no setup, and SmartLinker's own
     * Target Keywords. Which wins is a real editorial choice, so it is a
     * setting — but the SEO plugin is the default, because that is where
     * people actually maintain focus keywords.
     */
    /** Targets the editor has explicitly rejected for a given source post. */
    const REJECTED_META = '_slk_rejected';

    public static function rejected($post_id)
    {
        $v = get_post_meta((int) $post_id, self::REJECTED_META, true);
        return is_array($v) ? array_map('intval', $v) : [];
    }

    /**
     * Turning down a suggestion has to stick — otherwise the same wrong link
     * is offered again on every scan and the panel becomes noise.
     */
    public static function ajax_reject()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $target_id = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        $anchor = isset($_POST['phrase']) ? sanitize_text_field(wp_unslash($_POST['phrase'])) : '';

        $list = self::rejected($post_id);
        if ($target_id && !in_array($target_id, $list, true)) {
            $list[] = $target_id;
            update_post_meta($post_id, self::REJECTED_META, $list);
        }

        // The per-post list above stops this pair coming back HERE. This
        // teaches the rest of the site, so the same wrong anchor does not have
        // to be turned down once per post forever.
        $learned = Slk_Rejection::record($post_id, $target_id, $anchor);

        wp_send_json_success([
            'rejected' => $target_id,
            'learned'  => $learned,
            'message'  => $learned['anchor_blocked']
                ? sprintf(
                    /* translators: %s: the anchor text */
                    __('Got it — “%s” will not be suggested anywhere now.', 'smartlinker'),
                    $anchor
                )
                : ($learned['pair_blocked']
                    ? __('Got it — that pairing will not be suggested anywhere now.', 'smartlinker')
                    : ''),
        ]);
    }

    public static function focus_keywords()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $own = [];
        foreach (Slk_TargetKeyword::all() as $row) {
            $id = (int) $row->post_id;
            // First wins; the list is newest-first, so that is the most
            // recently chosen keyword for the post.
            if (!isset($own[$id])) {
                $own[$id] = (string) $row->keyword;
            }
        }

        $live = (int) Slk_Settings::get('use_seo_plugin_keywords', 1) === 1
            ? Slk_KeywordImport::live_focus_keywords()
            : [];

        // + preserves the left-hand side's keys, so the preferred source goes first.
        $map = Slk_Settings::get('focus_keyword_source', 'seo_plugin') === 'smartlinker'
            ? $own + $live
            : $live + $own;

        return $map;
    }

    /** post id => cluster name, so same-cluster targets can be favoured. */
    public static function cluster_map()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $map = [];
        foreach (Slk_Cluster::snapshot()['clusters'] as $c) {
            foreach ($c['ids'] as $id) {
                $map[(int) $id] = $c['name'];
            }
        }
        return $map;
    }

    /**
     * cluster name => its parent's name, for taxonomy-derived clusters.
     *
     * A child category is genuinely related to its parent, so those links are
     * worth something — just less than a link inside one cluster. AI-themed
     * clusters are flat, so this is empty for them.
     */
    protected static function cluster_parents()
    {
        static $parents = null;
        if ($parents !== null) {
            return $parents;
        }
        $parents = [];
        $by_id = [];
        foreach (get_categories(['hide_empty' => false]) as $cat) {
            $by_id[(int) $cat->term_id] = $cat->name;
        }
        foreach (get_categories(['hide_empty' => false]) as $cat) {
            if ($cat->parent && isset($by_id[(int) $cat->parent])) {
                $parents[$cat->name] = $by_id[(int) $cat->parent];
            }
        }
        return $parents;
    }

    /**
     * How the two posts' clusters relate: 1.0 same, 0.5 parent/child, else 0.
     *
     * @return array{0:float,1:string} [weight, phrase for the explanation]
     */
    public static function cluster_relation($a, $b)
    {
        if ($a === null || $b === null) {
            return [0.0, ''];
        }
        if ($a === $b) {
            return [1.0, __('same topic cluster', 'smartlinker')];
        }
        $parents = self::cluster_parents();
        $a_parent = isset($parents[$a]) ? $parents[$a] : null;
        $b_parent = isset($parents[$b]) ? $parents[$b] : null;
        if ($a_parent === $b || $b_parent === $a || ($a_parent !== null && $a_parent === $b_parent)) {
            return [0.5, __('related cluster', 'smartlinker')];
        }
        return [0.0, ''];
    }

    /**
     * The one-line justification shown under a suggestion.
     *
     * Public so the AI engine produces the identical sentence: the model
     * chooses the anchor, but "how related are these two posts" is measured
     * the same way regardless of who picked it. Two different explanations
     * for the same pair would be indefensible.
     *
     * @param WP_Post $post    the article being edited
     * @param int     $target_id  the destination
     * @param string  $closing sentence describing where the anchor came from
     */
    public static function explain($post, $target_id, $closing = '')
    {
        $corpus = Slk_Post::corpus();
        $target = get_post($target_id);
        if (!$target) {
            return $closing;
        }

        $source_terms = Slk_Post::term_vector($post);
        $target_terms = Slk_Post::term_vector($target);

        $vectors = Slk_Embedding::is_enabled() ? Slk_Embedding::all() : [];
        $sid = (int) $post->ID;
        $tid = (int) $target_id;

        if (isset($vectors[$sid], $vectors[$tid])) {
            $sim = Slk_Embedding::cosine($vectors[$sid], $vectors[$tid]);
        } else {
            $raw = Slk_Post::relatedness($source_terms, $target_terms, $corpus);
            $sim = min(1.0, $raw / max(0.01, (float) Slk_Settings::get('good_similarity', 0.12)));
        }

        $clusters = self::cluster_map();
        $rel = self::cluster_relation(
            isset($clusters[$sid]) ? $clusters[$sid] : null,
            isset($clusters[$tid]) ? $clusters[$tid] : null
        );

        return trim(sprintf(
            /* translators: 1: similarity 0-1, 2: overlap percentage, 3: cluster note, 4: anchor note */
            __('Post similarity %1$s, keyword overlap %2$d%%%3$s. %4$s', 'smartlinker'),
            number_format_i18n($sim, 2),
            (int) round(self::keyword_overlap($source_terms, $target_terms, $corpus) * 100),
            $rel[1] !== '' ? ', ' . $rel[1] : '',
            $closing
        ));
    }

    /** Plain-English description of how the anchor was found. */
    protected static function tier_phrase($tier)
    {
        switch ($tier) {
            case 'exact keyword':
                return __('Anchor matches target keyword exactly.', 'smartlinker');
            case 'partial keyword':
                return __('Anchor matches part of target keyword.', 'smartlinker');
            case 'title phrase':
                return __('Phrase taken from the target post title.', 'smartlinker');
            case 'salient word':
                return __('Salient noun phrase from target post.', 'smartlinker');
        }
        return '';
    }

    /**
     * Share of the target's distinctive vocabulary that also appears in the
     * source, weighted by rarity. 1.0 means the source already talks about
     * everything the target is about.
     */
    public static function keyword_overlap(array $source_terms, array $target_terms, array $corpus)
    {
        if (empty($target_terms)) {
            return 0.0;
        }
        $total = 0.0;
        $shared = 0.0;
        foreach (array_keys($target_terms) as $term) {
            $w = Slk_Post::idf($term, $corpus);
            $total += $w;
            if (isset($source_terms[$term])) {
                $shared += $w;
            }
        }
        return $total > 0 ? min(1.0, $shared / $total) : 0.0;
    }

    /** Editing content changes site-wide phrase rarity. */
    public static function flush_corpus()
    {
        Slk_Post::flush_corpus();
    }

    public static function on_save_post($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!in_array($post->post_type, Slk_Settings::enabled_post_types(), true)) {
            return;
        }
        Slk_Link::index_post($post_id);
    }

    /**
     * Generate outbound internal-link suggestions for a post.
     *
     * @return array of ['phrase','target_id','target_title','url','score']
     */
    public static function for_post($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return [];
        }

        $limit = (int) Slk_Settings::get('suggestion_limit', 20);
        $min_len = (int) Slk_Settings::get('min_keyword_length', 3);
        $use_stem = (int) Slk_Settings::get('use_stemming', 1) === 1;

        // Text of existing links is removed, so a phrase that is already a
        // link can never be offered again.
        $plain = Slk_Post::linkable_text($post->post_content);
        $content_lc = mb_strtolower($plain, 'UTF-8');
        if ($content_lc === '') {
            return [];
        }
        // Tokenize the content once for stem-aware phrase matching.
        $tokens = Slk_Word::tokenize($plain);

        // Phrases already linked in this post — don't suggest them again.
        $existing = self::existing_anchor_texts($post->post_content);
        // Places this post already links to — one link per destination is enough.
        $linked = Slk_Post::existing_link_targets($post->post_content);

        $targets = Slk_Post::candidate_targets($post_id);
        $suggestions = [];

        // Site-wide phrase rarity, plus this post's own vocabulary, so each
        // target can be judged on whether it is actually about the same thing.
        $corpus = Slk_Post::corpus();
        $source_terms = Slk_Post::term_vector($post);
        $min_rel = (float) Slk_Settings::get('min_relatedness', 0.06);
        $min_score = (float) Slk_Settings::get('suggestion_min_score', 1.2);

        $focus = self::focus_keywords();
        $clusters = self::cluster_map();
        $source_cluster = isset($clusters[(int) $post_id]) ? $clusters[(int) $post_id] : null;

        $vectors = Slk_Embedding::is_enabled() ? Slk_Embedding::all() : [];
        $source_vec = isset($vectors[(int) $post_id]) ? $vectors[(int) $post_id] : null;

        // Pass 1 — anchor first. A candidate with no phrase that names it is
        // dropped before it is ever scored, so scoring only ever ranks links
        // that could actually be written.
        $scored = [];
        $rejected = array_flip(self::rejected($post_id));

        foreach ($targets as $t) {
            $tid = (int) $t->ID;
            if (isset($linked['ids'][$tid])) {
                continue; // already linked from this post
            }
            if (isset($rejected[$tid])) {
                continue; // the editor already turned this one down
            }

            $anchor = self::pick_anchor(
                $plain,
                $tokens,
                $t,
                isset($focus[$tid]) ? $focus[$tid] : '',
                $use_stem,
                $existing,
                $min_len
            );
            if ($anchor === null) {
                continue;
            }

            // Anchors you have turned down often enough are retired site-wide.
            if (Slk_Rejection::blocked($anchor[0], $tid)) {
                continue;
            }

            $rel = self::cluster_relation(
                $source_cluster,
                isset($clusters[$tid]) ? $clusters[$tid] : null
            );

            $target_terms = Slk_Post::term_vector($t);

            // Meaning beats vocabulary: when both posts are in the semantic
            // index, compare embeddings. That is what lets a page about
            // "which grinder should I buy" match "Choosing a Burr Grinder",
            // which share no words at all. Falls back to TF-IDF per pair, so
            // a partly-built index still works.
            $semantic = ($source_vec !== null && isset($vectors[$tid]));
            if ($semantic) {
                $sim_raw = Slk_Embedding::cosine($source_vec, $vectors[$tid]);
                $floor = Slk_Embedding::FLOOR;
            } else {
                $sim_raw = Slk_Post::relatedness($source_terms, $target_terms, $corpus);
                $floor = $min_rel;
            }

            if ($sim_raw < $floor) {
                continue;
            }

            // A one-word anchor is rejected only when the word is so common on
            // this site that it identifies nothing — "coffee" on a coffee blog.
            //
            // Deliberately a SHARE OF POSTS, not an IDF floor. A site's core
            // nouns are frequent by definition, so a rarity threshold blocked
            // "grinder" and "espresso" — the very anchors you want. The word
            // already has to appear in the target's title (see pick_anchor),
            // which is what actually keeps anchors honest.
            if (mb_strpos($anchor[0], ' ') === false) {
                $w = mb_strtolower($anchor[0], 'UTF-8');
                $df = 0;
                foreach ([$w, Slk_Word::stem($w)] as $form) {
                    if (isset($corpus['df'][$form])) {
                        $df = max($df, (int) $corpus['df'][$form]);
                    }
                }
                $docs = max(1, (int) $corpus['docs']);
                if ($df / $docs > (float) Slk_Settings::get('max_anchor_doc_share', 0.5)) {
                    continue;
                }
            }

            $scored[] = [
                'target'    => $t,
                'anchor'    => $anchor[0],
                'tier'      => $anchor[1],
                'sim_raw'   => $sim_raw,
                'semantic'  => $semantic,
                'kw_overlap' => self::keyword_overlap($source_terms, $target_terms, $corpus),
                'cluster'   => $rel[0],
                'cluster_note' => $rel[1],
            ];
        }

        // Pass 2 — score. Similarity is scaled against an absolute reference,
        // NOT ranked against the other candidates: with a percentile there is
        // always a best candidate, so an article with nothing related on the
        // site still got a confident-looking top suggestion.
        $good_sim = max(0.01, (float) Slk_Settings::get('good_similarity', 0.12));
        $min_conf = (float) Slk_Settings::get('min_confidence', 0.30);

        // How badly each candidate needs inbound links. This nudges the ORDER
        // only — see below for why it is deliberately kept out of confidence.
        $equity_boost = (float) Slk_Settings::get('equity_boost', 0.15);
        $need = $equity_boost > 0 ? Slk_Equity::need_map() : [];

        // How much the anchor's provenance is trusted. A last-resort single
        // word needs stronger topical evidence than an exact keyword match.
        $tier_weight = [
            'exact keyword'   => 1.00,
            'partial keyword' => 0.92,
            'title phrase'    => 0.85,
            'salient word'    => 0.70,
        ];

        foreach ($scored as $row) {
            $t = $row['target'];
            $tid = (int) $t->ID;

            // Each measure gets its own scale. An embedding cosine of 0.45 is
            // weakly related; a TF-IDF cosine of 0.45 would be enormous.
            if (!empty($row['semantic'])) {
                $span = max(0.01, Slk_Embedding::GOOD - Slk_Embedding::FLOOR);
                $sim_norm = min(1.0, max(0.0, ($row['sim_raw'] - Slk_Embedding::FLOOR) / $span));
            } else {
                $sim_norm = min(1.0, $row['sim_raw'] / $good_sim);
            }

            // Relatedness of the two articles dominates; keyword overlap and
            // shared cluster refine it.
            $confidence = (0.75 * $sim_norm) + (0.15 * $row['kw_overlap']) + (0.10 * $row['cluster']);
            $confidence *= isset($tier_weight[$row['tier']]) ? $tier_weight[$row['tier']] : 0.7;

            if ($confidence < $min_conf) {
                continue;
            }

            // Ordering, not confidence.
            //
            // Confidence answers "is this the right link?" — a claim about
            // correctness. How starved the target is answers "is this a useful
            // link?" — a claim about value. Folding the second into the first
            // would make the percentage on screen a lie: a weak match to a
            // neglected page would read as a strong match. So the boost moves
            // the row up the list while the displayed figure stays honest.
            //
            // Capped by the setting (default 15%), which is enough to reorder
            // near-equals and never enough to lift a poor match above a good one.
            $target_need = isset($need[$tid]) ? $need[$tid] : 0.0;
            $priority = $confidence * (1 + ($equity_boost * $target_need));

            $title = trim(wp_strip_all_tags($t->post_title));
            $suggestions['post|' . $tid] = [
                'phrase'       => $row['anchor'],
                'context'      => Slk_Post::sentence_containing($plain, $row['anchor']),
                'target_id'    => $tid,
                'target_title' => $title,
                'url'          => get_permalink($tid),
                'path'         => wp_make_link_relative(get_permalink($tid)),
                'match'        => (int) round($confidence * 100),
                'score'        => round($priority * 10, 2),
                'need'         => round($target_need, 2),
                'reason'       => sprintf(
                    /* translators: 1: similarity 0-1, 2: overlap percentage, 3: cluster note, 4: how the anchor was chosen, 5: equity note */
                    __('Post similarity %1$s, keyword overlap %2$d%%%3$s. %4$s%5$s', 'smartlinker'),
                    number_format_i18n($row['semantic'] ? $row['sim_raw'] : $sim_norm, 2),
                    (int) round($row['kw_overlap'] * 100),
                    $row['cluster_note'] !== '' ? ', ' . $row['cluster_note'] : '',
                    self::tier_phrase($row['tier']),
                    ''  // the equity note is appended after sorting, see below
                ),
            ];
        }

        // Also match against imported external-site URLs (their derived titles).
        foreach (Slk_Sitemap::candidates() as $ext) {
            $phrase = mb_strtolower(trim($ext->title), 'UTF-8');
            if (mb_strlen($phrase) < $min_len || isset($existing[$phrase])) {
                continue;
            }
            if (isset($linked['urls'][rtrim(strtolower($ext->url), '/')])) {
                continue; // this post already links there
            }
            $matched = Slk_Word::find($plain, $tokens, $phrase, $use_stem);
            if ($matched === null || isset($existing[mb_strtolower($matched, 'UTF-8')])) {
                continue;
            }
            if (!self::anchor_is_usable($phrase, $ext->title, $corpus)) {
                continue;
            }
            $word_count = count(preg_split('/\s+/', $phrase));
            $confidence = 0.40;
            $key = $phrase . '|ext|' . $ext->url;
            if (!isset($suggestions[$key])) {
                $suggestions[$key] = [
                    'phrase'       => $matched,
                    'context'      => Slk_Post::sentence_containing($plain, $matched),
                    'target_id'    => 0,
                    'target_title' => $ext->title . ' (' . $ext->site_label . ')',
                    'url'          => $ext->url,
                    'path'         => $ext->url,
                    'match'        => (int) round($confidence * 100),
                    'score'        => round($confidence * 10, 2),
                    'reason'       => __('Imported page from a site you own. Anchor matches its title.', 'smartlinker'),
                    'external'     => true,
                ];
            }
        }

        // Optionally include taxonomy terms (categories/tags) as link targets.
        if ((int) Slk_Settings::get('link_taxonomies', 1) === 1) {
            foreach (Slk_Term::candidates() as $term) {
                $phrase = mb_strtolower(trim($term->name), 'UTF-8');
                if (mb_strlen($phrase) < $min_len || isset($existing[$phrase])) {
                    continue;
                }
                if (isset($linked['urls'][rtrim(strtolower($term->url), '/')])) {
                    continue; // already linked to this archive
                }
                $matched = Slk_Word::find($plain, $tokens, $phrase, $use_stem);
                if ($matched === null || isset($existing[mb_strtolower($matched, 'UTF-8')])) {
                    continue;
                }
                if (!self::anchor_is_usable($phrase, $term->name, $corpus)) {
                    continue;
                }
                // An archive is only worth linking when the article really
                // belongs to that topic. Without this, a coffee "Equipment"
                // category gets linked from an article about spaza shops
                // purely because both say the word "equipment".
                if (!self::term_is_related($term, $source_terms, $corpus, $min_rel)) {
                    continue;
                }
                // Archives are ranked below posts on purpose: a specific
                // article is almost always the more useful destination.
                $confidence = 0.45;
                $key = $phrase . '|term|' . $term->term_id;
                if (!isset($suggestions[$key])) {
                    $suggestions[$key] = [
                        'phrase'       => $matched,
                        'context'      => Slk_Post::sentence_containing($plain, $matched),
                        'target_id'    => 0,
                        'target_title' => $term->name . ' (' . $term->taxonomy_label . ')',
                        'url'          => $term->url,
                        'path'         => wp_make_link_relative($term->url),
                        'match'        => (int) round($confidence * 100),
                        'score'        => round($confidence * 10, 2),
                        'reason'       => __('Archive for a topic this article belongs to. Anchor from the term name.', 'smartlinker'),
                        'term'         => true,
                    ];
                }
            }
        }

        // Sort by score desc, keep one suggestion per phrase (best target wins).
        usort($suggestions, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $seen_phrase = [];
        $final = [];
        foreach ($suggestions as $s) {
            $pk = mb_strtolower($s['phrase'], 'UTF-8');
            if (isset($seen_phrase[$pk])) {
                continue;
            }
            $seen_phrase[$pk] = true;
            $final[] = $s;
            if (count($final) >= $limit) {
                break;
            }
        }

        // Explain a promotion only where one demonstrably happened: this row
        // sits above another with a HIGHER confidence, which can only be the
        // equity boost. Tying the note to an arbitrary need threshold meant it
        // stayed silent on a row that had visibly jumped the queue — the one
        // case where the reader most needs telling.
        $count = count($final);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($final[$j]['match'] > $final[$i]['match']) {
                    $final[$i]['promoted'] = true;
                    $final[$i]['reason'] = trim($final[$i]['reason'])
                        . ' ' . __('Ranked above a closer match because few links point here.', 'smartlinker');
                    break;
                }
            }
        }

        return $final;
    }

    /**
     * Lowercased set of anchor texts already present in the content.
     */
    public static function existing_anchor_texts($content)
    {
        $out = [];
        foreach (Slk_Link::parse($content) as $link) {
            $out[mb_strtolower($link['anchor'], 'UTF-8')] = true;
        }
        return $out;
    }

    /**
     * Recover the original casing of a phrase as it appears in the content.
     */
    public static function original_case($content, $phrase_lc)
    {
        $plain = Slk_Post::plain_text($content);
        if (preg_match('/\b(' . preg_quote($phrase_lc, '/') . ')\b/iu', $plain, $m)) {
            return $m[1];
        }
        return $phrase_lc;
    }

    /* ---------------------------------------------------------------------
     * Rendering + AJAX
     * ------------------------------------------------------------------- */

    public static function render_meta_box($post)
    {
        // The AI button always renders — hiding it made an unconfigured feature
        // look like a missing one. When it can't run, say which of the two
        // things is missing rather than leaving the user to guess.
        $slk_ai = Slk_AI::availability();
        $slk_ai_enabled = $slk_ai['enabled'];
        $slk_ai_reason  = $slk_ai['reason'];

        include SLK_PLUGIN_DIR . 'templates/meta_box.php';
    }

    public static function ajax_get_suggestions()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        wp_send_json_success(['suggestions' => self::for_post($post_id)]);
    }

    public static function ajax_insert_link()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $phrase = isset($_POST['phrase']) ? sanitize_text_field(wp_unslash($_POST['phrase'])) : '';
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        if ($phrase === '' || $url === '') {
            wp_send_json_error(['message' => __('Missing phrase or URL.', 'smartlinker')]);
        }

        // Snapshot before, so this one-click write can be undone. There is no
        // editor open here to hold an unsaved change and no review step.
        $before = get_post($post_id)->post_content;

        $result = Slk_Link::insert_into_post($post_id, $phrase, $url, [
            'new_tab'  => Slk_Settings::get('links_open_new_tab'),
            'nofollow' => Slk_Settings::get('links_nofollow'),
        ]);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        Slk_Activity::record(
            $post_id,
            'insert',
            sprintf(
                /* translators: 1: anchor text, 2: destination title */
                __('Linked “%1$s” to %2$s', 'smartlinker'),
                $phrase,
                get_the_title(url_to_postid($url)) ?: $url
            ),
            $before,
            get_post($post_id)->post_content,
            $url
        );

        wp_send_json_success(['message' => __('Link inserted.', 'smartlinker')]);
    }

    /* ---------------------------------------------------------------------
     * Inbound suggestions — "which other posts should link TO this one".
     * ------------------------------------------------------------------- */

    /**
     * Find source posts that could link to the given target post.
     *
     * @return array of ['phrase','source_id','source_title','source_edit','url','score']
     */
    public static function inbound_for_post($target_id)
    {
        $target = get_post($target_id);
        if (!$target) {
            return [];
        }

        $limit = (int) Slk_Settings::get('suggestion_limit', 20);
        $min_len = (int) Slk_Settings::get('min_keyword_length', 3);
        $target_url = get_permalink($target_id);

        // Titles of every other published post, so we never anchor a phrase
        // that is really another post's name (that phrase should link there,
        // not here). Map: lowercased title => post id.
        $other_titles = self::published_title_map();

        // Inbound is the same question as outbound, asked from the other end:
        // find a phrase in some OTHER post that names THIS one. So it runs the
        // identical 4-phase cascade and the identical gates — previously it
        // had its own body-keyword logic, which is how "like" ended up
        // proposed as an anchor pointing at a page called "Sample Page".
        $title = trim(wp_strip_all_tags($target->post_title));
        $corpus = Slk_Post::corpus();
        $target_terms = Slk_Post::term_vector($target);
        $focus = self::focus_keywords();
        $focus_kw = isset($focus[(int) $target_id]) ? $focus[(int) $target_id] : '';

        $clusters = self::cluster_map();
        $target_cluster = isset($clusters[(int) $target_id]) ? $clusters[(int) $target_id] : null;

        $vectors = Slk_Embedding::is_enabled() ? Slk_Embedding::all() : [];
        $target_vec = isset($vectors[(int) $target_id]) ? $vectors[(int) $target_id] : null;

        $use_stem = (int) Slk_Settings::get('use_stemming', 1) === 1;
        $min_rel = (float) Slk_Settings::get('min_relatedness', 0.10);
        $min_conf = (float) Slk_Settings::get('min_confidence', 0.30);
        $good_sim = max(0.01, (float) Slk_Settings::get('good_similarity', 0.12));
        $tier_weight = [
            'exact keyword' => 1.00, 'partial keyword' => 0.92,
            'title phrase'  => 0.85, 'salient word'    => 0.70,
        ];

        $sources = Slk_Post::candidate_targets($target_id);
        $suggestions = [];

        foreach ($sources as $src) {
            $sid = (int) $src->ID;

            // Ignore text that is already inside a link.
            $src_plain = Slk_Post::linkable_text($src->post_content);
            if ($src_plain === '') {
                continue;
            }
            if (self::post_links_to($sid, $target_id)) {
                continue;
            }
            $src_linked = Slk_Post::existing_link_targets($src->post_content);
            if (isset($src_linked['ids'][(int) $target_id])) {
                continue;
            }

            $existing = self::existing_anchor_texts($src->post_content);
            $tokens = Slk_Word::tokenize($src_plain);

            $anchor = self::pick_anchor($src_plain, $tokens, $target, $focus_kw, $use_stem, $existing, $min_len);
            if ($anchor === null) {
                continue; // nothing in this post names the target
            }

            $src_terms = Slk_Post::term_vector($src);
            $semantic = ($target_vec !== null && isset($vectors[$sid]));
            if ($semantic) {
                $sim_raw = Slk_Embedding::cosine($target_vec, $vectors[$sid]);
                $floor = Slk_Embedding::FLOOR;
            } else {
                $sim_raw = Slk_Post::relatedness($target_terms, $src_terms, $corpus);
                $floor = $min_rel;
            }
            if ($sim_raw < $floor) {
                continue;
            }

            // A one-word anchor used across most of the site names nothing.
            if (mb_strpos($anchor[0], ' ') === false) {
                $w = mb_strtolower($anchor[0], 'UTF-8');
                $df = 0;
                foreach ([$w, Slk_Word::stem($w)] as $form) {
                    if (isset($corpus['df'][$form])) {
                        $df = max($df, (int) $corpus['df'][$form]);
                    }
                }
                if ($df / max(1, (int) $corpus['docs']) > (float) Slk_Settings::get('max_anchor_doc_share', 0.5)) {
                    continue;
                }
            }

            if ($semantic) {
                $span = max(0.01, Slk_Embedding::GOOD - Slk_Embedding::FLOOR);
                $sim_norm = min(1.0, max(0.0, ($sim_raw - Slk_Embedding::FLOOR) / $span));
            } else {
                $sim_norm = min(1.0, $sim_raw / $good_sim);
            }
            $rel = self::cluster_relation($target_cluster, isset($clusters[$sid]) ? $clusters[$sid] : null);
            $overlap = self::keyword_overlap($target_terms, $src_terms, $corpus);

            $confidence = (0.75 * $sim_norm) + (0.15 * $overlap) + (0.10 * $rel[0]);
            $confidence *= isset($tier_weight[$anchor[1]]) ? $tier_weight[$anchor[1]] : 0.7;
            if ($confidence < $min_conf) {
                continue;
            }

            $suggestions['src|' . $sid] = [
                'phrase'       => $anchor[0],
                'context'      => Slk_Post::sentence_containing($src_plain, $anchor[0]),
                'source_id'    => $sid,
                'source_title' => trim(wp_strip_all_tags($src->post_title)),
                'source_edit'  => Slk_Admin::edit_url($sid, 'raw'),
                'target_title' => $title,
                'url'          => $target_url,
                'path'         => wp_make_link_relative($target_url),
                'match'        => (int) round($confidence * 100),
                'score'        => round($confidence * 10, 2),
                'reason'       => sprintf(
                    __('Post similarity %1$s, keyword overlap %2$d%%%3$s. %4$s', 'smartlinker'),
                    number_format_i18n($semantic ? $sim_raw : $sim_norm, 2),
                    (int) round($overlap * 100),
                    $rel[1] !== '' ? ', ' . $rel[1] : '',
                    self::tier_phrase($anchor[1])
                ),
            ];
        }

        usort($suggestions, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        // One suggestion per source post (its best-scoring phrase).
        $seen = [];
        $final = [];
        foreach ($suggestions as $s) {
            if (isset($seen[$s['source_id']])) {
                continue;
            }
            $seen[$s['source_id']] = true;
            $final[] = $s;
            if (count($final) >= $limit) {
                break;
            }
        }
        return $final;
    }

    /**
     * Map of lowercased post title => post id for all published, enabled posts.
     * Cached per request.
     */
    protected static function published_title_map()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        global $wpdb;
        $map = [];
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return $map;
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($ph)",
            $types
        ));
        foreach ($rows as $r) {
            $t = mb_strtolower(trim(wp_strip_all_tags($r->post_title)), 'UTF-8');
            if ($t !== '') {
                $map[$t] = (int) $r->ID;
            }
        }
        return $map;
    }

    /**
     * Whether a source post already has an internal link to the target.
     */
    public static function post_links_to($source_id, $target_id)
    {
        global $wpdb;
        $table = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table} WHERE post_id = %d AND target_post_id = %d AND type = 'internal' LIMIT 1",
            $source_id,
            $target_id
        ));
    }

    /**
     * Posts most in need of inbound links (fewest first), for the picker.
     */
    public static function inbound_candidates($limit = 200)
    {
        return Slk_Report::post_rows($limit, 0);
    }

    public static function render_inbound_page()
    {
        $candidates = self::inbound_candidates(200);
        include SLK_PLUGIN_DIR . 'templates/inbound.php';
    }

    public static function ajax_get_inbound()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $target_id = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        $engine = isset($_POST['engine']) ? sanitize_key($_POST['engine']) : 'standard';
        if (!$target_id || !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }

        if ($engine === 'ai') {
            $rows = Slk_AI::inbound_for($target_id);
            if (is_wp_error($rows)) {
                wp_send_json_error(['message' => $rows->get_error_message()]);
            }
        } else {
            $rows = self::inbound_for_post($target_id);
        }

        wp_send_json_success([
            'target_title' => get_the_title($target_id),
            'engine'       => $engine,
            'suggestions'  => $rows,
        ]);
    }
}
