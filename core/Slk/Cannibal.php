<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keyword cannibalisation — posts on your own site competing with each other.
 *
 * When two articles chase the same query, a search engine has to pick one. It
 * usually picks the one you would not have chosen, and both rank worse than a
 * single stronger article would have. Internal linking makes this worse rather
 * than better: links get split between the rivals, so neither accumulates the
 * authority to win.
 *
 * Two signals, and they mean different things:
 *
 *   SAME FOCUS KEYWORD — you told both posts to target the same phrase. This is
 *   an intent clash, cheap to detect, and unambiguous. Grouping by keyword is
 *   O(n), so it always runs.
 *
 *   SEMANTIC OVERLAP — the posts mean the same thing, whatever keywords were
 *   set. This is the one that finds the cases you did not know about, and it
 *   needs the embeddings index. Comparing every pair is O(n²), so the scan is
 *   batched against a wall clock, like the opportunities scan.
 *
 * Nothing here is auto-fixed, and deliberately so. The fix is editorial —
 * merge, differentiate, or make one canonical — and no plugin should make that
 * call for you. What it does do is say which of the pair is currently the
 * stronger page, using the equity graph, because that is the one worth keeping.
 */
class Slk_Cannibal
{
    const TRANSIENT = 'slk_cannibalisation';

    /** Source posts compared per scan pass. */
    const BATCH = 30;

    /** Seconds a pass may spend before handing off to the next request. */
    const BUDGET = 15;

    /**
     * Cosine above which two posts are treated as covering the same ground.
     *
     * Deliberately far above Slk_Embedding::GOOD (0.70), which only means
     * "related enough to link". Cannibalisation is a much stronger claim —
     * these should read as near-duplicates, not merely neighbours.
     */
    const SEMANTIC = 0.86;

    /* ---------------------------------------------------------------------
     * Keyword clashes — cheap, always available.
     * ------------------------------------------------------------------ */

    /** Focus keywords compare the way anchors do: case and padding folded. */
    public static function normalize($keyword)
    {
        return Slk_Anchor::normalize($keyword);
    }

