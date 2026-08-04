<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Money Pages: mark your most important revenue pages so SmartLinker tracks
 * how many inbound internal links each one has and highlights the gaps.
 * Stored as post meta so it travels with the post.
 */
class Slk_MoneyPage
{
    const META = '_slk_money_page';

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_money_pages') {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }

        if (!empty($_POST['slk_add_money']) && check_admin_referer('slk_money')) {
            $post_id = (int) ($_POST['post_id'] ?? 0);
            if ($post_id > 0 && get_post($post_id)) {
                update_post_meta($post_id, self::META, 1);
            }
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_money_pages&added=1'));
            exit;
        }

        if (!empty($_GET['slk_remove_money']) && check_admin_referer('slk_remove_money')) {
            delete_post_meta((int) $_GET['slk_remove_money'], self::META);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_money_pages&removed=1'));
            exit;
        }
    }

    /**
     * Money pages with their inbound internal link counts, weakest first.
     */
    public static function rows()
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_type,
                    (SELECT COUNT(*) FROM {$links} l WHERE l.target_post_id = p.ID AND l.type='internal') AS inbound
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
             WHERE p.post_status = 'publish'
             ORDER BY inbound ASC",
            self::META
        ));
    }

    public static function ids()
    {
        $ids = [];
        foreach (self::rows() as $r) {
            $ids[] = (int) $r->ID;
        }
        return $ids;
    }

    public static function is_money_page($post_id)
    {
        return (bool) get_post_meta((int) $post_id, self::META, true);
    }

    public static function render_page()
    {
        $rows = self::rows();
        $existing = wp_list_pluck($rows, 'ID');
        $candidates = get_posts([
            'post_type'   => Slk_Settings::enabled_post_types(),
            'post_status' => 'publish',
            'numberposts' => 500,
            'orderby'     => 'title',
            'order'       => 'ASC',
            'exclude'     => $existing,
        ]);
        include SLK_PLUGIN_DIR . 'templates/money_pages.php';
    }
}
