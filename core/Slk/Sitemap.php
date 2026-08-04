<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * External-site linking. Import another site's XML sitemap; its URLs become
 * candidate targets so outbound suggestions can also propose links to that
 * external content (e.g. a sister site you own).
 */
class Slk_Sitemap
{
    const MAX_URLS = 2000;
    const MAX_CHILD_SITEMAPS = 30;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_external') {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }

        // Import a sitemap.
        if (!empty($_POST['slk_add_sitemap']) && check_admin_referer('slk_sitemap')) {
            $sitemap_url = esc_url_raw(trim(wp_unslash($_POST['sitemap_url'] ?? '')));
            $label = sanitize_text_field(wp_unslash($_POST['site_label'] ?? ''));
            if ($sitemap_url === '') {
                wp_safe_redirect(admin_url('admin.php?page=smartlinker_external&err=url'));
                exit;
            }
            if ($label === '') {
                $label = wp_parse_url($sitemap_url, PHP_URL_HOST) ?: __('External site', 'smartlinker');
            }
            $count = self::import($sitemap_url, $label);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_external&imported=' . (int) $count));
            exit;
        }

        // Delete a site's URLs.
        if (!empty($_GET['slk_del_site']) && check_admin_referer('slk_del_site')) {
            global $wpdb;
            $label = sanitize_text_field(wp_unslash($_GET['slk_del_site']));
            $wpdb->delete(Slk_Query::external_table(), ['site_label' => $label], ['%s']);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_external&deleted=1'));
            exit;
        }
    }

    /**
     * Fetch and parse a sitemap (handles sitemap index), storing its URLs.
     *
     * @return int number of URLs imported
     */
    public static function import($sitemap_url, $label)
    {
        $urls = self::fetch_urls($sitemap_url, 0);
        if (empty($urls)) {
            return 0;
        }

        global $wpdb;
        $table = Slk_Query::external_table();
        $home_host = wp_parse_url(home_url(), PHP_URL_HOST);
        $now = current_time('mysql');
        $count = 0;

        foreach (array_slice($urls, 0, self::MAX_URLS) as $url) {
            // Skip URLs on this same site — those are internal, handled already.
            $host = wp_parse_url($url, PHP_URL_HOST);
            if ($host && strcasecmp($host, (string) $home_host) === 0) {
                continue;
            }
            $hash = md5(rtrim(strtolower($url), '/'));
            // De-dupe by hash.
            // phpcs:ignore WordPress.DB.PreparedSQL
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE url_hash = %s", $hash));
            if ($exists) {
                continue;
            }
            $wpdb->insert($table, [
                'site_label' => $label,
                'url'        => esc_url_raw($url),
                'url_hash'   => $hash,
                'title'      => self::title_from_url($url),
                'imported'   => $now,
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Recursively fetch <loc> URLs from a sitemap or sitemap index.
     */
    protected static function fetch_urls($sitemap_url, $depth)
    {
        if ($depth > 1) {
            return []; // index -> child only, no deeper
        }
        $response = wp_remote_get($sitemap_url, [
            'timeout'    => 20,
            'sslverify'  => false,
            'user-agent' => 'SmartLinker/' . SLK_VERSION,
        ]);
        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 400) {
            return [];
        }
        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return [];
        }

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            return [];
        }

        $urls = [];

        // Sitemap index -> recurse into child sitemaps.
        if (isset($xml->sitemap)) {
            $children = 0;
            foreach ($xml->sitemap as $sm) {
                if ($children++ >= self::MAX_CHILD_SITEMAPS) {
                    break;
                }
                $loc = trim((string) $sm->loc);
                if ($loc !== '') {
                    $urls = array_merge($urls, self::fetch_urls($loc, $depth + 1));
                }
                if (count($urls) >= self::MAX_URLS) {
                    break;
                }
            }
        }

        // URL set.
        if (isset($xml->url)) {
            foreach ($xml->url as $u) {
                $loc = trim((string) $u->loc);
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }
        }

        return $urls;
    }

    /**
     * Derive a human title from a URL slug (last path segment).
     */
    public static function title_from_url($url)
    {
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        if ($path === '') {
            return wp_parse_url($url, PHP_URL_HOST) ?: $url;
        }
        $segments = explode('/', $path);
        $slug = end($segments);
        $slug = preg_replace('/\.(html?|php|aspx?)$/i', '', $slug);
        $slug = str_replace(['-', '_', '+'], ' ', rawurldecode($slug));
        $slug = trim(preg_replace('/\s+/', ' ', $slug));
        return $slug !== '' ? ucwords($slug) : $url;
    }

    /**
     * External candidates for the suggestion engine: [{title, url}].
     */
    public static function candidates()
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }
        global $wpdb;
        $table = Slk_Query::external_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results("SELECT title, url, site_label FROM {$table} WHERE title <> ''");
        return $rows;
    }


    /**
     * Site summary rows for the admin listing.
     */
    public static function sites()
    {
        global $wpdb;
        $table = Slk_Query::external_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results("SELECT site_label, COUNT(*) AS url_count, MAX(imported) AS imported FROM {$table} GROUP BY site_label ORDER BY imported DESC");
    }

    public static function render_page()
    {
        $sites = self::sites();
        include SLK_PLUGIN_DIR . 'templates/external_sites.php';
    }
}
