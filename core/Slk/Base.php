<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core wiring: admin menu, editor meta box, asset enqueue, AJAX routing.
 */
class Slk_Base
{
    public function register()
    {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_head', [__CLASS__, 'hide_submenu_css']);
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('enqueue_block_editor_assets', [__CLASS__, 'editor_sidebar_assets']);
        add_filter('plugin_action_links_' . SLK_PLUGIN_BASENAME, [__CLASS__, 'settings_link']);

        // AJAX endpoints.
        add_action('wp_ajax_slk_get_suggestions', ['Slk_Suggestion', 'ajax_get_suggestions']);
        add_action('wp_ajax_slk_insert_link', ['Slk_Suggestion', 'ajax_insert_link']);
        add_action('wp_ajax_slk_get_inbound', ['Slk_Suggestion', 'ajax_get_inbound']);
        add_action('wp_ajax_slk_find_keyword_opportunities', ['Slk_TargetKeyword', 'ajax_find_opportunities']);
        add_action('wp_ajax_slk_get_ai_suggestions', ['Slk_AI', 'ajax_get_ai_suggestions']);
        add_action('wp_ajax_slk_url_preview', ['Slk_URLChanger', 'ajax_preview']);
        add_action('wp_ajax_slk_post_links', ['Slk_Report', 'ajax_post_links']);
        add_action('wp_ajax_slk_reject_suggestion', ['Slk_Suggestion', 'ajax_reject']);
        add_action('wp_ajax_slk_section', [__CLASS__, 'ajax_section']);
        add_action('wp_ajax_slk_post_keywords', ['Slk_TargetKeyword', 'ajax_keywords']);
        add_action('wp_ajax_slk_extract_keywords', ['Slk_TargetKeyword', 'ajax_extract']);
        add_action('wp_ajax_slk_fix_options', ['Slk_Error', 'ajax_fix_options']);
        add_action('wp_ajax_slk_apply_fix', ['Slk_Error', 'ajax_apply_fix']);
    }

    public static function deactivate()
    {
        // Drop the scheduled scan. Leaving it behind means WordPress keeps
        // firing a hook nothing listens to until the event is manually cleared.
        // Nothing destructive: no data is touched, and re-activating with the
        // setting still on re-creates the event.
        wp_clear_scheduled_hook(Slk_Schedule::HOOK);
        wp_clear_scheduled_hook(Slk_History::EVENT);
    }

