<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin settings: storage, defaults, and the tabbed settings screen.
 *
 * Each tab submits only its own fields, so saving is a merge over the stored
 * option rather than a replace — otherwise saving one tab would wipe another.
 */
class Slk_Settings
{
    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_save']);
    }

    /**
     * Default settings values.
     */
    public static function defaults()
    {
        return [
            // General.
            'suggestion_limit'       => 20,
            'min_keyword_length'     => 3,
            'links_open_new_tab'     => 0,
            'links_nofollow'         => 0,
            'track_clicks'           => 1,
            'autolink_enabled'       => 1,
            'use_stemming'           => 1,
            'link_taxonomies'        => 1,
            // Activity log: how many undoable changes to keep. Each entry holds a
            // full copy of the post before the change, so this is a disk trade,
            // not a time limit — see Slk_Activity.
            'activity_keep'          => 300,
            // Click log retention, in days. Unlike the activity log this is a
            // time limit, because clicks arrive from visitors rather than from
            // deliberate actions — the volume is set by your traffic, not by
            // anything you do. 0 keeps everything, which is a choice rather
            // than the default: see Slk_ClickTracker.
            'clicks_keep_days'       => 365,
            // Learning from rejections: how many DISTINCT posts must turn the
            // same thing down before it is retired site-wide. 0 switches
            // site-wide learning off — see Slk_Rejection.
            'reject_pair_threshold'   => 2,
            'reject_anchor_threshold' => 3,
            // How much a starved target may be promoted in the suggestion
            // order. Ordering only — never the displayed confidence.
            'equity_boost'            => 0.15,
            // Off, and it stays off unless explicitly turned on. Deleting a
            // plugin to reinstall it must never destroy the link index.
            'delete_data_on_uninstall' => 0,
            // How much two posts must overlap on distinctive vocabulary before
            // one may be suggested as a link target for the other.
            // A low safety net, not the main filter: on a small site IDF is
            // too weak for a threshold here to separate topics reliably, so
            // anchor quality does the real work (see Slk_Suggestion::for_post).
            'min_relatedness'        => 0.10,
            'suggestion_min_score'   => 1.2,
            'min_anchor_idf'         => 0.5,
            // A one-word anchor used by more than this share of posts identifies
            // nothing on this site, so it is not offered as an anchor.
            'max_anchor_doc_share'   => 0.5,
            // Focus keywords: read live from the SEO plugin, and which source
            // wins when both have one. 'seo_plugin' or 'smartlinker'.
            'use_seo_plugin_keywords' => 1,
            'focus_keyword_source'    => 'seo_plugin',
            // Composite confidence below which a suggestion is not shown.
            'min_confidence'         => 0.30,
            // Cosine similarity treated as "clearly the same topic" (scales the score).
            'good_similarity'        => 0.12,
            // Content ignoring.
            'ignore_words'           => 'the, a, an, and, or, but, of, to, in, for, on, with, is, are, this, that',
            'excluded_post_ids'      => '',
            'excluded_terms'         => '',
            // AI.
            'use_ai'                 => 0,
            'openai_api_key'         => '',
            'openai_model'           => 'gpt-4o-mini',
            'ai_min_match'           => 50,
            'ai_only_top'            => 0,
            'ai_top_n'               => 5,
            'ai_prefer_title_anchor' => 0,
            'ai_max_age'             => 0,
            'ai_cache'               => 1,
            'ai_timeout'             => 45,
            // Semantic index (embeddings) — the similarity signal for suggestions.
            'use_embeddings'         => 1,
            'embedding_model'        => 'text-embedding-3-small',
            // Scheduled scans and the email digest.
            'digest_enabled'          => 0,
            'digest_frequency'        => 'weekly',
            'digest_day'              => 1,
            'digest_recipients'       => '',
            'digest_only_changes'     => 1,
            'digest_scan_broken'      => 1,
            'digest_scan_opportunities' => 1,
        ];
    }

    /**
     * Which settings belong to which tab, and which of those are checkboxes
     * (checkboxes are absent from POST when unticked, so they need listing).
     */
    public static function schema()
    {
        return [
            'general' => [
                'fields' => ['suggestion_limit', 'min_keyword_length', 'links_open_new_tab', 'links_nofollow',
                             'track_clicks', 'autolink_enabled', 'use_stemming', 'link_taxonomies',
                             'activity_keep', 'clicks_keep_days', 'reject_pair_threshold', 'reject_anchor_threshold',
                             'equity_boost', 'delete_data_on_uninstall'],
                'checkboxes' => ['links_open_new_tab', 'links_nofollow', 'track_clicks', 'autolink_enabled',
                                 'use_stemming', 'link_taxonomies', 'delete_data_on_uninstall'],
            ],
            'ignoring' => [
                'fields' => ['ignore_words', 'excluded_post_ids', 'excluded_terms'],
                'checkboxes' => [],
            ],
            'digest' => [
                'fields' => ['digest_enabled', 'digest_frequency', 'digest_day', 'digest_recipients',
                             'digest_only_changes', 'digest_scan_broken', 'digest_scan_opportunities'],
                'checkboxes' => ['digest_enabled', 'digest_only_changes', 'digest_scan_broken',
                                 'digest_scan_opportunities'],
            ],
            'ai' => [
                'fields' => ['use_ai', 'openai_api_key', 'openai_model', 'ai_min_match', 'ai_only_top',
                             'ai_top_n', 'ai_prefer_title_anchor', 'ai_max_age', 'ai_cache', 'ai_timeout',
                             'use_embeddings', 'embedding_model'],
                'checkboxes' => ['use_ai', 'ai_only_top', 'ai_prefer_title_anchor', 'ai_cache', 'use_embeddings'],
            ],
        ];
    }

    public static function tabs()
    {
        return [
            'general'  => __('General Settings', 'smartlinker'),
            'ignoring' => __('Content Ignoring', 'smartlinker'),
            'digest'   => __('Scheduled Scans', 'smartlinker'),
            'ai'       => __('AI Settings', 'smartlinker'),
        ];
    }

    /**
     * Get a single setting value, falling back to the default.
     */
    public static function get($key, $fallback = null)
    {
        $settings = get_option(SLK_OPTION_SETTINGS, []);
        $defaults = self::defaults();
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        if (array_key_exists($key, $defaults)) {
            return $defaults[$key];
        }
        return $fallback;
    }

    /**
     * Post types SmartLinker operates on.
     */
    public static function enabled_post_types()
    {
        $types = get_option(SLK_OPTION_POST_TYPES, ['post', 'page']);
        return is_array($types) ? $types : ['post', 'page'];
    }

    /**
     * Function words that are never useful as, or inside, a link anchor.
     *
     * Built into the code rather than left to the setting so that existing
     * installs benefit without re-saving settings, and so anchors like
     * "our guide" or "before you" can't be produced on a small site where
     * rarity scoring has too little data to reject them.
     */
    public static function base_stop_words()
    {
        return ['the','a','an','and','or','but','of','to','in','for','on','with','is','are','was','were',
            'be','been','being','this','that','these','those','it','its','as','at','by','from','into',
            'out','up','down','over','under','above','below','than','then','there','here','when','where',
            'why','how','what','which','who','whom','all','any','some','each','every','both','few','more',
            'most','other','such','no','nor','not','only','own','same','so','too','very','can','will',
            'just','should','now','i','me','my','we','us','our','ours','you','your','yours','he','him',
            'his','she','her','hers','they','them','their','theirs','do','does','did','doing','have',
            'has','had','having','would','could','shall','may','might','must','if','because','while',
            'about','after','before','again','further','once','get','got','make','makes','made','need',
            'needs','want','wants','use','uses','used','using','also','well','back','even','still','way'];
    }

    /**
     * Words to ignore when generating suggestions, as a lowercase lookup array.
     * The user's list adds to the built-in one; it never replaces it.
     */
    public static function ignore_words()
    {
        $raw = (string) self::get('ignore_words', '');
        $words = array_filter(array_map('trim', explode(',', strtolower($raw))));
        return array_flip(array_unique(array_merge(self::base_stop_words(), $words)));
    }

    /**
     * Sanitize one field by key.
     */
    protected static function sanitize_field($key, $value)
    {
        switch ($key) {
            case 'suggestion_limit':
                return max(1, min(100, (int) $value));
            case 'min_keyword_length':
                return max(2, min(20, (int) $value));
            case 'digest_frequency':
                return in_array($value, ['daily', 'weekly'], true) ? $value : 'weekly';
            case 'digest_day':
                return max(0, min(6, (int) $value));
            case 'digest_recipients':
                // Comma-separated; anything that is not an address is dropped
                // rather than silently mailed into the void.
                $out = [];
                foreach (explode(',', (string) $value) as $addr) {
                    $addr = trim($addr);
                    if ($addr !== '' && is_email($addr)) {
                        $out[] = $addr;
                    }
                }
                return implode(', ', array_unique($out));
            case 'equity_boost':
                // Capped at 0.5: beyond that a starved page could outrank a
                // clearly better match, which is not a trade worth offering.
                return max(0, min(0.5, (float) $value));
            case 'reject_pair_threshold':
            case 'reject_anchor_threshold':
                // 0 means never suppress site-wide. The upper bound only stops
                // a typo turning the feature silently off.
                return max(0, min(50, (int) $value));
            case 'clicks_keep_days':
                // 0 means keep forever. Capped at ten years so a typo cannot
                // silently mean "never prune" when that was not the intent.
                return max(0, min(3650, (int) $value));
            case 'activity_keep':
                // 0 disables the log entirely; nothing is recorded and nothing
                // can be undone from a report.
                return max(0, min(5000, (int) $value));
            case 'ai_min_match':
                return max(0, min(100, (int) $value));
            case 'ai_top_n':
                return max(1, min(50, (int) $value));
            case 'ai_max_age':
                return max(0, (int) $value);
            case 'ai_timeout':
                return max(5, min(180, (int) $value));
            case 'openai_model':
                return sanitize_text_field($value);
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Handle a settings form POST for a single tab.
     */
    public static function handle_save()
    {
        if (empty($_POST['slk_settings_nonce']) || !wp_verify_nonce($_POST['slk_settings_nonce'], 'slk_save_settings')) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = isset($_POST['slk_tab']) ? sanitize_key($_POST['slk_tab']) : 'general';
        $schema = self::schema();
        if (!isset($schema[$tab])) {
            return;
        }

        $in = isset($_POST['slk']) && is_array($_POST['slk']) ? wp_unslash($_POST['slk']) : [];
        $settings = wp_parse_args(get_option(SLK_OPTION_SETTINGS, []), self::defaults());

        foreach ($schema[$tab]['fields'] as $key) {
            $is_checkbox = in_array($key, $schema[$tab]['checkboxes'], true);

            if ($is_checkbox) {
                $settings[$key] = empty($in[$key]) ? 0 : 1;
                continue;
            }

            // The API key is only overwritten when a new value is entered, so
            // it is never echoed back into the page and can't be blanked by accident.
            if ($key === 'openai_api_key') {
                $submitted = trim((string) ($in[$key] ?? ''));
                if ($submitted !== '') {
                    $settings[$key] = sanitize_text_field($submitted);
                }
                continue;
            }

            if (array_key_exists($key, $in)) {
                $settings[$key] = self::sanitize_field($key, $in[$key]);
            }
        }

        update_option(SLK_OPTION_SETTINGS, $settings);

        // Frequency or the on/off switch may have moved, so the cron event has
        // to be re-pinned to match what was just saved.
        if (class_exists('Slk_Schedule')) {
            Slk_Schedule::reschedule();
        }

        // Post types only live on the General tab.
        if ($tab === 'general') {
            $post_types = isset($in['post_types']) && is_array($in['post_types'])
                ? array_map('sanitize_key', $in['post_types'])
                : ['post', 'page'];
            update_option(SLK_OPTION_POST_TYPES, array_values($post_types));
        }

        wp_safe_redirect(admin_url('admin.php?page=smartlinker_settings&tab=' . $tab . '&saved=1'));
        exit;
    }

    /**
     * Render the tabbed settings screen.
     */
    public static function render_page()
    {
        $tabs = self::tabs();
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        if (!isset($tabs[$tab])) {
            $tab = 'general';
        }

        $s = wp_parse_args(get_option(SLK_OPTION_SETTINGS, []), self::defaults());
        $enabled_types = self::enabled_post_types();
        $public_types = get_post_types(['public' => true], 'objects');

        include SLK_PLUGIN_DIR . 'templates/settings.php';
    }
}
