<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Topic clusters — the whole site as one map.
 *
 * Clusters come from the site's own taxonomy by default, so the page works
 * with no API key and no setup. An optional AI pass re-groups the same posts
 * into editorial themes and tags each with a funnel stage.
 *
 * Every number shown is measured, never estimated: cluster size is a post
 * count, and the "health" colour is the share of a cluster's posts that have
 * at least one inbound internal link from a sibling in the same cluster.
 */
class Slk_Cluster
{
    /** Where the AI theming result lives (no new table needed). */
    const AI_OPTION = 'slk_clusters_ai';

    /** Cached derived snapshot. */
    const TRANSIENT = 'slk_clusters_snapshot';

    /** Ask the model for roughly this many themes. */
    const AI_TARGET_CLUSTERS = 8;

    /** Cap titles sent to the model so one call stays cheap. */
    const AI_MAX_TITLES = 400;

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('save_post', [__CLASS__, 'flush']);
        add_action('deleted_post', [__CLASS__, 'flush']);
        add_action('set_object_terms', [__CLASS__, 'flush']);
    }

    public static function flush()
    {
        delete_transient(self::TRANSIENT);
    }

    /* ---------------------------------------------------------------------
     * Deriving the map
     * ------------------------------------------------------------------- */

    /**
     * Clusters plus their link metrics, and the site totals above them.
     *
     * @return array{
     *   clusters: array<int,array>, totals: array, source: string, themed_at: string
     * }
     */
    public static function snapshot($force = false)
    {
        $cached = get_transient(self::TRANSIENT);
        if (!$force && is_array($cached)) {
            return $cached;
        }

        $posts = self::published_posts();
        $groups = self::grouping($posts);          // cluster name => [post ids]
        $inbound = self::inbound_map();            // post id => inbound internal link count
        $edges = self::internal_edges();           // source id => [target ids]
        $ai = get_option(self::AI_OPTION, []);

        $clusters = [];
        foreach ($groups as $name => $ids) {
            if (empty($ids)) {
                continue;
            }
            $set = array_flip($ids);

            // A post is "connected" when a sibling in the same cluster links
            // to it. That is what makes a cluster a cluster rather than a
            // pile of posts that happen to share a category.
            // $edges is keyed target => [sources], so this stays linear in
            // the number of links rather than posts x links.
            $connected = 0;
            $internal = 0;
            foreach ($ids as $id) {
                $has_sibling_link = false;
                $sources = isset($edges[$id]) ? $edges[$id] : [];
                foreach ($sources as $src) {
                    if ($src !== (int) $id && isset($set[$src])) {
                        $has_sibling_link = true;
                        $internal++;
                    }
                }
                if ($has_sibling_link) {
                    $connected++;
                }
            }

            // Pillar = the post the rest of the site points at most.
            $pillar_id = 0;
            $pillar_in = -1;
            $orphans = 0;
            foreach ($ids as $id) {
                $in = isset($inbound[$id]) ? (int) $inbound[$id] : 0;
                if ($in > $pillar_in) {
                    $pillar_in = $in;
                    $pillar_id = (int) $id;
                }
                if ($in === 0) {
                    $orphans++;
                }
            }
            // A cluster with nothing pointing into it has no pillar yet.
            if ($pillar_in <= 0) {
                $pillar_id = 0;
            }

            $count = count($ids);
            $clusters[] = [
                'name'      => (string) $name,
                'count'     => $count,
                'ids'       => array_map('intval', $ids),
                'pillar_id' => $pillar_id,
                'pillar'    => $pillar_id ? get_the_title($pillar_id) : '',
                'inbound'   => max(0, $pillar_in),
                'orphans'   => $orphans,
                'internal'  => $internal,
                // 0..1 — share of the cluster reachable from a sibling.
                'health'    => $count > 0 ? round($connected / $count, 4) : 0.0,
                'stage'     => isset($ai['stages'][$name]) ? (string) $ai['stages'][$name] : '',
            ];
        }

        usort($clusters, function ($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        $total_posts = count($posts);
        $total_orphans = 0;
        foreach ($posts as $p) {
            if (empty($inbound[$p->ID])) {
                $total_orphans++;
            }
        }
        $pillars = 0;
        foreach ($clusters as $c) {
            if ($c['pillar_id']) {
                $pillars++;
            }
        }

        $snapshot = [
            'clusters'  => $clusters,
            'source'    => !empty($ai['map']) ? 'ai' : 'taxonomy',
            'themed_at' => isset($ai['created']) ? (string) $ai['created'] : '',
            'totals'    => [
                'clusters' => count($clusters),
                'pillars'  => $pillars,
                'posts'    => $total_posts,
                'orphans'  => $total_orphans,
            ],
        ];

        set_transient(self::TRANSIENT, $snapshot, 15 * MINUTE_IN_SECONDS);
        return $snapshot;
    }

    /** Published posts of the enabled types. */
    protected static function published_posts()
    {
        global $wpdb;
        $types = Slk_Settings::enabled_post_types();
        if (empty($types)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($types), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ($ph)
             ORDER BY ID ASC",
            $types
        ));
    }

    /**
     * Cluster name => post ids. Uses the stored AI theming when present,
     * otherwise the site's own categories.
     */
    protected static function grouping($posts)
    {
        $ai = get_option(self::AI_OPTION, []);
        $valid = [];
        foreach ($posts as $p) {
            $valid[(int) $p->ID] = true;
        }

        if (!empty($ai['map']) && is_array($ai['map'])) {
            $groups = [];
            $assigned = [];
            foreach ($ai['map'] as $name => $ids) {
                $clean = [];
                foreach ((array) $ids as $id) {
                    $id = (int) $id;
                    // Ignore ids the model invented or that have since gone.
                    if (isset($valid[$id]) && !isset($assigned[$id])) {
                        $clean[] = $id;
                        $assigned[$id] = true;
                    }
                }
                if ($clean) {
                    $groups[(string) $name] = $clean;
                }
            }
            // Anything published after the theming run still has to appear.
            $left = array_diff(array_keys($valid), array_keys($assigned));
            if ($left) {
                $groups[__('Unclustered', 'smartlinker')] = array_values($left);
            }
            return $groups;
        }

        // Taxonomy baseline.
        $groups = [];
        $seen = [];
        foreach (get_categories(['hide_empty' => true]) as $cat) {
            $ids = get_posts([
                'category'    => $cat->term_id,
                'post_type'   => Slk_Settings::enabled_post_types(),
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);
            $ids = array_values(array_filter($ids, function ($id) use ($valid) {
                return isset($valid[(int) $id]);
            }));
            if ($ids) {
                $groups[$cat->name] = array_map('intval', $ids);
                foreach ($ids as $id) {
                    $seen[(int) $id] = true;
                }
            }
        }
        // Deliberately not "Uncategorised": WordPress already ships a category
        // called "Uncategorized", and two near-identical tiles on the map is
        // the kind of thing that makes people distrust the whole report.
        $left = array_diff(array_keys($valid), array_keys($seen));
        if ($left) {
            $groups[__('No category', 'smartlinker')] = array_values($left);
        }
        return $groups;
    }

    /** post id => number of internal links pointing at it. */
    protected static function inbound_map()
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            "SELECT target_post_id AS id, COUNT(*) AS n FROM {$links}
             WHERE type = 'internal' AND target_post_id > 0
             GROUP BY target_post_id"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = (int) $r->n;
        }
        return $out;
    }

    /**
     * target post id => [source post ids] for internal links.
     * Keyed by target because every caller asks "who links to this?".
     */
    protected static function internal_edges()
    {
        global $wpdb;
        $links = Slk_Query::links_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            "SELECT post_id, target_post_id FROM {$links}
             WHERE type = 'internal' AND target_post_id > 0 AND post_id > 0"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->target_post_id][] = (int) $r->post_id;
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * AI theming — one call, cached until cleared
     * ------------------------------------------------------------------- */

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_clusters') {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }

        if (!empty($_GET['slk_untheme']) && check_admin_referer('slk_cluster_untheme')) {
            delete_option(self::AI_OPTION);
            self::flush();
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_clusters&unthemed=1'));
            exit;
        }

        if (!empty($_POST['slk_theme_clusters']) && check_admin_referer('slk_cluster_theme')) {
            $started = microtime(true);
            $result = self::theme_with_ai();
            if (is_wp_error($result)) {
                wp_safe_redirect(admin_url('admin.php?page=smartlinker_clusters&theme_err=' . rawurlencode($result->get_error_message())));
                exit;
            }
            $secs = max(1, (int) round(microtime(true) - $started));
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_clusters&themed=' . (int) $result . '&secs=' . $secs));
            exit;
        }
    }

    /**
     * Group every published post into editorial themes with one OpenAI call.
     *
     * @return int|WP_Error number of themes created
     */
    public static function theme_with_ai()
    {
        if (!Slk_AI::is_configured()) {
            return new WP_Error('slk_no_key', __('Add an OpenAI API key under Settings → AI first.', 'smartlinker'));
        }

        $posts = self::published_posts();
        if (count($posts) < 2) {
            return new WP_Error('slk_too_few', __('Not enough published content to cluster yet.', 'smartlinker'));
        }

        $lines = [];
        foreach (array_slice($posts, 0, self::AI_MAX_TITLES) as $p) {
            $lines[] = (int) $p->ID . ': ' . wp_strip_all_tags($p->post_title);
        }

        $system = 'You group a website\'s articles into editorial topic clusters. '
            . 'Given a numbered list of "id: title", group EVERY id into about ' . self::AI_TARGET_CLUSTERS . ' themes '
            . '(fewer if the site is small). Each id must appear in exactly one theme. '
            . 'Theme names must be short, human, and specific to this site\'s subject matter — '
            . 'no generic names like "Miscellaneous" or "Other". '
            . 'Also label each theme with a marketing funnel stage: '
            . '"TOFU" for awareness/how-to content, "MOFU" for comparison/evaluation, "BOFU" for purchase-intent content. '
            . 'Respond ONLY as JSON: {"clusters":[{"name":"Theme name","stage":"TOFU","post_ids":[1,2,3]}]}.';

        $body = [
            'model'           => Slk_AI::model(),
            'temperature'     => 0,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => "ARTICLES:\n" . implode("\n", $lines)],
            ],
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => max(60, (int) Slk_Settings::get('ai_timeout', 45)),
            'headers' => [
                'Authorization' => 'Bearer ' . trim((string) Slk_Settings::get('openai_api_key', '')),
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            Slk_AI::log_error($response->get_error_message(), 'clusters/transport');
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) {
            $msg = $data['error']['message'] ?? sprintf(__('OpenAI API error (HTTP %d).', 'smartlinker'), $code);
            Slk_AI::log_error($msg, 'clusters/HTTP ' . $code);
            return new WP_Error('slk_ai_http', $msg);
        }

        $parsed = json_decode($data['choices'][0]['message']['content'] ?? '', true);
        if (!is_array($parsed) || empty($parsed['clusters']) || !is_array($parsed['clusters'])) {
            Slk_AI::log_error(__('Could not parse the AI clustering response.', 'smartlinker'), 'clusters/parse');
            return new WP_Error('slk_ai_parse', __('Could not parse the AI response.', 'smartlinker'));
        }

        $map = [];
        $stages = [];
        foreach ($parsed['clusters'] as $c) {
            $name = trim(wp_strip_all_tags((string) ($c['name'] ?? '')));
            if ($name === '' || empty($c['post_ids']) || !is_array($c['post_ids'])) {
                continue;
            }
            $stage = strtoupper(trim((string) ($c['stage'] ?? '')));
            if (!in_array($stage, ['TOFU', 'MOFU', 'BOFU'], true)) {
                $stage = '';
            }
            $map[$name] = array_map('intval', $c['post_ids']);
            $stages[$name] = $stage;
        }

        if (empty($map)) {
            return new WP_Error('slk_ai_empty', __('The AI returned no usable clusters.', 'smartlinker'));
        }

        update_option(self::AI_OPTION, [
            'map'     => $map,
            'stages'  => $stages,
            'created' => current_time('mysql'),
        ], false);
        self::flush();

        return count($map);
    }

    public static function render_page()
    {
        $snapshot = self::snapshot();
        include SLK_PLUGIN_DIR . 'templates/clusters.php';
    }
}
