<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A "Links" column on the Posts list.
 *
 * The plugin's own reports are somewhere you have to go. This puts the same
 * three numbers where you already are — the screen you open to find a post to
 * edit — so a badly linked article is visible at the moment you are choosing
 * what to work on, not on a report you remember to check.
 *
 * Two decisions make it more than a read-out:
 *
 *   ZERO INBOUND IS THE ONLY NUMBER THAT MATTERS AT A GLANCE. Three grey
 *   figures per row is noise you learn to skip. A post nothing links to is an
 *   orphan — that one is coloured and labelled, the rest stay quiet.
 *
 *   THE COLUMN SORTS. Sorting the posts list by fewest inbound links turns it
 *   into a worklist you can work down inside WordPress, using the editor you
 *   were going to open anyway.
 *
 * Counts are fetched for the whole screen in ONE query. Three sub-selects per
 * row would add sixty-plus queries to a page that is already among the
 * heaviest in wp-admin.
 */
class Slk_PostsColumn
{
    const COLUMN = 'slk_links';

    /** Per-request cache: post id => ['in'=>int,'out'=>int,'ext'=>int]. */
    protected static $counts = null;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'hook_post_types']);
    }

    /**
     * Only the post types the plugin is set to work on — adding a column to
     * a type SmartLinker ignores would show zeroes and mean nothing.
     */
    public static function hook_post_types()
    {
        foreach (Slk_Settings::enabled_post_types() as $type) {
            add_filter("manage_{$type}_posts_columns", [__CLASS__, 'add_column']);
            add_action("manage_{$type}_posts_custom_column", [__CLASS__, 'render'], 10, 2);
            add_filter("manage_edit-{$type}_sortable_columns", [__CLASS__, 'sortable']);
        }
        // 'page' uses a different column filter name.
        if (in_array('page', Slk_Settings::enabled_post_types(), true)) {
            add_filter('manage_pages_columns', [__CLASS__, 'add_column']);
            add_action('manage_pages_custom_column', [__CLASS__, 'render'], 10, 2);
        }
        add_filter('posts_clauses', [__CLASS__, 'sort_clause'], 10, 2);
        add_action('admin_head-edit.php', [__CLASS__, 'styles']);
    }

    /**
     * Styles printed inline, on edit.php only.
     *
     * The plugin's stylesheet is 77KB and is deliberately not loaded outside
     * SmartLinker's own pages and the editor. Enqueuing all of it so one
     * column can be 13px and red would be a poor trade on a screen that is
     * already among the heaviest in wp-admin — this is under 1KB.
     */
    public static function styles()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !in_array($screen->post_type, Slk_Settings::enabled_post_types(), true)) {
            return;
        }
        echo '<style id="slk-posts-column">' . self::CSS . '</style>';
    }

    const CSS = '
