<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Why wasn't this post suggested?"
 *
 * The engine drops a candidate at whichever gate it fails and moves on. That
 * is right for producing suggestions and useless for understanding them: when
 * a link you expected does not appear, there is nothing to read. Every number
 * the decision turned on is computed and then thrown away.
 *
 * This replays the same pipeline for one source→target pair and stops at the
 * first gate that rejects it, reporting the actual figures and what would have
 * to change. It deliberately calls the same helpers the engine calls rather
 * than reimplementing the rules — a diagnostic that disagrees with the engine
 * is worse than none, because it sends you looking in the wrong place.
 *
 * The gates, in the order the engine applies them:
 *
 *   1. the target is a candidate at all
 *   2. this post does not already link there
 *   3. you have not rejected it here
 *   4. an anchor can be found in your text
 *   5. that anchor is not retired site-wide
 *   6. the two posts are related enough
 *   7. a one-word anchor is not too common to mean anything
 *   8. confidence clears the minimum
 *   9. it ranks inside the per-post limit
 */
class Slk_Diagnose
{
    /**
     * @return array{ok:bool,stage:string,headline:string,detail:string,metrics:array,fixes:array}
     */
    public static function explain($source_id, $target_id)
    {
        $source_id = (int) $source_id;
        $target_id = (int) $target_id;

        $source = get_post($source_id);
        $target = get_post($target_id);

        if (!$source || !$target) {
            return self::verdict('input', __('One of those posts no longer exists.', 'smartlinker'), '');
        }
        if ($source_id === $target_id) {
            return self::verdict('input', __('That is the same post twice.', 'smartlinker'), __('A post cannot link to itself.', 'smartlinker'));
        }

        $settings = [
            'limit'      => (int) Slk_Settings::get('suggestion_limit', 20),
            'min_len'    => (int) Slk_Settings::get('min_keyword_length', 3),
            'use_stem'   => (int) Slk_Settings::get('use_stemming', 1) === 1,
            'min_rel'    => (float) Slk_Settings::get('min_relatedness', 0.06),
            'min_conf'   => (float) Slk_Settings::get('min_confidence', 0.30),
            'good_sim'   => max(0.01, (float) Slk_Settings::get('good_similarity', 0.12)),
            'max_share'  => (float) Slk_Settings::get('max_anchor_doc_share', 0.5),
        ];

        $plain = Slk_Post::linkable_text($source->post_content);
        if (trim($plain) === '') {
            return self::verdict(
                'content',
                __('The source post has no linkable text.', 'smartlinker'),
                __('Anchors are taken from the words already in the post, so an empty or image-only post can never produce one.', 'smartlinker')
            );
        }

        $metrics = [
            'target_title'   => trim(wp_strip_all_tags($target->post_title)),
            'focus_keyword'  => '',
            'anchor'         => '',
            'tier'           => '',
            'similarity'     => null,
            'floor'          => null,
            'semantic'       => false,
            'confidence'     => null,
            'min_confidence' => $settings['min_conf'],
        ];

        /* -- 1. is the target even a candidate? ------------------------- */

        $candidates = Slk_Post::candidate_targets($source_id);
        $found = null;
        foreach ($candidates as $c) {
            if ((int) $c->ID === $target_id) {
                $found = $c;
                break;
            }
        }
        if (!$found) {
            return self::verdict(
                'candidate',
                __('That post is not eligible as a link target.', 'smartlinker'),
                __('It is excluded before anything is scored — usually because its post type is not enabled, it is not published, or it is listed under Settings → Content Ignoring.', 'smartlinker'),
                $metrics,
                [
                    __('Check Settings → General → post types.', 'smartlinker'),
                    __('Check Settings → Content Ignoring for its post ID or category.', 'smartlinker'),
                    __('Confirm the post is published, not draft or private.', 'smartlinker'),
                ]
            );
        }

        /* -- 2. already linked? ----------------------------------------- */

        $linked = Slk_Post::existing_link_targets($source->post_content);
        if (isset($linked['ids'][$target_id])) {
            return self::verdict(
                'linked',
                __('This post already links there.', 'smartlinker'),
                __('SmartLinker never suggests a second link to a page the post already points at — that is one wasted click, not two useful ones.', 'smartlinker'),
                $metrics
            );
        }

        /* -- 3. rejected here? ------------------------------------------ */

        if (in_array($target_id, Slk_Suggestion::rejected($source_id), true)) {
            return self::verdict(
                'rejected_here',
                __('You rejected this suggestion on this post.', 'smartlinker'),
                __('Rejections stick, so the same suggestion is not offered again on this post.', 'smartlinker'),
                $metrics,
                [__('Clear it by editing the post and removing the _slk_rejected custom field, or reject fewer things here.', 'smartlinker')]
            );
        }

        /* -- 4. can an anchor be found? --------------------------------- */

        $tokens = Slk_Word::tokenize($plain);
        $existing = Slk_Suggestion::existing_anchor_texts($source->post_content);
        $focus = Slk_Suggestion::focus_keywords();
        $metrics['focus_keyword'] = isset($focus[$target_id]) ? $focus[$target_id] : '';

        $anchor = Slk_Suggestion::pick_anchor(
            $plain,
            $tokens,
            $found,
            $metrics['focus_keyword'],
            $settings['use_stem'],
            $existing,
            $settings['min_len']
        );

        if ($anchor === null) {
            $tried = self::anchor_candidates($found, $metrics['focus_keyword']);
            return self::verdict(
                'anchor',
                __('No usable anchor text exists in this post.', 'smartlinker'),
                __('This is the most common reason, and it is deliberate. An anchor has to be words already in your writing that also name the destination — SmartLinker will not invent a phrase or link something vague just to make a suggestion appear.', 'smartlinker'),
                $metrics,
                [
                    $tried
                        ? sprintf(
                            /* translators: %s: comma-separated phrases */
                            __('It looked for these and found none of them in your text: %s', 'smartlinker'),
                            implode(', ', array_map(function ($p) {
                                return '“' . $p . '”';
                            }, array_slice($tried, 0, 6)))
                        )
                        : __('The target has no focus keyword and its title is all filler words, so there was nothing to look for.', 'smartlinker'),
                    __('Fix it by mentioning the destination naturally in this post, or by giving the target a focus keyword that matches how you actually write about it.', 'smartlinker'),
                ]
            );
        }

        $metrics['anchor'] = $anchor[0];
        $metrics['tier'] = $anchor[1];

        /* -- 5. retired site-wide? -------------------------------------- */

        if (Slk_Rejection::blocked($anchor[0], $target_id)) {
            $scope = Slk_Rejection::anchor_blocked($anchor[0])
                ? __('everywhere on the site', 'smartlinker')
                : __('for this destination', 'smartlinker');
            return self::verdict(
                'learned',
                sprintf(
                    /* translators: 1: anchor text, 2: scope */
                    __('You have retired “%1$s” %2$s.', 'smartlinker'),
                    $anchor[0],
                    $scope
                ),
                __('Rejecting the same suggestion on enough different posts retires it site-wide.', 'smartlinker'),
                $metrics,
                [__('Bring it back under Reports → Anchor Text → Suppressed, or raise the thresholds in Settings → General.', 'smartlinker')]
            );
        }

        /* -- 6. related enough? ----------------------------------------- */

        $corpus = Slk_Post::corpus();
        $source_terms = Slk_Post::term_vector($source);
        $target_terms = Slk_Post::term_vector($found);

        $vectors = Slk_Embedding::is_enabled() ? Slk_Embedding::all() : [];
        $source_vec = isset($vectors[$source_id]) ? $vectors[$source_id] : null;
        $semantic = ($source_vec !== null && isset($vectors[$target_id]));

        if ($semantic) {
            $sim = Slk_Embedding::cosine($source_vec, $vectors[$target_id]);
            $floor = Slk_Embedding::FLOOR;
        } else {
            $sim = Slk_Post::relatedness($source_terms, $target_terms, $corpus);
            $floor = $settings['min_rel'];
        }

        $metrics['similarity'] = $sim;
        $metrics['floor'] = $floor;
        $metrics['semantic'] = $semantic;

        if ($sim < $floor) {
            return self::verdict(
                'similarity',
                __('The two posts are not related enough.', 'smartlinker'),
                sprintf(
                    /* translators: 1: similarity, 2: floor, 3: which measure */
                    __('Similarity is %1$s, and the floor is %2$s (%3$s).', 'smartlinker'),
                    number_format($sim, 3),
                    number_format($floor, 3),
                    $semantic
                        ? __('measured by meaning, from the semantic index', 'smartlinker')
                        : __('measured by shared distinctive vocabulary', 'smartlinker')
                ),
                $metrics,
                array_filter([
                    $semantic
                        ? ''
                        : __('Build the semantic index under Settings → AI. Word overlap misses posts that mean the same thing in different words, which is usually what is happening here.', 'smartlinker'),
                    __('Lower “minimum relatedness” in Settings if you want a wider net — at the cost of looser suggestions.', 'smartlinker'),
                ])
            );
        }

        /* -- 7. one-word anchor too common? ------------------------------ */

        if (mb_strpos($anchor[0], ' ') === false) {
            $w = mb_strtolower($anchor[0], 'UTF-8');
            $df = 0;
            foreach ([$w, Slk_Word::stem($w)] as $form) {
                if (isset($corpus['df'][$form])) {
                    $df = max($df, (int) $corpus['df'][$form]);
                }
            }
            $docs = max(1, (int) $corpus['docs']);
            $share = $df / $docs;
            $metrics['anchor_share'] = $share;

            if ($share > $settings['max_share']) {
                return self::verdict(
                    'common_anchor',
                    sprintf(
                        /* translators: %s: the anchor */
                        __('“%s” is too common on this site to identify anything.', 'smartlinker'),
                        $anchor[0]
                    ),
                    sprintf(
                        /* translators: 1: percentage, 2: threshold percentage */
                        __('It appears in %1$s%% of your posts; the limit is %2$s%%. A word that shows up almost everywhere tells a reader nothing about where the link goes.', 'smartlinker'),
                        number_format($share * 100, 0),
                        number_format($settings['max_share'] * 100, 0)
                    ),
                    $metrics,
                    [
                        __('Give the target a multi-word focus keyword so a more specific anchor can be found.', 'smartlinker'),
                        __('Or raise “maximum anchor document share” in Settings.', 'smartlinker'),
                    ]
                );
            }
        }

        /* -- 8. confidence ----------------------------------------------- */

        if ($semantic) {
            $span = max(0.01, Slk_Embedding::GOOD - Slk_Embedding::FLOOR);
            $sim_norm = min(1.0, max(0.0, ($sim - Slk_Embedding::FLOOR) / $span));
        } else {
            $sim_norm = min(1.0, $sim / $settings['good_sim']);
        }

        $overlap = Slk_Suggestion::keyword_overlap($source_terms, $target_terms, $corpus);
        $clusters = Slk_Suggestion::cluster_map();
        $rel = Slk_Suggestion::cluster_relation(
            isset($clusters[$source_id]) ? $clusters[$source_id] : null,
            isset($clusters[$target_id]) ? $clusters[$target_id] : null
        );

        $tier_weight = [
            'exact keyword'   => 1.00,
            'partial keyword' => 0.92,
            'title phrase'    => 0.85,
            'salient word'    => 0.70,
        ];
        $weight = isset($tier_weight[$anchor[1]]) ? $tier_weight[$anchor[1]] : 0.7;

        $confidence = ((0.75 * $sim_norm) + (0.15 * $overlap) + (0.10 * $rel[0])) * $weight;

        $metrics['sim_norm'] = $sim_norm;
        $metrics['keyword_overlap'] = $overlap;
        $metrics['cluster'] = $rel[0];
        $metrics['cluster_note'] = $rel[1];
        $metrics['tier_weight'] = $weight;
        $metrics['confidence'] = $confidence;

        if ($confidence < $settings['min_conf']) {
            return self::verdict(
                'confidence',
                __('It scored below your confidence threshold.', 'smartlinker'),
                sprintf(
                    /* translators: 1: score, 2: threshold */
                    __('Confidence is %1$s and the minimum is %2$s. Everything else passed — an anchor was found and the posts are related. This is the closest kind of near-miss.', 'smartlinker'),
                    number_format($confidence, 2),
                    number_format($settings['min_conf'], 2)
                ),
                $metrics,
                [
                    sprintf(
                        /* translators: 1: tier name, 2: weight */
                        __('The anchor came from “%1$s”, which is weighted %2$s. An exact focus-keyword match scores highest.', 'smartlinker'),
                        $anchor[1],
                        number_format($weight, 2)
                    ),
                    __('Lower “minimum confidence” in Settings, or put the two posts in the same topic cluster.', 'smartlinker'),
                ]
            );
        }

        /* -- 9. ranked out? ---------------------------------------------- */

        $actual = Slk_Suggestion::for_post($source_id);
        foreach ($actual as $s) {
            if (isset($s['target_id']) && (int) $s['target_id'] === $target_id) {
                return self::verdict(
                    'ok',
                    __('It is being suggested.', 'smartlinker'),
                    sprintf(
                        /* translators: 1: anchor, 2: confidence */
                        __('Anchor “%1$s”, confidence %2$s.', 'smartlinker'),
                        $s['phrase'],
                        isset($s['match']) ? $s['match'] . '%' : number_format($confidence, 2)
                    ),
                    $metrics,
                    [],
                    true
                );
            }
        }

        return self::verdict(
            'limit',
            __('It passed every check but was crowded out.', 'smartlinker'),
            sprintf(
                /* translators: %d: the per-post limit */
                __('Only the top %d suggestions are kept per post, and other candidates scored higher.', 'smartlinker'),
                $settings['limit']
            ),
            $metrics,
            [__('Raise “suggestions per post” in Settings → General.', 'smartlinker')]
        );
    }

