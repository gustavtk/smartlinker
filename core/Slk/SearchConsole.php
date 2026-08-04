<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Google Search Console integration (data layer).
 *
 * Imports the "Pages" performance export (URL, Clicks, Impressions, CTR,
 * Position) and surfaces an SEO priority report: pages that earn impressions
 * but have few inbound internal links — the highest-value places to add links.
 *
 * The same storage can later be populated by a live OAuth fetch from the
 * Search Analytics API without changing the report.
 */
class Slk_SearchConsole
{
    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_import']);
    }

    public static function handle_import()
    {
        if (empty($_POST['slk_gsc_import'])) {
            return;
        }
        if (!current_user_can('edit_posts') || !check_admin_referer('slk_gsc_import')) {
            return;
        }
        $redirect = admin_url('admin.php?page=smartlinker_search_console');

        if (empty($_FILES['gsc_csv']['tmp_name']) || !is_uploaded_file($_FILES['gsc_csv']['tmp_name'])) {
            wp_safe_redirect($redirect . '&gsc_err=file');
            exit;
        }

        $rows = self::parse_csv($_FILES['gsc_csv']['tmp_name']);
        if ($rows === false) {
            wp_safe_redirect($redirect . '&gsc_err=parse');
            exit;
        }

        global $wpdb;
        $table = self::gsc_table();
        // Fresh import replaces prior data.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query("TRUNCATE TABLE {$table}");

        $imported = 0;
        $now = current_time('mysql');
        foreach ($rows as $r) {
            $url = esc_url_raw($r['url']);
            if ($url === '') {
                continue;
            }
            $wpdb->insert($table, [
                'url'         => $url,
                'url_hash'    => md5(self::normalize_url($url)),
                'post_id'     => (int) url_to_postid($url),
                'clicks'      => (int) $r['clicks'],
                'impressions' => (int) $r['impressions'],
                'ctr'         => (float) $r['ctr'],
                'position'    => (float) $r['position'],
                'imported'    => $now,
            ]);
            $imported++;
        }

        wp_safe_redirect($redirect . '&gsc_imported=' . $imported);
        exit;
    }

    protected static function gsc_table()
    {
        return Slk_Query::gsc_table();
    }

    /**
     * Parse a Search Console "Pages" CSV. Detects the URL/Clicks/Impressions/
     * CTR/Position columns from the header row.
     *
     * @return array|false list of ['url','clicks','impressions','ctr','position']
     */
    public static function parse_csv($path)
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return false;
        }
        $out = [];
        $map = null;

        // Escape passed explicitly: PHP 8.4 deprecates omitting it, and the
        // default changes in PHP 9. '' is RFC 4180 CSV — quotes doubled,
        // nothing else special — which is what other tools produce.
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            }
            if ($map === null) {
                $lower = array_map(function ($c) {
                    return strtolower(trim((string) $c));
                }, $cells);
                $map = [
                    'url'         => self::find_col($lower, ['top pages', 'page', 'url', 'landing page']),
                    'clicks'      => self::find_col($lower, ['clicks']),
                    'impressions' => self::find_col($lower, ['impressions']),
                    'ctr'         => self::find_col($lower, ['ctr']),
                    'position'    => self::find_col($lower, ['position']),
                ];
                // If we couldn't find a URL column, assume positional GSC order.
                if ($map['url'] === null) {
                    $map = ['url' => 0, 'clicks' => 1, 'impressions' => 2, 'ctr' => 3, 'position' => 4];
                }
                continue;
            }

            $url = isset($map['url'], $cells[$map['url']]) ? trim((string) $cells[$map['url']]) : '';
            if ($url === '' || stripos($url, 'http') !== 0) {
                continue;
            }
            $out[] = [
                'url'         => $url,
                'clicks'      => self::num($cells, $map['clicks']),
                'impressions' => self::num($cells, $map['impressions']),
                'ctr'         => self::num($cells, $map['ctr']),
                'position'    => self::num($cells, $map['position']),
            ];
        }
        fclose($handle);
        return $out;
    }

    protected static function find_col($lower, $names)
    {
        foreach ($names as $n) {
            $i = array_search($n, $lower, true);
            if ($i !== false) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Parse a numeric cell (strips %, commas, spaces).
     */
    protected static function num($cells, $index)
    {
        if ($index === null || !isset($cells[$index])) {
            return 0;
        }
        $v = str_replace(['%', ',', ' '], '', (string) $cells[$index]);
        return is_numeric($v) ? (float) $v : 0;
    }

    protected static function normalize_url($url)
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    /**
     * Priority report rows: GSC pages ordered by impressions, joined with the
     * post's inbound internal-link count. High impressions + low inbound = act.
     */
    public static function priority_rows($limit = 200)
    {
        global $wpdb;
        $gsc = self::gsc_table();
        $links = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT g.url, g.post_id, g.clicks, g.impressions, g.ctr, g.position,
                (SELECT COUNT(*) FROM {$links} l WHERE l.target_post_id = g.post_id AND l.type='internal') AS inbound
             FROM {$gsc} g
             ORDER BY g.impressions DESC
             LIMIT %d",
            (int) $limit
        ));
    }

    public static function has_data()
    {
        global $wpdb;
        $gsc = self::gsc_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$gsc}") > 0;
    }

    public static function render_page()
    {
        $rows = self::has_data() ? self::priority_rows() : [];
        include SLK_PLUGIN_DIR . 'templates/search_console.php';
    }
}
