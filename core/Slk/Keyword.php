<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Auto-linking: keyword -> URL rules, managed in admin and applied to
 * post content on the frontend via the_content.
 */
class Slk_Keyword
{
    /** Cache for the per-rule link counts — recomputed when rules change. */
    const COUNT_TRANSIENT = 'slk_autolink_counts';

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);

        // Editing or deleting content changes how many times each rule matches.
        add_action('save_post', [__CLASS__, 'flush_counts']);
        add_action('deleted_post', [__CLASS__, 'flush_counts']);

        if (Slk_Settings::get('autolink_enabled')) {
            // Late priority so other content filters run first.
            add_filter('the_content', [__CLASS__, 'apply_to_content'], 25);
        }
    }

    /**
     * Fetch all rules (optionally only active ones).
     */
    public static function all($active_only = false)
    {
        global $wpdb;
        $table = Slk_Query::autolinks_table();
        $where = $active_only ? ' WHERE active = 1' : '';
        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results("SELECT * FROM {$table}{$where} ORDER BY id DESC");
    }

    /**
     * How many links each rule actually produces across published content.
     *
     * Auto-links are applied at render time and never stored, so there is no
     * table to count — the number has to be derived by running the rules. It
     * therefore reuses apply_to_content()'s own matcher via
     * replace_outside_tags() rather than reimplementing the matching: a second
     * implementation would drift and report links that never appear.
     *
     * Paused rules are counted too, so the column shows what re-activating
     * one would yield. Cached, because this is O(rules x posts).
     *
     * @return array<int,int> rule id => link count
     */
    public static function link_counts($force = false)
    {
        $cached = get_transient(self::COUNT_TRANSIENT);
        if (!$force && is_array($cached)) {
            return $cached;
        }

        $rules = self::all(false);
        $counts = [];
        foreach ($rules as $r) {
            $counts[(int) $r->id] = 0;
        }

        $types = Slk_Settings::enabled_post_types();
        if (empty($rules) || empty($types)) {
            set_transient(self::COUNT_TRANSIENT, $counts, 15 * MINUTE_IN_SECONDS);
            return $counts;
        }

        global $wpdb;
        $ph = implode(',', array_fill(0, count($types), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ($ph)",
            $types
        ));

        // Rules are not independent: whichever runs first turns the text into
        // an anchor, and every later rule skips it. Counting each rule against
        // the untouched post would let two overlapping rules both claim the
        // same words. So replay them in order, mutating as we go — exactly
        // what apply_to_content() does.
        $active = array_filter($rules, function ($r) {
            return !empty($r->active);
        });
        $paused = array_filter($rules, function ($r) {
            return empty($r->active);
        });

        foreach ($posts as $post) {
            $content = $post->post_content;

            foreach ($active as $rule) {
                $content = self::tally($rule, $post->ID, $content, $counts);
            }

            // A paused rule is measured against the content the active rules
            // have already claimed, so its number answers the real question:
            // what would switching this on actually add?
            foreach ($paused as $rule) {
                self::tally($rule, $post->ID, $content, $counts);
            }
        }

        set_transient(self::COUNT_TRANSIENT, $counts, 15 * MINUTE_IN_SECONDS);
        return $counts;
    }

    /**
     * Apply one rule to $content for counting, adding the hits to $counts.
     * Returns the content with this rule's links inserted, so the caller can
     * feed it to the next rule the way the frontend does.
     */
    protected static function tally($rule, $post_id, $content, array &$counts)
    {
        if ($rule->keyword === '') {
            return $content;
        }
        // Mirrors apply_to_content(): a page never links to itself.
        if ((int) $rule->target_post_id === (int) $post_id) {
            return $content;
        }

        // Cheap guard before the expensive walk: if the keyword isn't present
        // as a plain substring, no pattern built from it can match. This is
        // what keeps an O(rules x posts) count viable on a large site.
        $present = $rule->case_sensitive
            ? (strpos($content, $rule->keyword) !== false)
            : (stripos($content, $rule->keyword) !== false);
        if (!$present) {
            return $content;
        }

        $boundary = $rule->partial_match ? '' : '\b';
        $flags = 'u' . ($rule->case_sensitive ? '' : 'i');
        $pattern = '/' . $boundary . '(' . preg_quote($rule->keyword, '/') . ')' . $boundary . '/' . $flags;

        $atts = ['new_tab' => $rule->new_tab, 'nofollow' => $rule->nofollow];

        $n = 0;
        $content = self::replace_outside_tags(
            $content,
            $pattern,
            function ($matchText) use ($rule, $atts) {
                return Slk_Link::build_anchor($matchText, $rule->url, $atts);
            },
            max(1, (int) $rule->max_per_post),
            $n
        );

        $counts[(int) $rule->id] += $n;
        return $content;
    }

    /**
     * Handle add/delete/toggle POST + GET actions on the auto-linking page.
     */
    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_autolinks') {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }
        global $wpdb;
        $table = Slk_Query::autolinks_table();

        // Add rule.
        if (!empty($_POST['slk_add_rule']) && check_admin_referer('slk_autolink')) {
            $in = wp_unslash($_POST);
            $keyword = sanitize_text_field($in['keyword'] ?? '');
            $url = esc_url_raw($in['url'] ?? '');
            if ($keyword !== '' && $url !== '') {
                $wpdb->insert($table, [
                    'keyword'        => $keyword,
                    'url'            => $url,
                    'target_post_id' => (int) url_to_postid($url),
                    'case_sensitive' => empty($in['case_sensitive']) ? 0 : 1,
                    'partial_match'  => empty($in['partial_match']) ? 0 : 1,
                    'new_tab'        => empty($in['new_tab']) ? 0 : 1,
                    'nofollow'       => empty($in['nofollow']) ? 0 : 1,
                    'max_per_post'   => max(1, (int) ($in['max_per_post'] ?? 1)),
                    'active'         => 1,
                    'created'        => current_time('mysql'),
                ]);
            }
            delete_transient(self::COUNT_TRANSIENT);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_autolinks&added=1'));
            exit;
        }

        // Delete rule.
        if (!empty($_GET['slk_delete']) && check_admin_referer('slk_delete_rule')) {
            $wpdb->delete($table, ['id' => (int) $_GET['slk_delete']], ['%d']);
            delete_transient(self::COUNT_TRANSIENT);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_autolinks&deleted=1'));
            exit;
        }

        // Toggle active.
        if (!empty($_GET['slk_toggle']) && check_admin_referer('slk_toggle_rule')) {
            $id = (int) $_GET['slk_toggle'];
            // phpcs:ignore WordPress.DB.PreparedSQL
            $wpdb->query($wpdb->prepare("UPDATE {$table} SET active = 1 - active WHERE id = %d", $id));
            delete_transient(self::COUNT_TRANSIENT);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_autolinks&toggled=1'));
            exit;
        }
    }

    /**
     * Apply active auto-link rules to a piece of content on the frontend.
     */
    public static function apply_to_content($content)
    {
        if (is_admin() || empty($content)) {
            return $content;
        }

        $rules = self::all(true);
        if (empty($rules)) {
            return $content;
        }

        $current_id = get_the_ID();

        foreach ($rules as $rule) {
            // Never link a page to itself.
            if ($current_id && (int) $rule->target_post_id === (int) $current_id) {
                continue;
            }

            $boundary = $rule->partial_match ? '' : '\b';
            $flags = 'u' . ($rule->case_sensitive ? '' : 'i');
            $pattern = '/' . $boundary . '(' . preg_quote($rule->keyword, '/') . ')' . $boundary . '/' . $flags;

            $anchor_atts = [
                'new_tab'  => $rule->new_tab,
                'nofollow' => $rule->nofollow,
            ];

            $count = 0;
            $max = max(1, (int) $rule->max_per_post);
            $content = self::replace_outside_tags($content, $pattern, function ($matchText) use ($rule, $anchor_atts) {
                return Slk_Link::build_anchor($matchText, $rule->url, $anchor_atts);
            }, $max, $count);
        }

        return $content;
    }

    /**
     * Replace up to $max matches of $pattern in text segments that are not
     * inside HTML tags or existing anchors.
     */
    protected static function replace_outside_tags($content, $pattern, $callback, $max, &$count)
    {
        $parts = preg_split('/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $inside_anchor = false;
        $count = 0;

        foreach ($parts as $i => $part) {
            if ($part === '' || $count >= $max) {
                continue;
            }
            if ($part[0] === '<') {
                if (preg_match('/^<a[\s>]/i', $part)) {
                    $inside_anchor = true;
                } elseif (preg_match('#^</a\s*>#i', $part)) {
                    $inside_anchor = false;
                }
                continue;
            }
            if ($inside_anchor) {
                continue;
            }
            $parts[$i] = preg_replace_callback($pattern, function ($m) use ($callback, &$count, $max) {
                if ($count >= $max) {
                    return $m[0];
                }
                $count++;
                return $callback($m[1]);
            }, $part);
        }

        return implode('', $parts);
    }

    /** Drop the cached counts so the next page view recomputes them. */
    public static function flush_counts()
    {
        delete_transient(self::COUNT_TRANSIENT);
    }

    public static function render_page()
    {
        $rules = self::all(false);
        $counts = self::link_counts();
        include SLK_PLUGIN_DIR . 'templates/autolinks.php';
    }
}
