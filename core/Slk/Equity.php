<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Link equity and click depth — how link value moves around the site, and how
 * far each page sits from the front door.
 *
 * The orphan report answers "which pages have no inbound links". This answers
 * the harder question underneath it: which pages have inbound links that are
 * themselves worth nothing. A post linked twice from your two weakest articles
 * is nearly as invisible as an orphan, and no existing report says so.
 *
 * Two independent measures, because they fail differently:
 *
 *   EQUITY — PageRank over your internal links. A page's value is the sum of
 *   value flowing in, split between everything each linking page points at. It
 *   is recursive on purpose: a link from a well-linked page is worth more than
 *   a link from a page nobody reaches.
 *
 *   DEPTH — clicks from the front page, by breadth-first search. A page can
 *   have decent equity and still sit six clicks deep, which is a crawling
 *   problem rather than an authority one.
 *
 * Both are computed from the link index alone. No external service, no
 * assumptions about traffic — this is the shape of your own site.
 */
class Slk_Equity
{
    const TRANSIENT = 'slk_equity';

    /** Standard PageRank damping. The 15% is the chance of jumping anywhere. */
    const DAMPING = 0.85;

    const ITERATIONS = 40;

    /** Stop early once no page's score moves by more than this. */
    const EPSILON = 0.000001;

    /** Depth beyond which a page is called buried. */
    const DEEP = 3;

    /* ---------------------------------------------------------------------
     * The graph
     * ------------------------------------------------------------------ */

