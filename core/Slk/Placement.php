<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Where in a post its internal links actually sit.
 *
 * Every other report treats a link as a fact: it exists, it points somewhere,
 * it works. None of them ask whether anyone will reach it. A link in the last
 * paragraph of a two-thousand word article is read by the small fraction of
 * people still there, and a reader who left at the halfway mark never saw it.
 * Position is the cheapest thing to fix and the least often looked at.
 *
 * Position is measured in VISIBLE TEXT, not raw HTML. Markup is unevenly
 * distributed — a post opening with a gallery or a table of contents carries
 * far more tags per word at the top — so measuring offsets in the source would
 * report a link as halfway down a post the reader sees as near the start.
 * What matters is how much reading stands between the top and the link.
 */
class Slk_Placement
{
    const TRANSIENT = 'slk_placement';

    /** Posts with at least this many links get a shape verdict. */
    const MIN_LINKS = 2;

    /** Average position at or above which a post is called bottom-heavy. */
    const BOTTOM = 0.60;

    /** Average position at or below which its links are well placed. */
    const TOP = 0.40;

    /**
     * Position of every internal link in one post, as a fraction 0–1.
     *
     * @return array list of ['anchor','url','position']
     */
    public static function positions($content, $home = null)
    {
        $content = (string) $content;
        if (trim($content) === '') {
            return [];
        }
        if ($home === null) {
            $home = home_url();
        }
        $host = wp_parse_url($home, PHP_URL_HOST);

        $total = strlen(wp_strip_all_tags($content));
        if ($total === 0) {
            return [];
        }

        if (!preg_match_all(
            '/<a\s[^>]*href=("|\')(.*?)\1[^>]*>(.*?)<\/a>/is',
            $content,
            $m,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        )) {
            return [];
        }

        $out = [];
        foreach ($m as $match) {
            $url = html_entity_decode($match[2][0], ENT_QUOTES, 'UTF-8');

            // Internal only. External links have their own report, and mixing
            // them would make "how deep are my internal links" unanswerable.
            $link_host = wp_parse_url($url, PHP_URL_HOST);
            if ($link_host !== null && $link_host !== $host) {
                continue;
            }

            // How much reading stands between the top of the post and here.
            $before = strlen(wp_strip_all_tags(substr($content, 0, $match[0][1])));

            $out[] = [
                'anchor'   => trim(wp_strip_all_tags($match[3][0])),
                'url'      => $url,
                'position' => min(1.0, max(0.0, $before / $total)),
            ];
        }

        return $out;
    }

    /**
     * Summarise one post's link placement.
     */
    public static function summarise(array $positions)
    {
        $n = count($positions);
        if ($n === 0) {
            return [
                'links' => 0, 'avg' => null, 'first_quarter' => 0,
                'last_quarter' => 0, 'shape' => 'none', 'spots' => [],
            ];
        }

        $vals = array_column($positions, 'position');
        $avg = array_sum($vals) / $n;

        $first = count(array_filter($vals, function ($p) {
            return $p <= 0.25;
        }));
        $last = count(array_filter($vals, function ($p) {
            return $p >= 0.75;
        }));

        // A single link has a position but not a shape — one data point is not
        // a pattern, and calling it "bottom-heavy" would be overreach.
        $shape = 'mixed';
        if ($n < self::MIN_LINKS) {
            $shape = 'single';
        } elseif ($avg >= self::BOTTOM) {
            $shape = 'bottom';
        } elseif ($avg <= self::TOP) {
            $shape = 'top';
        }

        return [
            'links'         => $n,
            'avg'           => $avg,
            'first_quarter' => $first,
            'last_quarter'  => $last,
            'shape'         => $shape,
            'spots'         => $vals,
        ];
    }

    /* ---------------------------------------------------------------------
     * The report
     * ------------------------------------------------------------------ */

    public static function build()
    {
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return ['rows' => [], 'stats' => self::empty_stats(), 'generated' => current_time('mysql')];
        }

        $home = home_url();
        $rows = [];

        // Walked in slices rather than selected in one query: this reads the
        // body of every published post, and holding them all at once is a
        // fatal memory error on a large site. What is kept is the summary —
        // a handful of numbers per post — not the content it came from.
        Slk_Post::walk_content(
            Slk_Post::ids_for_walk(['publish']),
            function ($p) use ($home, &$rows) {
                $positions = self::positions($p->post_content, $home);
                if (empty($positions)) {
                    return;   // no internal links is an orphan/outbound question
                }
                $s = self::summarise($positions);
                $rows[] = array_merge($s, [
                    'id'       => (int) $p->ID,
                    'title'    => trim(wp_strip_all_tags($p->post_title)) ?: __('(no title)', 'smartlinker'),
                    'url'      => get_permalink($p->ID),
                    'edit_url' => get_edit_post_link($p->ID, ''),
                ]);
            },
            ['post_title']
        );

        usort($rows, function ($a, $b) {
            return $b['avg'] <=> $a['avg'];
        });

        $all = array_column($rows, 'avg');
        $stats = [
            'posts'    => count($rows),
            'links'    => array_sum(array_column($rows, 'links')),
            'avg'      => $all ? array_sum($all) / count($all) : 0,
            'bottom'   => count(array_filter($rows, function ($r) {
                return $r['shape'] === 'bottom';
            })),
            'top'      => count(array_filter($rows, function ($r) {
                return $r['shape'] === 'top';
            })),
            'last_q'   => array_sum(array_column($rows, 'last_quarter')),
            'first_q'  => array_sum(array_column($rows, 'first_quarter')),
        ];

        $out = ['rows' => $rows, 'stats' => $stats, 'generated' => current_time('mysql')];
        set_transient(self::TRANSIENT, $out, HOUR_IN_SECONDS);
        return $out;
    }

    protected static function empty_stats()
    {
        return ['posts' => 0, 'links' => 0, 'avg' => 0, 'bottom' => 0, 'top' => 0, 'last_q' => 0, 'first_q' => 0];
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

    /* ---------------------------------------------------------------------
     * Admin
     * ------------------------------------------------------------------ */

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('save_post', [__CLASS__, 'flush']);
        add_action('deleted_post', [__CLASS__, 'flush']);
    }

    public static function handle_actions()
    {
        if (!class_exists('Slk_Reports') || !Slk_Reports::on_tab('placement')) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }
        if (!empty($_GET['slk_placement_rebuild']) && check_admin_referer('slk_placement_rebuild')) {
            self::flush();
            self::build();
            wp_safe_redirect(Slk_Reports::url('placement', ['rebuilt' => 1]));
            exit;
        }
    }

    public static function render_page()
    {
        $data = self::all();
        $view = isset($_GET['view']) ? sanitize_key($_GET['view']) : 'all';
        include SLK_PLUGIN_DIR . 'templates/placement.php';
    }
}