    /**
     * Register the top-level menu and sub pages.
     */
    /**
     * Render one section for the SPA, without the rest of the admin page.
     *
     * Navigating used to fetch the whole of admin.php and throw away all but
     * two elements — around 70KB to use 8KB, and far worse in wall time: that
     * request builds the entire admin menu and runs every other plugin's admin
     * bootstrap, none of which survives the swap. Going through admin-ajax
     * skips all of it.
     *
     * Only plain navigation comes here. Anything carrying a nonce is an action
     * — a scan, an undo, a rebuild — and those keep the full admin path, where
     * their admin_init handlers run exactly as before.
     */
    public static function ajax_section()
    {
        check_ajax_referer('slk_ajax', 'nonce');

        $slug = isset($_POST['slug']) ? sanitize_key(wp_unslash($_POST['slug'])) : '';
        $routes = self::routes();
        if (!isset($routes[$slug])) {
            wp_send_json_error(['message' => __('Unknown section.', 'smartlinker')]);
        }

        list($title, $cap, $renderer) = $routes[$slug];
        if (!current_user_can($cap)) {
            wp_send_json_error(['message' => __('Permission denied.', 'smartlinker')]);
        }

        // Renderers read $_GET directly (tab, view, filter, paged…), so the
        // requested query string is replayed into it. Only the query part of
        // the URL is used; the rest is discarded.
        $query = isset($_POST['query']) ? wp_unslash($_POST['query']) : '';
        $args = [];
        parse_str((string) $query, $args);
        unset($args['_wpnonce'], $args['action'], $args['nonce']);
        $args['page'] = $slug;

        $_GET = $args;
        $_REQUEST = array_merge($_REQUEST, $args);

        ob_start();
        Slk_Admin::render($slug, $renderer);
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html, 'title' => $title]);
    }

    /**
     * Route table: slug => [page title, capability, content renderer].
     *
     * Extracted from add_menu() so the section endpoint can resolve and
     * capability-check a page without WordPress having built the admin menu.
     */
    public static function routes()
    {
        return [
            'smartlinker'                 => [__('SmartLinker Dashboard', 'smartlinker'), 'edit_posts',        ['Slk_Dashboard', 'render_page']],
            'smartlinker_reports'         => [__('Reports', 'smartlinker'), 'edit_posts',                      ['Slk_Reports', 'render_page']],
            'smartlinker_clusters'        => [__('Topic Clusters', 'smartlinker'), 'edit_posts',              ['Slk_Cluster', 'render_page']],
            'smartlinker_opportunities'   => [__('Link Opportunities', 'smartlinker'), 'edit_posts',          ['Slk_Opportunity', 'render_page']],
            'smartlinker_orphans'         => [__('Orphaned Posts', 'smartlinker'), 'edit_posts',              ['Slk_Report', 'render_orphans_page']],
            'smartlinker_inbound'         => [__('Add Inbound Internal Links', 'smartlinker'), 'edit_posts',   ['Slk_Suggestion', 'render_inbound_page']],
            'smartlinker_ai'              => [__('AI Suggestions', 'smartlinker'), 'edit_posts',               ['Slk_AI', 'render_page']],
            'smartlinker_money_pages'     => [__('Money Pages', 'smartlinker'), 'manage_categories',           ['Slk_MoneyPage', 'render_page']],
            'smartlinker_target_keywords' => [__('Target Keywords', 'smartlinker'), 'manage_categories',       ['Slk_TargetKeyword', 'render_page']],
            'smartlinker_autolinks'       => [__('Auto-Linking', 'smartlinker'), 'manage_categories',          ['Slk_Keyword', 'render_page']],
            'smartlinker_link_map'        => [__('Bulk Link Map', 'smartlinker'), 'edit_others_posts',         ['Slk_LinkMap', 'render_page']],
            'smartlinker_url_changer'     => [__('URL Changer', 'smartlinker'), 'manage_categories',           ['Slk_URLChanger', 'render_page']],
            'smartlinker_broken'          => [__('Broken Links', 'smartlinker'), 'edit_posts',                 ['Slk_Reports', 'render_page']],
            'smartlinker_clicks'          => [__('Link Clicks', 'smartlinker'), 'edit_posts',                  ['Slk_Reports', 'render_page']],
            'smartlinker_domains'         => [__('Domain Report', 'smartlinker'), 'edit_posts',                ['Slk_Reports', 'render_page']],
            'smartlinker_search_console'  => [__('Search Console', 'smartlinker'), 'edit_posts',               ['Slk_SearchConsole', 'render_page']],
            'smartlinker_external'        => [__('External Sites', 'smartlinker'), 'manage_categories',        ['Slk_Sitemap', 'render_page']],
            'smartlinker_placement'       => [__('Link Placement', 'smartlinker'), 'edit_posts',                ['Slk_Reports', 'render_page']],
            'smartlinker_cannibal'        => [__('Cannibalisation', 'smartlinker'), 'edit_posts',               ['Slk_Reports', 'render_page']],
            'smartlinker_equity'          => [__('Link Equity', 'smartlinker'), 'edit_posts',                  ['Slk_Reports', 'render_page']],
            'smartlinker_diagnose'        => [__('Why not?', 'smartlinker'), 'edit_posts',                     ['Slk_Diagnose', 'render_page']],
            'smartlinker_anchors'         => [__('Anchor Text', 'smartlinker'), 'edit_posts',                  ['Slk_Reports', 'render_page']],
            'smartlinker_activity'        => [__('Activity', 'smartlinker'), 'edit_posts',                     ['Slk_Activity', 'render_page']],
            'smartlinker_settings'        => [__('Settings', 'smartlinker'), 'manage_options',                 ['Slk_Settings', 'render_page']],
        ];
    }

    public static function add_menu()
    {
        $routes = self::routes();

        add_menu_page(
            __('SmartLinker', 'smartlinker'),
            __('SmartLinker', 'smartlinker'),
            'edit_posts',
            'smartlinker',
            function () use ($routes) {
                Slk_Admin::render('smartlinker', $routes['smartlinker'][2]);
            },
            'dashicons-admin-links',
            58
        );

        // Register each section as a page (so routing/redirects keep working),
        // each wrapped in the unified shell. The 'smartlinker' slug is already
        // registered by add_menu_page above — skip it to avoid a double render.
        foreach ($routes as $slug => $route) {
            if ($slug === 'smartlinker') {
                continue;
            }
            list($title, $cap, $renderer) = $route;
            add_submenu_page(
                'smartlinker',
                $title,
                $title,
                $cap,
                $slug,
                function () use ($slug, $renderer) {
                    Slk_Admin::render($slug, $renderer);
                }
            );
        }

        // Navigation lives in the in-page sidebar. We keep the submenu pages
        // registered (so access stays authorized) but hide the native WP
        // submenu with CSS — see hide_submenu_css().
    }

    /**
     * Hide SmartLinker's native WP admin submenu; the in-page sidebar replaces it.
     */
    public static function hide_submenu_css()
    {
        echo '<style>#toplevel_page_smartlinker .wp-submenu{display:none!important;}</style>';
    }

    /**
     * Add the suggestions meta box to enabled post types.
     */
    public static function add_meta_boxes()
    {
        foreach (Slk_Settings::enabled_post_types() as $type) {
            add_meta_box(
                'slk_suggestions',
                __('SmartLinker — Internal Link Suggestions', 'smartlinker'),
                ['Slk_Suggestion', 'render_meta_box'],
                $type,
                'normal',
                'default'
            );
        }
    }

    /**
     * Enqueue admin CSS/JS on our own pages and the post editor.
     */
    public static function admin_assets($hook)
    {
        $is_editor = in_array($hook, ['post.php', 'post-new.php'], true);
        $is_our_page = isset($_GET['page']) && strpos((string) $_GET['page'], 'smartlinker') === 0;

        if (!$is_editor && !$is_our_page) {
            return;
        }

        // The font is fetched from a third party, which costs a DNS lookup and
        // a TLS handshake before the first byte — on a cold visit that is
        // usually more than the download itself. These hints start both while
        // the HTML is still parsing.
        add_filter('wp_resource_hints', function ($urls, $relation) {
            if ($relation === 'preconnect') {
                $urls[] = ['href' => 'https://fonts.googleapis.com'];
                $urls[] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => 'anonymous'];
            }
            return $urls;
        }, 10, 2);

        // Work Sans throughout — plugin pages and editor panels alike. Inter
        // was dropped rather than left loading: it is no longer referenced by
        // any rule, so requesting it would cost a download for nothing.
        wp_enqueue_style(
            'slk-font',
            'https://fonts.googleapis.com/css2?family=Work+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap',
            [],
            null
        );
        wp_enqueue_style('slk-admin', SLK_PLUGIN_URL . self::asset('css/admin.css'), [], SLK_VERSION);
        /*
         * admin-ui.js, NOT admin.js — the name matters.
         *
         * WordPress finds a script's translations by hashing its path, and
         * strips a ".min.js" suffix first so translations key off the
         * unminified name. Core checks that suffix strictly (str_ends_with
         * '.min.js', dot included). `wp i18n make-json` checks it loosely, on
         * "min.js" alone — so for a file called admin.js it strips seven
         * characters from "...dmin.js" and writes the JSON under a hash for
         * "js/a.js". Core then looks up the hash for "js/admin.js", finds
         * nothing, and every translated string in the file silently stays
         * English. Any file ending in the letters "min.js" hits this.
         */
        wp_enqueue_script('slk-admin', SLK_PLUGIN_URL . self::asset('js/admin-ui.js'), ['jquery', 'wp-i18n'], SLK_VERSION, true);
        wp_localize_script('slk-admin', 'SLK', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('slk_ajax'),
            'linkDefaults' => [
                'new_tab'  => (int) Slk_Settings::get('links_open_new_tab') ? 1 : 0,
                'nofollow' => (int) Slk_Settings::get('links_nofollow') ? 1 : 0,
            ],
            'ai'      => Slk_AI::availability(),
            'settingsUrl' => admin_url('admin.php?page=smartlinker_settings&tab=ai'),
            'i18n'    => [
                'loading'  => __('Finding suggestions…', 'smartlinker'),
                'inserted' => __('Link inserted.', 'smartlinker'),
                'error'    => __('Something went wrong.', 'smartlinker'),
                'none'     => __('No suggestions found for this content yet.', 'smartlinker'),
            ],
        ]);

        // Lets wp.i18n.__() in the JS resolve against the same text domain as
        // the PHP. Without it those calls silently return their English
        // argument, which looks like a working translation right up until
        // someone reads the screen in another language.
        self::set_script_translations('slk-admin');
    }

    /**
     * Point a script's wp.i18n calls at the plugin's own translations.
     *
     * Guarded because the function arrived in WordPress 5.0 and the declared
     * floor is 5.8 — cheap insurance against a fatal on an older install that
     * ignores the header.
     */
    protected static function set_script_translations($handle)
    {
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations($handle, 'smartlinker', SLK_PLUGIN_DIR . 'languages');
        }
    }

    /**
     * Pick the minified build of an asset, or the source when debugging.
     *
     * Minifying takes the admin's CSS and JS from 48KB to 27KB gzipped — a
     * 42% saving on what actually crosses the wire, which is the number that
     * matters, since every web server gzips these already. Judging by the raw
     * 203KB would badly overstate the win.
     *
     * SCRIPT_DEBUG serves the sources instead, so a stack trace points at a
     * real line and the code is readable in devtools. The sources are the
     * files you edit; the .min files are build output, regenerated by
     * bin/build-assets.sh.
     *
     * If a .min file is missing the source is used. A plugin that renders
     * unstyled because a build step was skipped is a far worse failure than
     * one that serves 20KB more.
     *
     * NOTE for translations: WordPress strips a ".min.js" suffix before
     * hashing a script path, so js/admin-ui.min.js resolves to the JSON
     * generated for js/admin-ui.js. Minifying does not break the JS
     * translations — but see the naming warning below for the case where it
     * does go wrong.
     */
    public static function asset($relative)
    {
        if (defined('SCRIPT_DEBUG') && SCRIPT_DEBUG) {
            return $relative;
        }
        $min = preg_replace('/\.(js|css)$/', '.min.$1', $relative);
        return is_readable(SLK_PLUGIN_DIR . $min) ? $min : $relative;
    }

    /**
     * The block-editor sidebar panel. Hooked to enqueue_block_editor_assets so
     * the wp-plugins / wp-editor handles are guaranteed to exist — on the
     * classic editor this never runs and the meta box remains the only UI.
     *
     * Depends on slk-admin: the sidebar reuses its anchor-building and
     * boundary-safe replacement logic rather than duplicating it.
     */
    public static function editor_sidebar_assets()
    {
        wp_enqueue_script(
            'slk-editor-sidebar',
            SLK_PLUGIN_URL . self::asset('js/editor-sidebar.js'),
            ['wp-plugins', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-edit-post', 'slk-admin'],
            SLK_VERSION,
            true
        );
        self::set_script_translations('slk-editor-sidebar');
    }

    /**
     * Add a Settings link on the plugins list row.
     */
    public static function settings_link($links)
    {
        $url = admin_url('admin.php?page=smartlinker_settings');
        array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'smartlinker') . '</a>');
        return $links;
    }
}