    /**
     * @return array{nodes:array<int,string>,out:array<int,int[]>,in:array<int,int[]>}
     */
    public static function graph()
    {
        global $wpdb;

        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return ['nodes' => [], 'out' => [], 'in' => []];
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));

        // Every published post is a node, including ones with no links at all —
        // they are exactly the pages this report exists to find.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ($ph)",
            $types
        ));

        $nodes = [];
        foreach ($rows as $r) {
            $nodes[(int) $r->ID] = $r->post_title;
        }
        if (!$nodes) {
            return ['nodes' => [], 'out' => [], 'in' => []];
        }

        $table = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $edges = $wpdb->get_results(
            "SELECT DISTINCT post_id, target_post_id FROM {$table}
             WHERE type = 'internal' AND target_post_id > 0 AND post_id <> target_post_id"
        );

        $out = [];
        $in = [];
        foreach ($edges as $e) {
            $from = (int) $e->post_id;
            $to = (int) $e->target_post_id;
            // Links from or to something unpublished are not part of the graph.
            if (!isset($nodes[$from]) || !isset($nodes[$to])) {
                continue;
            }
            $out[$from][] = $to;
            $in[$to][] = $from;
        }

        return ['nodes' => $nodes, 'out' => $out, 'in' => $in];
    }

    /* ---------------------------------------------------------------------
     * Equity
     * ------------------------------------------------------------------ */

    /**
     * PageRank over the internal link graph.
     *
     * @return array<int,float> post id => share of total link value
     */
    public static function pagerank(array $graph)
    {
        $nodes = array_keys($graph['nodes']);
        $n = count($nodes);
        if ($n === 0) {
            return [];
        }

        $rank = [];
        foreach ($nodes as $id) {
            $rank[$id] = 1.0 / $n;
        }

        $teleport = (1.0 - self::DAMPING) / $n;

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $next = array_fill_keys($nodes, $teleport);

            // Pages with no outbound links would otherwise swallow value out of
            // the system. Their rank is spread over every page instead, which
            // is what the random-surfer model actually describes.
            $dangling = 0.0;
            foreach ($nodes as $id) {
                if (empty($graph['out'][$id])) {
                    $dangling += $rank[$id];
                }
            }
            $spill = self::DAMPING * $dangling / $n;

            foreach ($nodes as $id) {
                $next[$id] += $spill;
            }

            foreach ($graph['out'] as $from => $targets) {
                if (!isset($rank[$from]) || empty($targets)) {
                    continue;
                }
                $share = self::DAMPING * $rank[$from] / count($targets);
                foreach ($targets as $to) {
                    if (isset($next[$to])) {
                        $next[$to] += $share;
                    }
                }
            }

            $delta = 0.0;
            foreach ($nodes as $id) {
                $delta = max($delta, abs($next[$id] - $rank[$id]));
            }
            $rank = $next;

            if ($delta < self::EPSILON) {
                break;
            }
        }

        return $rank;
    }

    /* ---------------------------------------------------------------------
     * Depth
     * ------------------------------------------------------------------ */

    /**
     * Where a visitor starts.
     *
     * With a static front page that is one post. With the classic blog index
     * the front page lists the most recent posts, so those are the pages one
     * click in — pretending otherwise would report the whole site as
     * unreachable on a perfectly normal setup.
     *
     * @return int[] post ids at depth 0
     */
    public static function seeds(array $graph)
    {
        $seeds = [];

        if (get_option('show_on_front') === 'page') {
            $front = (int) get_option('page_on_front');
            if ($front && isset($graph['nodes'][$front])) {
                $seeds[] = $front;
            }
        }

        if (!$seeds) {
            $per_page = max(1, (int) get_option('posts_per_page', 10));
            $recent = get_posts([
                'numberposts' => $per_page,
                'post_status' => 'publish',
                'post_type'   => Slk_Settings::enabled_post_types(),
                'fields'      => 'ids',
            ]);
            foreach ($recent as $id) {
                if (isset($graph['nodes'][(int) $id])) {
                    $seeds[] = (int) $id;
                }
            }
        }

        return $seeds;
    }

    /**
     * Clicks from the front page, breadth-first.
     *
     * @return array<int,int> post id => depth; missing means unreachable
     */
    public static function depths(array $graph, array $seeds)
    {
        $depth = [];
        $queue = [];

        foreach ($seeds as $id) {
            $depth[$id] = 0;
            $queue[] = $id;
        }

        // Plain FIFO: the first time BFS reaches a node it is via a shortest
        // path, so a node is never revisited.
        for ($i = 0; $i < count($queue); $i++) {
            $current = $queue[$i];
            if (empty($graph['out'][$current])) {
                continue;
            }
            foreach ($graph['out'][$current] as $next) {
                if (!isset($depth[$next])) {
                    $depth[$next] = $depth[$current] + 1;
                    $queue[] = $next;
                }
            }
        }

        return $depth;
    }

    /* ---------------------------------------------------------------------
     * The report
     * ------------------------------------------------------------------ */

    /**
     * @return array{rows:array,stats:array,generated:string}
     */
    public static function build()
    {
        $graph = self::graph();
        if (empty($graph['nodes'])) {
            return ['rows' => [], 'stats' => self::empty_stats(), 'generated' => current_time('mysql')];
        }

        $rank = self::pagerank($graph);
        $seeds = self::seeds($graph);
        $depth = self::depths($graph, $seeds);

        $n = count($graph['nodes']);
        $average = 1.0 / max(1, $n);

        $rows = [];
        foreach ($graph['nodes'] as $id => $title) {
            $inbound = isset($graph['in'][$id]) ? count($graph['in'][$id]) : 0;
            $outbound = isset($graph['out'][$id]) ? count($graph['out'][$id]) : 0;

            $rows[] = [
                'id'       => $id,
                'title'    => trim(wp_strip_all_tags($title)) ?: __('(no title)', 'smartlinker'),
                'url'      => get_permalink($id),
                'edit_url' => get_edit_post_link($id, ''),
                'equity'   => isset($rank[$id]) ? $rank[$id] : 0.0,
                // Relative to an evenly-shared site, so 1.00 means "an average
                // page here" and 0.20 means "a fifth of its fair share". A raw
                // PageRank of 0.0004 tells nobody anything.
                'relative' => $average > 0 ? (isset($rank[$id]) ? $rank[$id] : 0) / $average : 0,
                'depth'    => isset($depth[$id]) ? $depth[$id] : null,
                'inbound'  => $inbound,
                'outbound' => $outbound,
                'seed'     => in_array($id, $seeds, true),
            ];
        }

        usort($rows, function ($a, $b) {
            return $b['equity'] <=> $a['equity'];
        });

        $reachable = array_filter($rows, function ($r) {
            return $r['depth'] !== null;
        });
        $depths = array_map(function ($r) {
            return $r['depth'];
        }, $reachable);

        $stats = [
            'posts'       => $n,
            'unreachable' => count($rows) - count($reachable),
            'deep'        => count(array_filter($depths, function ($d) {
                return $d > self::DEEP;
            })),
            'avg_depth'   => $depths ? array_sum($depths) / count($depths) : 0,
            'max_depth'   => $depths ? max($depths) : 0,
            'starved'     => count(array_filter($rows, function ($r) {
                return $r['relative'] < 0.5;
            })),
            'seeds'       => count($seeds),
            'front'       => get_option('show_on_front') === 'page' ? 'page' : 'posts',
        ];

        $out = ['rows' => $rows, 'stats' => $stats, 'generated' => current_time('mysql')];
        set_transient(self::TRANSIENT, $out, HOUR_IN_SECONDS);
        return $out;
    }

    protected static function empty_stats()
    {
        return [
            'posts' => 0, 'unreachable' => 0, 'deep' => 0, 'avg_depth' => 0,
            'max_depth' => 0, 'starved' => 0, 'seeds' => 0, 'front' => 'posts',
        ];
    }

    public static function all()
    {
        $cached = get_transient(self::TRANSIENT);
        return is_array($cached) ? $cached : self::build();
    }

    public static function flush()
    {
        delete_transient(self::TRANSIENT);
    }

    /**
     * How badly each page needs inbound links, 0 (well linked) to 1 (starved).
     *
     * Fed to the suggestion engine so that, between two equally relevant
     * targets, the one nothing points at wins. Deliberately a NEED and not a
     * quality: a starved page is not a better match, it is a more useful link,
     * and those are different claims. See Slk_Suggestion::for_post() for why
     * that distinction is kept out of the confidence figure.
     *
     * @return array<int,float>
     */
    public static function need_map()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $data = self::all();
        $map = [];
        foreach ($data['rows'] as $r) {
            // At or above an average page's share: no need at all. At a quarter
            // of average or below: maximum need. Linear between, so the signal
            // is gradual rather than a cliff nobody can predict.
            $need = (1.0 - $r['relative']) / 0.75;
            $map[(int) $r['id']] = max(0.0, min(1.0, $need));
        }
        return $map;
    }

    /** Multiple of an average page's link value, for one post. */
    public static function relative_for($post_id)
    {
        $data = self::all();
        foreach ($data['rows'] as $r) {
            if ((int) $r['id'] === (int) $post_id) {
                return (float) $r['relative'];
            }
        }
        return 0.0;
    }

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        // Cheap to rebuild and always derived, so it can be flushed freely —
        // unlike the Opportunities worklist, there is no user state to lose.
        add_action('deleted_post', [__CLASS__, 'flush']);
    }

    public static function handle_actions()
    {
        if (!class_exists('Slk_Reports') || !Slk_Reports::on_tab('equity')) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }
        if (!empty($_GET['slk_equity_rebuild']) && check_admin_referer('slk_equity_rebuild')) {
            self::flush();
            self::build();
            wp_safe_redirect(Slk_Reports::url('equity', ['rebuilt' => 1]));
            exit;
        }
    }

    public static function render_page()
    {
        $data = self::all();
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'all';
        include SLK_PLUGIN_DIR . 'templates/equity.php';
    }
}
