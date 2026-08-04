<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The dashboard: an at-a-glance snapshot of internal linking health —
 * scores, link distribution, recommendations, and quick actions.
 */
class Slk_Dashboard
{
    /** Minutes of manual work saved per link SmartLinker inserts. */
    const MINUTES_PER_LINK = 2;

    public static function stats()
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        $clicks = Slk_Query::clicks_table();

        $summary = Slk_Report::summary(); // internal, external, total_posts, orphaned, broken

        $total = (int) $summary['total_posts'];
        $linked_posts = 0;
        $with_outbound = 0;
        $types = Slk_Settings::enabled_post_types();
        if (!empty($types) && $total > 0) {
            $ph = implode(',', array_fill(0, count($types), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL
            $linked_posts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_status='publish' AND p.post_type IN ($ph)
                 AND EXISTS (SELECT 1 FROM {$links} l WHERE l.target_post_id = p.ID AND l.type='internal')",
                $types
            ));
            // phpcs:ignore WordPress.DB.PreparedSQL
            $with_outbound = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_status='publish' AND p.post_type IN ($ph)
                 AND EXISTS (SELECT 1 FROM {$links} l WHERE l.post_id = p.ID AND l.type='internal')",
                $types
            ));
        }
        $coverage = $total > 0 ? round(($linked_posts / $total) * 100, 1) : 0;

        // Posts indexed at least once (i.e. crawled).
        // phpcs:ignore WordPress.DB.PreparedSQL
        $crawled = (int) $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$links}");
        $crawled = min($crawled + ($total - $with_outbound > 0 ? 0 : 0), $total);
        $indexed_pct = $total > 0 ? round(($crawled / $total) * 100, 1) : 0;

        // Clicks: last 30 days vs the prior 30.
        $now = current_time('mysql');
        $d30 = gmdate('Y-m-d H:i:s', strtotime($now . ' -30 days'));
        $d60 = gmdate('Y-m-d H:i:s', strtotime($now . ' -60 days'));
        // phpcs:ignore WordPress.DB.PreparedSQL
        $clicks_30 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$clicks} WHERE clicked_at >= %s", $d30));
        // phpcs:ignore WordPress.DB.PreparedSQL
        $clicks_prev = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$clicks} WHERE clicked_at >= %s AND clicked_at < %s", $d60, $d30));

        // Links SmartLinker created.
        $created_30 = Slk_Link::insertions_since(30);
        $created_prev = Slk_Link::insertions_since(30, 30);

        $internal = (int) $summary['internal'];
        $external = (int) $summary['external'];
        $total_links = $internal + $external;

        return [
            'total_posts'   => $total,
            'crawled'       => $crawled,
            'indexed_pct'   => $indexed_pct,
            'internal'      => $internal,
            'external'      => $external,
            'total_links'   => $total_links,
            'internal_pct'  => $total_links > 0 ? round(($internal / $total_links) * 100) : 0,
            'external_pct'  => $total_links > 0 ? round(($external / $total_links) * 100) : 0,
            'broken'        => (int) $summary['broken'],
            'orphaned'      => (int) $summary['orphaned'],
            'linked_posts'  => $linked_posts,
            'with_outbound' => $with_outbound,
            'coverage'      => $coverage,
            'clicks_30'     => $clicks_30,
            'clicks_prev'   => $clicks_prev,
            'created_30'    => $created_30,
            'created_prev'  => $created_prev,
            'time_saved'    => round(($created_30 * self::MINUTES_PER_LINK) / 60, 1),
        ];
    }

    /**
     * Composite site health score (0-100): mostly link coverage, penalised
     * for orphaned content and broken links.
     */
    public static function health_score($s)
    {
        $total = max(1, (int) $s['total_posts']);
        $orphan_ratio = min(1, $s['orphaned'] / $total);
        $broken_ratio = $s['total_links'] > 0 ? min(1, $s['broken'] / $s['total_links']) : 0;

        $score = ($s['coverage'] * 0.60)
            + ((1 - $orphan_ratio) * 100 * 0.25)
            + ((1 - $broken_ratio) * 100 * 0.15);

        return (int) round(max(0, min(100, $score)));
    }

    /**
     * Link quality score (0-10): how well-connected the site is — average
     * internal links per post, plus how many posts link out at all.
     */
    public static function quality_score($s)
    {
        $total = max(1, (int) $s['total_posts']);
        $avg_internal = $s['internal'] / $total;      // ideal ~3+ per post
        $outbound_ratio = $s['with_outbound'] / $total;

        $density = min(1, $avg_internal / 3);
        $score = ($density * 0.6 + $outbound_ratio * 0.4) * 10;

        return round(max(0, min(10, $score)), 1);
    }

    /**
     * Rating band for a 0-100 style value.
     *
     * @return array [label, level]
     */
    public static function band($pct)
    {
        if ($pct >= 85) {
            return [__('Excellent', 'smartlinker'), 'good'];
        }
        if ($pct >= 70) {
            return [__('Good', 'smartlinker'), 'good'];
        }
        if ($pct >= 50) {
            return [__('Needs Work', 'smartlinker'), 'warn'];
        }
        return [__('Poor', 'smartlinker'), 'bad'];
    }

    /**
     * Prioritised recommendations based on the current state of the site.
     */
    public static function recommendations($s)
    {
        $out = [];

        if ($s['total_links'] === 0) {
            $out[] = [
                'title'  => __('Run your first scan', 'smartlinker'),
                'desc'   => __('SmartLinker has not indexed your links yet. Scan the site to unlock every report and suggestion.', 'smartlinker'),
                'impact' => 'high',
                'url'    => wp_nonce_url(Slk_Reports::url('overview', ['slk_rescan' => 1]), 'slk_rescan'),
                'action' => __('Scan now', 'smartlinker'),
            ];
            return $out;
        }

        if ($s['orphaned'] > 0) {
            $out[] = [
                'title'  => __('Orphaned Posts', 'smartlinker'),
                'desc'   => __('Brings your hidden pages back to life by adding internal links that help visitors and search engines find them.', 'smartlinker'),
                'impact' => 'high',
                'url'    => admin_url('admin.php?page=smartlinker_orphans'),
                'action' => __('Review', 'smartlinker'),
            ];
        }
        if ($s['broken'] > 0) {
            $out[] = [
                'title'  => __('Broken Links', 'smartlinker'),
                'desc'   => __('Dead links waste crawl budget and frustrate readers. Repair or remove them to protect your rankings.', 'smartlinker'),
                'impact' => 'high',
                'url'    => Slk_Reports::url('broken'),
                'action' => __('Review', 'smartlinker'),
            ];
        }
        if ($s['coverage'] < 85) {
            $out[] = [
                'title'  => __('Link Coverage', 'smartlinker'),
                'desc'   => __('Fills in missing internal links across your site so your content works together and performs better in search.', 'smartlinker'),
                'impact' => $s['coverage'] < 50 ? 'high' : 'medium',
                'url'    => admin_url('admin.php?page=smartlinker_inbound'),
                'action' => __('Review', 'smartlinker'),
            ];
        }

        return $out;
    }

    /**
     * Features that are configured-but-unused, to surface on the dashboard.
     */
    public static function unused_features($s)
    {
        $out = [];

        if (!Slk_Settings::get('track_clicks')) {
            $out[] = [
                'title'  => __('Click Tracking', 'smartlinker'),
                'desc'   => __('See which internal links actually get clicked, so you can improve weak anchor text.', 'smartlinker'),
                'url'    => admin_url('admin.php?page=smartlinker_settings'),
                'action' => __('Enable', 'smartlinker'),
            ];
        }
        if (!Slk_AI::is_configured()) {
            $out[] = [
                'title'  => __('AI Suggestions', 'smartlinker'),
                'desc'   => __('Add your OpenAI key for semantic, context-aware link suggestions with relevance scores.', 'smartlinker'),
                'url'    => admin_url('admin.php?page=smartlinker_ai'),
                'action' => __('Set up', 'smartlinker'),
            ];
        }
        if (empty(Slk_Keyword::all(true))) {
            $out[] = [
                'title'  => __('Auto-Linking', 'smartlinker'),
                'desc'   => __('Create keyword rules once and let SmartLinker link them everywhere, automatically.', 'smartlinker'),
                'url'    => admin_url('admin.php?page=smartlinker_autolinks'),
                'action' => __('Review', 'smartlinker'),
            ];
        }
        if (empty(Slk_MoneyPage::ids())) {
            $out[] = [
                'title'  => __('Money Pages', 'smartlinker'),
                'desc'   => __('Mark your revenue pages so SmartLinker prioritises building links to them.', 'smartlinker'),
                'url'    => admin_url('admin.php?page=smartlinker_money_pages'),
                'action' => __('Review', 'smartlinker'),
            ];
        }
        if (!Slk_SearchConsole::has_data()) {
            $out[] = [
                'title'  => __('Search Console Priorities', 'smartlinker'),
                'desc'   => __('Import your Search Console export to find high-impression pages that lack internal links.', 'smartlinker'),
                'url'    => admin_url('admin.php?page=smartlinker_search_console'),
                'action' => __('Import', 'smartlinker'),
            ];
        }

        return array_slice($out, 0, 4);
    }

    /**
     * Time-of-day greeting.
     */
    public static function greeting()
    {
        $hour = (int) current_time('G');
        if ($hour < 12) {
            return __('Good morning', 'smartlinker');
        }
        if ($hour < 18) {
            return __('Good afternoon', 'smartlinker');
        }
        return __('Good evening', 'smartlinker');
    }

    public static function render_page()
    {
        $stats = self::stats();
        $health = self::health_score($stats);
        $quality = self::quality_score($stats);
        $recommendations = self::recommendations($stats);
        $unused = self::unused_features($stats);
        $setup_steps = Slk_Setup::steps();
        $show_setup = Slk_Setup::should_show($setup_steps);
        // Empty until two readings exist; the strip hides itself rather than
        // drawing a line through one point.
        $trends = Slk_History::trends(30);
        $trend_ready = Slk_History::count_rows() >= 2;
        include SLK_PLUGIN_DIR . 'templates/dashboard.php';
    }
}
