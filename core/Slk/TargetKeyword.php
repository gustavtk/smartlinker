<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Target Keywords: assign a focus keyword to a post, then find every other
 * post that mentions that keyword but does not yet link to the target — so you
 * can add a keyword-anchored internal link in one click.
 */
class Slk_TargetKeyword
{
    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    /* ---------------------------------------------------------------------
     * CRUD
     * ------------------------------------------------------------------- */

    public static function all()
    {
        global $wpdb;
        $table = Slk_Query::target_keywords_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC");
    }

    public static function get($id)
    {
        global $wpdb;
        $table = Slk_Query::target_keywords_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id));
    }

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_target_keywords') {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }
        global $wpdb;
        $table = Slk_Query::target_keywords_table();

        // Add.
        if (!empty($_POST['slk_add_keyword']) && check_admin_referer('slk_target_keyword')) {
            $post_id = (int) ($_POST['post_id'] ?? 0);
            $keyword = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
            if ($post_id > 0 && $keyword !== '' && get_post($post_id)) {
                $wpdb->insert($table, [
                    'post_id' => $post_id,
                    'keyword' => $keyword,
                    'created' => current_time('mysql'),
                ], ['%d', '%s', '%s']);
            }
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_target_keywords&added=1'));
            exit;
        }

        // Delete.
        if (!empty($_GET['slk_delete_kw']) && check_admin_referer('slk_delete_kw')) {
            $wpdb->delete($table, ['id' => (int) $_GET['slk_delete_kw']], ['%d']);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_target_keywords&deleted=1'));
            exit;
        }

        // Import from another SEO plugin. The preview is a plain GET, so only
        // the commit needs a nonce — nothing is written until this runs.
        if (!empty($_POST['slk_import_keywords']) && check_admin_referer('slk_import_keywords')) {
            $source = sanitize_key(wp_unslash($_POST['source'] ?? ''));
            $result = Slk_KeywordImport::import($source);
            wp_safe_redirect(admin_url(
                'admin.php?page=smartlinker_target_keywords'
                . '&kw_imported=' . (int) $result['imported']
                . '&kw_skipped=' . (int) $result['skipped']
                . '&kw_source=' . rawurlencode($source)
            ));
            exit;
        }
    }

    /* ---------------------------------------------------------------------
     * Opportunity engine
     * ------------------------------------------------------------------- */

    /**
     * Source posts that mention $keyword but don't yet link to $target_id.
     *
     * @return array of ['source_id','source_title','phrase','url']
     */
    public static function opportunities($keyword, $target_id)
    {
        $keyword_lc = mb_strtolower(trim((string) $keyword), 'UTF-8');
        if ($keyword_lc === '' || !get_post($target_id)) {
            return [];
        }
        $target_url = get_permalink($target_id);
        $pattern = '/\b' . preg_quote($keyword_lc, '/') . '\b/u';

        $out = [];
        foreach (Slk_Post::candidate_targets($target_id) as $src) {
            // Text already inside a link is excluded, so a keyword that is
            // already linked is not reported as an opportunity.
            $content_lc = mb_strtolower(Slk_Post::linkable_text($src->post_content), 'UTF-8');
            if ($content_lc === '' || mb_strpos($content_lc, $keyword_lc) === false) {
                continue;
            }
            if (!preg_match($pattern, $content_lc)) {
                continue;
            }
            if (Slk_Suggestion::post_links_to($src->ID, $target_id)) {
                continue;
            }
            $out[] = [
                'source_id'    => (int) $src->ID,
                'source_title' => trim(wp_strip_all_tags($src->post_title)),
                'phrase'       => Slk_Suggestion::original_case($src->post_content, $keyword_lc),
                'url'          => $target_url,
            ];
        }
        return $out;
    }

    /**
     * Count opportunities for a keyword row (used on the listing page).
     */
    public static function opportunity_count($keyword, $target_id)
    {
        return count(self::opportunities($keyword, $target_id));
    }

    /* ---------------------------------------------------------------------
     * Rendering + AJAX
     * ------------------------------------------------------------------- */

    /* ---------------------------------------------------------------------
     * Editor panel: keywords for one post
     * ------------------------------------------------------------------- */

    /**
     * Every keyword attached to a post, newest first.
     *
     * @return array<int,array{id:int,keyword:string,source:string}>
     */
    public static function for_post($post_id)
    {
        global $wpdb;
        $table = Slk_Query::target_keywords_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, keyword FROM {$table} WHERE post_id = %d ORDER BY id DESC",
            (int) $post_id
        ));

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $seen[mb_strtolower($r->keyword)] = true;
            $out[] = ['id' => (int) $r->id, 'keyword' => (string) $r->keyword, 'source' => 'smartlinker'];
        }

        // The SEO plugin's own keyword belongs here too — it is what the
        // anchor engine actually uses, so hiding it would be misleading.
        $live = Slk_KeywordImport::live_focus_keywords();
        if (isset($live[(int) $post_id]) && !isset($seen[mb_strtolower($live[(int) $post_id])])) {
            array_unshift($out, [
                'id'      => 0, // not ours to delete
                'keyword' => $live[(int) $post_id],
                'source'  => 'seo',
            ]);
        }
        return $out;
    }

    public static function ajax_keywords()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }

        global $wpdb;
        $table = Slk_Query::target_keywords_table();
        $action = isset($_POST['op']) ? sanitize_key($_POST['op']) : 'list';

        if ($action === 'add') {
            $kw = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
            if ($kw !== '') {
                // phpcs:ignore WordPress.DB.PreparedSQL
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE post_id = %d AND LOWER(keyword) = %s",
                    $post_id,
                    mb_strtolower($kw)
                ));
                if (!$exists) {
                    $wpdb->insert($table, [
                        'post_id' => $post_id,
                        'keyword' => $kw,
                        'created' => current_time('mysql'),
                    ], ['%d', '%s', '%s']);
                }
            }
        } elseif ($action === 'remove') {
            $id = (int) ($_POST['keyword_id'] ?? 0);
            if ($id) {
                $wpdb->delete($table, ['id' => $id, 'post_id' => $post_id], ['%d', '%d']);
            }
        }

        wp_send_json_success(['keywords' => self::for_post($post_id)]);
    }

    /**
     * Ask the AI for target keywords for one post — one call, cheap.
     *
     * Generating them beats guessing: the anchor engine's best tier matches a
     * focus keyword verbatim, so a post with none falls back to weaker tiers.
     */
    public static function ajax_extract()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        if (!Slk_AI::is_configured()) {
            wp_send_json_error(['message' => __('Add an OpenAI API key under Settings → AI first.', 'smartlinker')]);
        }

        $post = get_post($post_id);
        if (!$post) {
            wp_send_json_error(['message' => __('Post not found.', 'smartlinker')]);
        }

        $content = mb_substr(Slk_Post::plain_text($post->post_content), 0, 4000);
        $system = 'You extract target keywords for a web page, for internal-link anchor text. '
            . 'Return 4 to 6 short keyword phrases this page should rank for. '
            . 'Each must be 2 to 5 words, lowercase, and read like something a person would search. '
            . 'Prefer phrases that actually appear in the page. '
            . 'No brand names unless the page is about that brand, no single generic words. '
            . 'Respond ONLY as JSON: {"keywords":["...","..."]}.';

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => max(30, (int) Slk_Settings::get('ai_timeout', 45)),
            'headers' => [
                'Authorization' => 'Bearer ' . trim((string) Slk_Settings::get('openai_api_key', '')),
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode([
                'model'           => Slk_AI::model(),
                'temperature'     => 0,
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "TITLE: {$post->post_title}\n\nCONTENT:\n{$content}"],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            Slk_AI::log_error($response->get_error_message(), 'keywords/transport');
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? sprintf(__('OpenAI API error (HTTP %d).', 'smartlinker'), $code);
            Slk_AI::log_error($msg, 'keywords/HTTP ' . $code);
            wp_send_json_error(['message' => $msg]);
        }

        $parsed = json_decode($data['choices'][0]['message']['content'] ?? '', true);
        if (!is_array($parsed) || empty($parsed['keywords']) || !is_array($parsed['keywords'])) {
            wp_send_json_error(['message' => __('Could not read the AI response.', 'smartlinker')]);
        }

        global $wpdb;
        $table = Slk_Query::target_keywords_table();
        $added = 0;
        foreach (array_slice($parsed['keywords'], 0, 8) as $kw) {
            $kw = sanitize_text_field(trim((string) $kw));
            if ($kw === '') {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE post_id = %d AND LOWER(keyword) = %s",
                $post_id,
                mb_strtolower($kw)
            ));
            if ($exists) {
                continue;
            }
            $wpdb->insert($table, [
                'post_id' => $post_id,
                'keyword' => $kw,
                'created' => current_time('mysql'),
            ], ['%d', '%s', '%s']);
            $added++;
        }

        wp_send_json_success(['keywords' => self::for_post($post_id), 'added' => $added]);
    }

    public static function render_page()
    {
        $keywords = self::all();
        $import_sources = Slk_KeywordImport::sources();
        $preview_source = isset($_GET['kw_preview']) ? sanitize_key(wp_unslash($_GET['kw_preview'])) : '';
        $preview_rows = $preview_source ? Slk_KeywordImport::candidates($preview_source) : [];
        include SLK_PLUGIN_DIR . 'templates/target_keywords.php';
    }

    public static function ajax_find_opportunities()
    {
        check_ajax_referer('slk_ajax', 'nonce');
        $id = isset($_POST['keyword_id']) ? (int) $_POST['keyword_id'] : 0;
        $row = self::get($id);
        if (!$row || !current_user_can('edit_posts')) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }
        wp_send_json_success([
            'opportunities' => self::opportunities($row->keyword, $row->post_id),
        ]);
    }
}