    /**
     * The phrases the cascade would have looked for — the evidence behind
     * "no usable anchor", which is otherwise the least actionable verdict.
     */
    protected static function anchor_candidates($target, $focus_keyword)
    {
        $out = [];
        if ($focus_keyword !== '') {
            $out[] = $focus_keyword;
        }

        $weak = Slk_Suggestion::weak_words();
        $title = mb_strtolower(trim(wp_strip_all_tags($target->post_title)), 'UTF-8');
        $words = preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY);

        $start = 0;
        $end = count($words) - 1;
        while ($start <= $end && isset($weak[$words[$start]])) {
            $start++;
        }
        while ($end >= $start && isset($weak[$words[$end]])) {
            $end--;
        }
        if ($end > $start) {
            $out[] = implode(' ', array_slice($words, $start, $end - $start + 1));
        }

        return array_values(array_unique(array_filter($out)));
    }

    protected static function verdict($stage, $headline, $detail, $metrics = [], $fixes = [], $ok = false)
    {
        return [
            'ok'       => (bool) $ok,
            'stage'    => $stage,
            'headline' => $headline,
            'detail'   => $detail,
            'metrics'  => $metrics,
            'fixes'    => array_values(array_filter($fixes)),
        ];
    }

    /* ---------------------------------------------------------------------
     * Admin
     * ------------------------------------------------------------------ */

    public function register()
    {
        add_action('wp_ajax_slk_diagnose', [__CLASS__, 'ajax_diagnose']);
    }

    public static function ajax_diagnose()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        $source = isset($_POST['source_id']) ? (int) $_POST['source_id'] : 0;
        $target = isset($_POST['target_id']) ? (int) $_POST['target_id'] : 0;
        if (!$source || !$target) {
            wp_send_json_error(['message' => __('Pick both a source post and a destination.', 'smartlinker')]);
        }
        wp_send_json_success(self::explain($source, $target));
    }

    public static function render_page()
    {
        $posts = Slk_Post::candidate_targets(0, 2000);
        include SLK_PLUGIN_DIR . 'templates/diagnose.php';
    }
}