.column-slk_links{width:132px}
.slk-col{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.slk-col-stat{display:inline-flex;align-items:center;gap:3px;font-size:12.5px;line-height:1;color:#50575e;text-decoration:none}
a.slk-col-stat:hover{color:#2271b1}
.slk-col-stat strong{font-weight:600}
.slk-col-ico{width:13px;height:13px;flex:0 0 auto;opacity:.75}
.slk-col-stat.is-quiet{color:#8c8f94}
.slk-col-stat.is-none{color:#b32d2e;font-weight:600}
.slk-col-stat.is-none .slk-col-ico{opacity:1}
.slk-col-flag{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#b32d2e;background:#fcf0f1;border-radius:3px;padding:1px 5px}
';

    public static function add_column($columns)
    {
        // Placed before the date column, which is where the eye already ends up.
        $out = [];
        foreach ($columns as $key => $label) {
            if ($key === 'date') {
                $out[self::COLUMN] = __('Links', 'smartlinker');
            }
            $out[$key] = $label;
        }
        if (!isset($out[self::COLUMN])) {
            $out[self::COLUMN] = __('Links', 'smartlinker');
        }
        return $out;
    }

    public static function sortable($columns)
    {
        $columns[self::COLUMN] = 'slk_inbound';
        return $columns;
    }

    /* ---------------------------------------------------------------------
     * Counts
     * ------------------------------------------------------------------ */

    /**
     * One query for every row on screen.
     */
    protected static function counts_for(array $post_ids)
    {
        if (self::$counts !== null) {
            return self::$counts;
        }
        self::$counts = [];
        $post_ids = array_map('intval', array_filter($post_ids));
        if (empty($post_ids)) {
            return self::$counts;
        }

        global $wpdb;
        $table = Slk_Query::links_table();
        $ph = implode(',', array_fill(0, count($post_ids), '%d'));

        // Outbound and external are grouped by the post that holds the link;
        // inbound is grouped by the post being linked TO. One pass each way,
        // unioned, rather than a sub-select per row.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id AS id,
                    SUM(CASE WHEN type = 'internal' THEN 1 ELSE 0 END) AS outbound,
                    SUM(CASE WHEN type = 'external' THEN 1 ELSE 0 END) AS external,
                    0 AS inbound
             FROM {$table} WHERE post_id IN ($ph) GROUP BY post_id
             UNION ALL
             SELECT target_post_id AS id, 0, 0, COUNT(*)
             FROM {$table}
             WHERE type = 'internal' AND target_post_id IN ($ph)
             GROUP BY target_post_id",
            array_merge($post_ids, $post_ids)
        ));

        foreach ($post_ids as $id) {
            self::$counts[$id] = ['in' => 0, 'out' => 0, 'ext' => 0];
        }
        foreach ($rows as $r) {
            $id = (int) $r->id;
            if (!isset(self::$counts[$id])) {
                continue;
            }
            self::$counts[$id]['out'] += (int) $r->outbound;
            self::$counts[$id]['ext'] += (int) $r->external;
            self::$counts[$id]['in']  += (int) $r->inbound;
        }
        return self::$counts;
    }

    /** The ids on the current screen, so the prefetch knows what to ask for. */
    protected static function screen_ids()
    {
        global $wp_query;
        if (empty($wp_query->posts)) {
            return [];
        }
        return array_map(function ($p) {
            return is_object($p) ? (int) $p->ID : (int) $p;
        }, $wp_query->posts);
    }

    /* ---------------------------------------------------------------------
     * Rendering
     * ------------------------------------------------------------------ */

    public static function render($column, $post_id)
    {
        if ($column !== self::COLUMN) {
            return;
        }
        $counts = self::counts_for(self::screen_ids());
        $c = isset($counts[$post_id]) ? $counts[$post_id] : ['in' => 0, 'out' => 0, 'ext' => 0];

        $inbound_url = admin_url('admin.php?page=smartlinker_inbound&target=' . (int) $post_id);
        $report_url = Slk_Reports::url('overview', ['s' => get_the_title($post_id)]);

        echo '<div class="slk-col">';

        // Inbound first, and loud when it is zero — that is the orphan, and the
        // only one of the three worth interrupting someone for.
        printf(
            '<a class="slk-col-stat %s" href="%s" title="%s">%s<strong>%d</strong></a>',
            $c['in'] === 0 ? 'is-none' : '',
            esc_url($inbound_url),
            esc_attr($c['in'] === 0
                ? __('Nothing links to this post — find posts that should', 'smartlinker')
                : sprintf(
                    /* translators: %d: number of links */
                    _n('%d post links to this one', '%d posts link to this one', $c['in'], 'smartlinker'),
                    $c['in']
                )),
            self::icon('in'),
            (int) $c['in']
        );

        printf(
            '<a class="slk-col-stat" href="%s" title="%s">%s<strong>%d</strong></a>',
            esc_url($report_url),
            esc_attr(sprintf(
                /* translators: %d: number of links */
                _n('%d internal link out of this post', '%d internal links out of this post', $c['out'], 'smartlinker'),
                $c['out']
            )),
            self::icon('out'),
            (int) $c['out']
        );

        printf(
            '<span class="slk-col-stat is-quiet" title="%s">%s<strong>%d</strong></span>',
            esc_attr(sprintf(
                /* translators: %d: number of links */
                _n('%d link to another site', '%d links to other sites', $c['ext'], 'smartlinker'),
                $c['ext']
            )),
            self::icon('ext'),
            (int) $c['ext']
        );

        if ($c['in'] === 0) {
            echo '<span class="slk-col-flag">' . esc_html__('orphan', 'smartlinker') . '</span>';
        }

        echo '</div>';
    }

    /** Inline SVG rather than dashicons: these sit at 13px and must stay crisp. */
    protected static function icon($which)
    {
        $paths = [
            'in'  => 'M12 5v14m0 0l-6-6m6 6l6-6',           // arrow down into the post
            'out' => 'M7 17L17 7m0 0H8m9 0v9',              // arrow up-right, leaving
            'ext' => 'M12 21a9 9 0 100-18 9 9 0 000 18zm0 0c2.5-2.5 2.5-15 0-18m0 18c-2.5-2.5-2.5-15 0-18M3.6 9h16.8M3.6 15h16.8',
        ];
        return '<svg class="slk-col-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" '
            . 'focusable="false"><path d="' . $paths[$which] . '"/></svg>';
    }

    /* ---------------------------------------------------------------------
     * Sorting
     * ------------------------------------------------------------------ */

    /**
     * Order by inbound link count.
     *
     * Done in `posts_clauses` because the value is not a column or a meta key —
     * it is a count over another table, so there is nothing for the usual
     * `orderby` handling to grab.
     */
    public static function sort_clause($clauses, $query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return $clauses;
        }
        if ($query->get('orderby') !== 'slk_inbound') {
            return $clauses;
        }

        global $wpdb;
        $table = Slk_Query::links_table();
        $order = strtoupper($query->get('order')) === 'DESC' ? 'DESC' : 'ASC';

        $clauses['orderby'] = "(SELECT COUNT(*) FROM {$table} sl
                                WHERE sl.target_post_id = {$wpdb->posts}.ID
                                AND sl.type = 'internal') {$order}, {$wpdb->posts}.post_date DESC";

        return $clauses;
    }
}