    /**
     * Posts that were given the same focus keyword.
     *
     * @return array list of ['keyword' => string, 'posts' => int[]]
     */
    public static function keyword_clashes()
    {
        $focus = Slk_Suggestion::focus_keywords();
        $types = Slk_Settings::enabled_post_types();

        $groups = [];
        foreach ($focus as $post_id => $keyword) {
            $key = self::normalize($keyword);
            if ($key === '') {
                continue;
            }
            $post = get_post((int) $post_id);
            if (!$post || $post->post_status !== 'publish' || !in_array($post->post_type, $types, true)) {
                continue;
            }
            $groups[$key]['keyword'] = trim(wp_strip_all_tags($keyword));
            $groups[$key]['posts'][] = (int) $post_id;
        }

        $out = [];
        foreach ($groups as $g) {
            if (count($g['posts']) < 2) {
                continue;
            }
            $out[] = $g;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Semantic overlap — needs the embeddings index.
     * ------------------------------------------------------------------ */

    public static function semantic_available()
    {
        return Slk_Embedding::is_enabled() && count(Slk_Embedding::all()) >= 2;
    }

    /**
     * Compare a slice of posts against every post after them.
     *
     * Only the upper triangle is walked — comparing A to B and then B to A
     * would double the work to produce the same pair twice.
     *
     * @return array{pairs:array,scanned:int,total:int,done:bool}
     */
    public static function scan($offset = 0)
    {
        $started = microtime(true);
        $vectors = self::semantic_available() ? Slk_Embedding::all() : [];

        $types = Slk_Settings::enabled_post_types();
        $ids = [];
        foreach (array_keys($vectors) as $id) {
            $post = get_post((int) $id);
            if ($post && $post->post_status === 'publish' && in_array($post->post_type, $types, true)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        $total = count($ids);

        $state = $offset > 0 ? self::all() : ['pairs' => [], 'scanned' => 0, 'total' => $total, 'generated' => ''];
        $pairs = $state['pairs'];

        $i = $offset;
        for (; $i < $total; $i++) {
            for ($j = $i + 1; $j < $total; $j++) {
                $a = $ids[$i];
                $b = $ids[$j];
                $sim = Slk_Embedding::cosine($vectors[$a], $vectors[$b]);
                if ($sim < self::SEMANTIC) {
                    continue;
                }
                $pairs[$a . '|' . $b] = ['a' => $a, 'b' => $b, 'similarity' => round($sim, 4)];
            }

            if ($i - $offset >= self::BATCH || microtime(true) - $started > self::BUDGET) {
                $i++;
                break;
            }
        }

        $out = [
            'pairs'     => $pairs,
            'scanned'   => min($total, $i),
            'total'     => $total,
            'generated' => current_time('mysql'),
        ];
        set_transient(self::TRANSIENT, $out, DAY_IN_SECONDS);

        $out['done'] = $out['scanned'] >= $total;
        return $out;
    }

    public static function all()
    {
        $cached = get_transient(self::TRANSIENT);
        return is_array($cached) ? $cached : ['pairs' => [], 'scanned' => 0, 'total' => 0, 'generated' => ''];
    }

    public static function flush()
    {
        delete_transient(self::TRANSIENT);
    }

    /* ---------------------------------------------------------------------
     * The report
     * ------------------------------------------------------------------ */

    /**
     * Every competing pair, keyword clashes and semantic overlaps merged.
     */
    public static function rows()
    {
        $focus = Slk_Suggestion::focus_keywords();
        $semantic = self::all();

        $pairs = [];

        // Keyword clashes first: a group of three posts on one keyword is three
        // competing pairs, and each deserves its own decision.
        foreach (self::keyword_clashes() as $clash) {
            $posts = $clash['posts'];
            $count = count($posts);
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = min($posts[$i], $posts[$j]);
                    $b = max($posts[$i], $posts[$j]);
                    $pairs[$a . '|' . $b] = [
                        'a' => $a,
                        'b' => $b,
                        'keyword' => $clash['keyword'],
                        'similarity' => null,
                    ];
                }
            }
        }

        foreach ($semantic['pairs'] as $key => $p) {
            if (isset($pairs[$key])) {
                $pairs[$key]['similarity'] = $p['similarity'];
                continue;
            }
            $pairs[$key] = [
                'a' => $p['a'],
                'b' => $p['b'],
                'keyword' => '',
                'similarity' => $p['similarity'],
            ];
        }

        $rows = [];
        foreach ($pairs as $p) {
            $a = get_post($p['a']);
            $b = get_post($p['b']);
            if (!$a || !$b) {
                continue;
            }

            $same_kw = $p['keyword'] !== '';
            $close = $p['similarity'] !== null && $p['similarity'] >= self::SEMANTIC;

            // Severity is about how certain the clash is, not how bad it is.
            if ($same_kw && $close) {
                $severity = 'direct';
            } elseif ($same_kw) {
                $severity = 'keyword';
            } else {
                $severity = 'overlap';
            }

            $eq_a = Slk_Equity::relative_for($p['a']);
            $eq_b = Slk_Equity::relative_for($p['b']);

            $rows[] = [
                'a'          => $p['a'],
                'b'          => $p['b'],
                'a_title'    => trim(wp_strip_all_tags($a->post_title)),
                'b_title'    => trim(wp_strip_all_tags($b->post_title)),
                'a_url'      => get_permalink($p['a']),
                'b_url'      => get_permalink($p['b']),
                'a_edit'     => Slk_Admin::edit_url($p['a'], ''),
                'b_edit'     => Slk_Admin::edit_url($p['b'], ''),
                'a_keyword'  => isset($focus[$p['a']]) ? $focus[$p['a']] : '',
                'b_keyword'  => isset($focus[$p['b']]) ? $focus[$p['b']] : '',
                'keyword'    => $p['keyword'],
                'similarity' => $p['similarity'],
                'severity'   => $severity,
                'a_equity'   => $eq_a,
                'b_equity'   => $eq_b,
                // Which one to keep as the main page. Link value already earned
                // is the least arbitrary tie-break available, and it is the one
                // that is expensive to rebuild elsewhere.
                'stronger'   => $eq_a >= $eq_b ? $p['a'] : $p['b'],
            ];
        }

        $order = ['direct' => 0, 'keyword' => 1, 'overlap' => 2];
        usort($rows, function ($x, $y) use ($order) {
            if ($x['severity'] !== $y['severity']) {
                return $order[$x['severity']] <=> $order[$y['severity']];
            }
            return (float) $y['similarity'] <=> (float) $x['similarity'];
        });

        return $rows;
    }

    public static function counts($rows)
    {
        $c = ['total' => count($rows), 'direct' => 0, 'keyword' => 0, 'overlap' => 0];
        foreach ($rows as $r) {
            $c[$r['severity']]++;
        }
        return $c;
    }

    /* ---------------------------------------------------------------------
     * Admin
     * ------------------------------------------------------------------ */

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('deleted_post', [__CLASS__, 'flush']);
    }

    public static function handle_actions()
    {
        if (!class_exists('Slk_Reports') || !Slk_Reports::on_tab('cannibal')) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        if (!empty($_GET['slk_cannibal_scan']) && check_admin_referer('slk_cannibal_scan')) {
            $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
            $result = self::scan($offset);

            // One batch per request, redirecting onward, so a large site never
            // hits the time limit in a single call.
            if (empty($result['done'])) {
                wp_safe_redirect(wp_nonce_url(
                    Slk_Reports::url('cannibal', ['slk_cannibal_scan' => 1, 'offset' => $result['scanned']]),
                    'slk_cannibal_scan'
                ));
                exit;
            }
            wp_safe_redirect(Slk_Reports::url('cannibal', ['scanned' => 1]));
            exit;
        }
    }

    public static function render_page()
    {
        $rows = self::rows();
        $counts = self::counts($rows);
        $state = self::all();
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'all';
        if (in_array($view, ['direct', 'keyword', 'overlap'], true)) {
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return $r['severity'] === $view;
            }));
        }
        include SLK_PLUGIN_DIR . 'templates/cannibal.php';
    }
}
