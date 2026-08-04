<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Internal linking report: sitewide summary + per-post inbound/outbound counts,
 * orphaned content detection, and a full re-scan action.
 */
class Slk_Report
{
    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_rescan']);
    }

    /**
     * Re-index every enabled post's links.
     */
    public static function handle_rescan()
    {
        if (!Slk_Reports::on_tab('overview')) {
            return;
        }
        if (empty($_GET['slk_rescan']) || !check_admin_referer('slk_rescan')) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        if (!empty($types)) {
            $ph = implode(',', array_fill(0, count($types), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($ph)",
                $types
            ));
            foreach ($ids as $id) {
                Slk_Link::index_post((int) $id);
            }
        }

        wp_safe_redirect(Slk_Reports::url('overview', ['rescanned' => count($ids ?? [])]));
        exit;
    }

    /**
     * Sitewide totals for the dashboard cards.
     */
    public static function summary()
    {
        global $wpdb;
        $table = Slk_Query::links_table();

        $internal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE type='internal'");
        $external = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE type='external'");

        $types = Slk_Settings::enabled_post_types();
        $total_posts = 0;
        $orphaned = 0;
        if (!empty($types)) {
            $ph = implode(',', array_fill(0, count($types), '%s'));
            // phpcs:ignore WordPress.DB.PreparedSQL
            $total_posts = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ($ph)",
                $types
            ));
            // Orphaned = published posts with zero inbound internal links.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $orphaned = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} p
                 WHERE p.post_status='publish' AND p.post_type IN ($ph)
                 AND NOT EXISTS (SELECT 1 FROM {$table} l WHERE l.target_post_id = p.ID AND l.type='internal')",
                $types
            ));
        }

        $broken = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE broken = 1");

        return compact('internal', 'external', 'total_posts', 'orphaned', 'broken');
    }

    /**
     * Per-post rows: outbound internal, inbound internal, clicks.
     */
    public static function post_rows($limit = 100, $offset = 0)
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        $clicks = Slk_Query::clicks_table();
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));

        $sql = "SELECT p.ID, p.post_title, p.post_type,
                    (SELECT COUNT(*) FROM {$links} lo WHERE lo.post_id = p.ID AND lo.type='internal') AS outbound,
                    (SELECT COUNT(*) FROM {$links} li WHERE li.target_post_id = p.ID AND li.type='internal') AS inbound,
                    (SELECT COUNT(*) FROM {$clicks} c WHERE c.target_post_id = p.ID) AS clicks
                FROM {$wpdb->posts} p
                WHERE p.post_status='publish' AND p.post_type IN ($ph)
                ORDER BY inbound ASC, p.post_date DESC
                LIMIT %d OFFSET %d";
        $args = array_merge($types, [(int) $limit, (int) $offset]);
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    /**
     * The Links Report table: search, filter, sort and paginate in one query.
     *
     * @param array $args page, per_page, search, filter, orderby, order
     * @return array ['rows' => array, 'total' => int]
     */
    public static function table_rows($args = [])
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        $clicks = Slk_Query::clicks_table();
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return ['rows' => [], 'total' => 0];
        }

        $a = wp_parse_args($args, [
            'page' => 1, 'per_page' => 20, 'search' => '',
            'filter' => 'all', 'orderby' => 'inbound', 'order' => 'asc',
        ]);

        $type_ph = implode(',', array_fill(0, count($types), '%s'));
        $params = $types;

        $where = "p.post_status='publish' AND p.post_type IN ($type_ph)";

        if ($a['search'] !== '') {
            $where .= ' AND p.post_title LIKE %s';
            $params[] = '%' . $wpdb->esc_like($a['search']) . '%';
        }

        // Sub-selects reused by SELECT, WHERE and ORDER BY.
        $sql_in  = "(SELECT COUNT(*) FROM {$links} li WHERE li.target_post_id = p.ID AND li.type='internal')";
        $sql_out = "(SELECT COUNT(*) FROM {$links} lo WHERE lo.post_id = p.ID AND lo.type='internal')";
        $sql_ext = "(SELECT COUNT(*) FROM {$links} le WHERE le.post_id = p.ID AND le.type='external')";
        $sql_clk = "(SELECT COUNT(*) FROM {$clicks} c WHERE c.target_post_id = p.ID)";

        switch ($a['filter']) {
            case 'orphaned':
                $where .= " AND {$sql_in} = 0";
                break;
            case 'no_outbound':
                $where .= " AND {$sql_out} = 0";
                break;
            case 'money':
                $where .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = '_slk_money_page')";
                break;
        }

        $order = strtolower($a['order']) === 'desc' ? 'DESC' : 'ASC';
        $orderby_map = [
            'title'    => 'p.post_title',
            'date'     => 'p.post_date',
            'inbound'  => $sql_in,
            'outbound' => $sql_out,
            'external' => $sql_ext,
            'clicks'   => $sql_clk,
        ];
        $orderby = $orderby_map[$a['orderby']] ?? $sql_in;

        // Total (for pagination).
        // phpcs:ignore WordPress.DB.PreparedSQL
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}",
            $params
        ));

        $per_page = max(5, min(200, (int) $a['per_page']));
        $offset = max(0, ((int) $a['page'] - 1) * $per_page);

        $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_date,
                    {$sql_in} AS inbound, {$sql_out} AS outbound,
                    {$sql_ext} AS external, {$sql_clk} AS clicks
                FROM {$wpdb->posts} p
                WHERE {$where}
                ORDER BY {$orderby} {$order}, p.post_title ASC
                LIMIT %d OFFSET %d";
        $params[] = $per_page;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare($sql, $params));

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * The individual links for one post, for the expandable row detail.
     */
    public static function links_for_post($post_id)
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $outbound = $wpdb->get_results($wpdb->prepare(
            "SELECT url, anchor, type, broken, status_code FROM {$links} WHERE post_id = %d ORDER BY type ASC",
            $post_id
        ));
        // phpcs:ignore WordPress.DB.PreparedSQL
        $inbound = $wpdb->get_results($wpdb->prepare(
            "SELECT l.anchor, l.post_id, p.post_title
             FROM {$links} l LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
             WHERE l.target_post_id = %d AND l.type='internal'
             ORDER BY p.post_title ASC",
            $post_id
        ));
        return ['outbound' => $outbound, 'inbound' => $inbound];
    }

    public static function ajax_post_links()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        $data = self::links_for_post($post_id);
        wp_send_json_success([
            'inbound'  => $data['inbound'],
            'outbound' => $data['outbound'],
            'edit'     => Slk_Admin::edit_url($post_id, 'raw'),
        ]);
    }

    public static function render_page()
    {
        $summary = self::summary();

        $args = [
            'page'     => isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1,
            'per_page' => isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20,
            'search'   => isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '',
            'filter'   => isset($_GET['filter']) ? sanitize_key($_GET['filter']) : 'all',
            'orderby'  => isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'inbound',
            'order'    => isset($_GET['order']) ? sanitize_key($_GET['order']) : 'asc',
        ];
        $result = self::table_rows($args);
        $rows = $result['rows'];
        $total = $result['total'];

        include SLK_PLUGIN_DIR . 'templates/report.php';
    }

    /* ---------------------------------------------------------------------
     * Orphaned posts report
     * ------------------------------------------------------------------- */

    /**
     * Published items with zero inbound internal links, oldest first so the
     * longest-abandoned content surfaces at the top.
     */
    public static function orphan_rows($limit = 500)
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));

        $sql = "SELECT p.ID, p.post_title, p.post_type, p.post_date,
                    (SELECT COUNT(*) FROM {$links} lo WHERE lo.post_id = p.ID AND lo.type='internal') AS outbound
                FROM {$wpdb->posts} p
                WHERE p.post_status='publish' AND p.post_type IN ($ph)
                  AND NOT EXISTS (SELECT 1 FROM {$links} l WHERE l.target_post_id = p.ID AND l.type='internal')
                ORDER BY p.post_date ASC
                LIMIT %d";
        $args = array_merge($types, [(int) $limit]);
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare($sql, $args));
    }

    public static function render_orphans_page()
    {
        $rows = self::orphan_rows();
        include SLK_PLUGIN_DIR . 'templates/orphans.php';
    }

    /* ---------------------------------------------------------------------
     * Domain report (outbound external link profile)
     * ------------------------------------------------------------------- */

    public static function domain_rows($limit = 200)
    {
        global $wpdb;
        $table = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT host, COUNT(*) AS links, COUNT(DISTINCT post_id) AS posts
             FROM {$table}
             WHERE type='external' AND host <> ''
             GROUP BY host
             ORDER BY links DESC
             LIMIT %d",
            (int) $limit
        ));
    }

    /**
     * Posts linking out to a given host (for the expandable breakdown).
     */
    public static function domain_detail($host, $limit = 50)
    {
        global $wpdb;
        $table = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.post_id, l.url, l.anchor, p.post_title
             FROM {$table} l LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
             WHERE l.type='external' AND l.host = %s
             ORDER BY p.post_title ASC LIMIT %d",
            $host,
            (int) $limit
        ));
    }

    public static function render_domains_page()
    {
        $rows = self::domain_rows();
        $detail_host = isset($_GET['host']) ? sanitize_text_field(wp_unslash($_GET['host'])) : '';
        $detail = $detail_host !== '' ? self::domain_detail($detail_host) : [];
        include SLK_PLUGIN_DIR . 'templates/domains.php';
    }

    /* ---------------------------------------------------------------------
     * Link clicks report
     * ------------------------------------------------------------------- */

    /**
     * Top clicked internal links within a date range.
     *
     * @param string $range one of '7', '30', 'all'
     */
    public static function click_rows($range = '30', $limit = 100)
    {
        global $wpdb;
        $clicks = Slk_Query::clicks_table();

        $where = '1=1';
        $args = [];
        if ($range !== 'all') {
            $days = (int) $range ?: 30;
            $since = gmdate('Y-m-d H:i:s', strtotime(current_time('mysql') . ' -' . $days . ' days'));
            $where = 'c.clicked_at >= %s';
            $args[] = $since;
        }
        $args[] = (int) $limit;

        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.target_post_id, c.url, c.anchor, COUNT(*) AS clicks,
                    COUNT(DISTINCT c.post_id) AS sources
             FROM {$clicks} c
             WHERE {$where}
             GROUP BY c.target_post_id, c.anchor, c.url
             ORDER BY clicks DESC
             LIMIT %d",
            $args
        ));
    }

    public static function render_clicks_page()
    {
        $range = isset($_GET['range']) ? sanitize_key($_GET['range']) : '30';
        $rows = self::click_rows($range);
        include SLK_PLUGIN_DIR . 'templates/clicks.php';
    }
}
