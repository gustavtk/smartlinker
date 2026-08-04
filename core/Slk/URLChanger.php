<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * URL Changer: find a URL used across your content and replace it everywhere,
 * optionally leaving a 301 redirect from the old URL to the new one.
 */
class Slk_URLChanger
{
    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_submit']);

        // Only hook the redirect handler if at least one redirect is stored.
        if (self::has_redirects()) {
            add_action('template_redirect', [__CLASS__, 'maybe_redirect'], 1);
        }
    }

    /* ---------------------------------------------------------------------
     * Admin: find & replace
     * ------------------------------------------------------------------- */

    public static function handle_submit()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_url_changer') {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }

        // Remove a redirect (keeps the change history row).
        if (!empty($_GET['slk_del_redirect']) && check_admin_referer('slk_del_redirect')) {
            self::delete_redirect((int) $_GET['slk_del_redirect']);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_url_changer&rmredir=1'));
            exit;
        }

        if (empty($_POST['slk_url_change']) || !check_admin_referer('slk_url_change')) {
            return;
        }

        $old = esc_url_raw(trim(wp_unslash($_POST['old_url'] ?? '')));
        $new = esc_url_raw(trim(wp_unslash($_POST['new_url'] ?? '')));
        $redirect = empty($_POST['add_redirect']) ? 0 : 1;

        if ($old === '' || $new === '' || $old === $new) {
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_url_changer&err=input'));
            exit;
        }

        $stats = self::replace_sitewide($old, $new);

        global $wpdb;
        $wpdb->insert(Slk_Query::url_changes_table(), [
            'old_url'       => $old,
            'new_url'       => $new,
            'old_path'      => self::path_of($old),
            'posts_changed' => $stats['posts'],
            'occurrences'   => $stats['occurrences'],
            'redirect'      => $redirect,
            'created'       => current_time('mysql'),
        ], ['%s', '%s', '%s', '%d', '%d', '%d', '%s']);

        wp_safe_redirect(admin_url(sprintf(
            'admin.php?page=smartlinker_url_changer&done=1&posts=%d&occ=%d',
            $stats['posts'],
            $stats['occurrences']
        )));
        exit;
    }

    /**
     * Pattern that matches a URL only at a boundary.
     *
     * A plain str_replace() is unsafe here: replacing "?p=99" would also
     * corrupt "?p=999999", and replacing "/guide" would hit "/guide-2".
     * We therefore require the match to be followed by a delimiter (quote,
     * whitespace, tag bracket, etc.) or end of string, and allow an optional
     * trailing slash so "/page" also matches "/page/".
     */
    protected static function match_pattern($old)
    {
        $bare = rtrim($old, '/');
        return '#' . preg_quote($bare, '#') . '/?(?=[\'"\s<>\)\]]|$)#';
    }

    /**
     * Count how many posts/occurrences a change would affect, without
     * modifying anything. Powers the "this will update N links" preview.
     *
     * @return array ['posts' => int, 'occurrences' => int]
     */
    public static function preview($old)
    {
        $types = Slk_Settings::enabled_post_types();
        if (empty($types) || $old === '') {
            return ['posts' => 0, 'occurrences' => 0];
        }
        $pattern = self::match_pattern($old);
        $posts = 0;
        $occurrences = 0;

        // The LIKE narrows this to posts that mention the URL, which on most
        // edits is a handful. It is not a bound: replacing a bare domain, or
        // http with https, matches nearly every post on the site — exactly
        // the case someone reaches for this tool to do.
        Slk_Post::walk_content(
            self::ids_containing($old),
            function ($row) use ($pattern, &$posts, &$occurrences) {
                $n = preg_match_all($pattern, $row->post_content);
                if ($n > 0) {
                    $posts++;
                    $occurrences += $n;
                }
            }
        );

        return ['posts' => $posts, 'occurrences' => $occurrences];
    }

    /**
     * Ids of posts whose content mentions $needle, across every status the
     * rewriter touches — drafts and scheduled posts included, because a URL
     * left stale in a draft is published broken later.
     */
    protected static function ids_containing($needle)
    {
        global $wpdb;

        $types = Slk_Settings::enabled_post_types();
        if (empty($types) || $needle === '') {
            return [];
        }

        $type_ph = implode(',', array_fill(0, count($types), '%s'));
        $args = array_merge($types, ['%' . $wpdb->esc_like($needle) . '%']);

        // phpcs:ignore WordPress.DB.PreparedSQL
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status IN ('publish','draft','pending','private','future')
               AND post_type IN ($type_ph)
               AND post_content LIKE %s
             ORDER BY ID ASC",
            $args
        )));
    }

    public static function ajax_preview()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        if (!current_user_can('manage_categories')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        $old = isset($_POST['old_url']) ? esc_url_raw(wp_unslash($_POST['old_url'])) : '';
        wp_send_json_success(self::preview($old));
    }

    /**
     * Replace every occurrence of $old with $new in enabled post content.
     *
     * @return array ['posts' => int, 'occurrences' => int]
     */
    public static function replace_sitewide($old, $new)
    {
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return ['posts' => 0, 'occurrences' => 0];
        }

        $pattern = self::match_pattern($old);
        $posts = 0;
        $occurrences = 0;

        /*
         * Every rewritten post is logged with the content it had beforehand,
         * under one batch token.
         *
         * This is the most destructive thing the plugin does — a find and
         * replace across every post at once — and until now it was the only
         * write with no way back. The preview said how much would change; it
         * could not put anything back afterwards.
         */
        $batch = Slk_Activity::new_batch();

        // The ids are resolved up front, before any post is rewritten. That
        // matters here in a way it does not for the read-only walks: each
        // update changes the very content the LIKE matches on, so a query run
        // slice-by-slice against live data would shift underneath itself and
        // skip posts. A fixed id list is walked exactly once.
        Slk_Post::walk_content(
            self::ids_containing($old),
            function ($row) use ($pattern, $new, $old, $batch, &$posts, &$occurrences) {
                $count = 0;
                $updated = preg_replace($pattern, str_replace('$', '\\$', $new), $row->post_content, -1, $count);
                if ($count > 0 && $updated !== null && $updated !== $row->post_content) {
                    // Recorded BEFORE the write, so a failure part-way through
                    // still leaves every completed post restorable.
                    Slk_Activity::record(
                        $row->ID,
                        'rewrite',
                        sprintf(
                            /* translators: 1: the old URL, 2: the new URL */
                            __('Repointed %1$s to %2$s', 'smartlinker'),
                            $old,
                            $new
                        ),
                        $row->post_content,
                        $updated,
                        $new,
                        $batch
                    );
                    wp_update_post(['ID' => $row->ID, 'post_content' => $updated]);
                    Slk_Link::index_post($row->ID);
                    $posts++;
                    $occurrences += $count;
                }
            }
        );

        return ['posts' => $posts, 'occurrences' => $occurrences, 'batch' => $batch];
    }

    /* ---------------------------------------------------------------------
     * Frontend: 301 redirects
     * ------------------------------------------------------------------- */

    /**
     * Redirect the current request if its path matches a stored redirect.
     */
    public static function maybe_redirect()
    {
        if (is_admin()) {
            return;
        }
        $request_path = self::normalize_path(rawurldecode($_SERVER['REQUEST_URI'] ?? ''));
        if ($request_path === '') {
            return;
        }

        foreach (self::redirects() as $r) {
            if ($r->old_path === '' || $r->new_url === '') {
                continue;
            }
            if (self::normalize_path($r->old_path) === $request_path) {
                // Guard against redirecting a URL to itself.
                if (self::normalize_path(self::path_of($r->new_url)) === $request_path
                    && self::same_host($r->new_url)) {
                    return;
                }
                /*
                 * wp_redirect, not wp_safe_redirect, and deliberately so.
                 *
                 * This feature exists to repoint a URL that content has moved
                 * away from, and "moved" sometimes means to another site.
                 * wp_safe_redirect() would silently rewrite any off-site
                 * destination to wp-admin, quietly breaking the redirect the
                 * admin explicitly asked for.
                 *
                 * The destination is not visitor input: it comes from the
                 * plugin's own table, writable only with manage_categories,
                 * behind a nonce, and stored through esc_url_raw().
                 */
                // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
                wp_redirect($r->new_url, 301);
                exit;
            }
        }
    }

    /* ---------------------------------------------------------------------
     * Data helpers
     * ------------------------------------------------------------------- */

    public static function all_changes($limit = 200)
    {
        global $wpdb;
        $table = Slk_Query::url_changes_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
            (int) $limit
        ));
    }

    /**
     * Active redirect rows, cached per request.
     */
    public static function redirects()
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }
        global $wpdb;
        $table = Slk_Query::url_changes_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results("SELECT old_path, new_url FROM {$table} WHERE redirect = 1");
        return $rows;
    }

    public static function has_redirects()
    {
        global $wpdb;
        $table = Slk_Query::url_changes_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE redirect = 1") > 0;
    }

    public static function delete_redirect($id)
    {
        global $wpdb;
        // Remove the redirect but keep the change record.
        $wpdb->update(Slk_Query::url_changes_table(), ['redirect' => 0], ['id' => (int) $id], ['%d'], ['%d']);
    }

    /**
     * Extract the path (+query) portion of a URL for redirect matching.
     */
    public static function path_of($url)
    {
        $parts = wp_parse_url($url);
        $path = $parts['path'] ?? '/';
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }

    protected static function normalize_path($path)
    {
        // Drop a trailing slash (except root) so /foo and /foo/ match.
        $path = trim($path);
        if ($path !== '/' && substr($path, -1) === '/') {
            $path = rtrim($path, '/');
        }
        return $path;
    }

    protected static function same_host($url)
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        return empty($host) || strcasecmp((string) $host, (string) wp_parse_url(home_url(), PHP_URL_HOST)) === 0;
    }

    public static function render_page()
    {
        $changes = self::all_changes();
        include SLK_PLUGIN_DIR . 'templates/url_changer.php';
    }
}
