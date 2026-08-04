<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * CSV Link Map: upload a CSV of keyword -> target URL and bulk-insert those
 * links into matching content across the whole site in one pass. Unlike
 * auto-linking rules (applied at render time), this writes real links into
 * post content, using the same safe insertion as manual suggestions.
 */
class Slk_LinkMap
{
    const MAX_ROWS = 2000;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_import']);
    }

    public static function handle_import()
    {
        if (empty($_POST['slk_linkmap_run'])) {
            return;
        }
        if (!current_user_can('edit_others_posts') || !check_admin_referer('slk_linkmap')) {
            return;
        }
        $redirect = admin_url('admin.php?page=smartlinker_link_map');

        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            wp_safe_redirect($redirect . '&lm_err=file');
            exit;
        }

        $rules = self::parse($_FILES['csv']['tmp_name']);
        if (empty($rules)) {
            wp_safe_redirect($redirect . '&lm_err=empty');
            exit;
        }

        $use_stem = (int) Slk_Settings::get('use_stemming', 1) === 1;
        $max_per_rule = isset($_POST['max_posts']) ? max(1, (int) $_POST['max_posts']) : 25;

        $links_added = 0;
        $posts_touched = [];

        foreach ($rules as $rule) {
            $keyword = $rule['keyword'];
            $url = $rule['url'];
            if ($keyword === '' || $url === '') {
                continue;
            }
            $target_id = (int) url_to_postid($url);

            $matches = self::find_posts_with_keyword($keyword, $max_per_rule);
            foreach ($matches as $post) {
                // Never link a post to itself.
                if ($target_id && (int) $post->ID === $target_id) {
                    continue;
                }
                // Skip if this post already links to the target URL.
                if (self::already_links($post->post_content, $url)) {
                    continue;
                }
                $plain = Slk_Post::plain_text($post->post_content);
                $tokens = Slk_Word::tokenize($plain);
                $anchor = Slk_Word::find($plain, $tokens, $keyword, $use_stem);
                if ($anchor === null) {
                    continue;
                }
                $result = Slk_Link::insert_into_post($post->ID, $anchor, $url, [
                    'new_tab'  => Slk_Settings::get('links_open_new_tab'),
                    'nofollow' => Slk_Settings::get('links_nofollow'),
                ]);
                if (!is_wp_error($result)) {
                    $links_added++;
                    $posts_touched[$post->ID] = true;
                }
            }
        }

        wp_safe_redirect($redirect . '&lm_added=' . $links_added . '&lm_posts=' . count($posts_touched) . '&lm_rules=' . count($rules));
        exit;
    }

    /**
     * Parse the link-map CSV (keyword,url with optional header).
     *
     * @return array of ['keyword','url']
     */
    public static function parse($path)
    {
        $handle = fopen($path, 'r');
        if (!$handle) {
            return [];
        }
        $out = [];
        $col = null;
        $rows = 0;
        while (($cells = fgetcsv($handle)) !== false) {
            if ($rows++ > self::MAX_ROWS) {
                break;
            }
            if (isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', $cells[0]);
            }
            if ($col === null) {
                $lower = array_map(function ($c) {
                    return strtolower(trim((string) $c));
                }, $cells);
                if (in_array('keyword', $lower, true) && in_array('url', $lower, true)) {
                    $col = ['keyword' => array_search('keyword', $lower, true), 'url' => array_search('url', $lower, true)];
                    continue;
                }
                $col = ['keyword' => 0, 'url' => 1];
            }
            $keyword = isset($cells[$col['keyword']]) ? sanitize_text_field(trim((string) $cells[$col['keyword']])) : '';
            $url = isset($cells[$col['url']]) ? esc_url_raw(trim((string) $cells[$col['url']])) : '';
            if ($keyword !== '' && $url !== '') {
                $out[] = ['keyword' => $keyword, 'url' => $url];
            }
        }
        fclose($handle);
        return $out;
    }

    /**
     * Published enabled posts whose content contains the keyword.
     */
    protected static function find_posts_with_keyword($keyword, $limit)
    {
        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return [];
        }
        $type_ph = implode(',', array_fill(0, count($types), '%s'));
        $like = '%' . $wpdb->esc_like($keyword) . '%';
        $args = array_merge($types, [$like, (int) $limit]);
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_status='publish' AND post_type IN ($type_ph)
             AND post_content LIKE %s
             LIMIT %d",
            $args
        ));
    }

    protected static function already_links($content, $url)
    {
        foreach (Slk_Link::parse($content) as $link) {
            if (rtrim($link['url'], '/') === rtrim($url, '/')) {
                return true;
            }
        }
        return false;
    }

    public static function render_page()
    {
        include SLK_PLUGIN_DIR . 'templates/link_map.php';
    }
}
