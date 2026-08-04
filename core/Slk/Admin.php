<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The unified admin shell: a self-contained interface with an in-page sidebar
 * (all sections), a dark header bar (section title + version), and a content
 * panel — so SmartLinker isn't spread across the WordPress admin submenu.
 */
class Slk_Admin
{
    /**
     * Every section shown in the in-page sidebar.
     * [slug, label, dashicon, capability]
     */
    public static function sections()
    {
        return [
            ['smartlinker',                  __('Dashboard', 'smartlinker'),       'dashicons-dashboard',       'edit_posts'],
            ['smartlinker_reports',          __('Reports', 'smartlinker'),         'dashicons-chart-bar',       'edit_posts'],
            ['smartlinker_clusters',         __('Topic Clusters', 'smartlinker'),  'dashicons-networking',      'edit_posts'],
            ['smartlinker_opportunities',    __('Link Opportunities', 'smartlinker'), 'dashicons-admin-links',  'edit_posts'],
            ['smartlinker_orphans',          __('Orphaned Posts', 'smartlinker'),  'dashicons-editor-unlink',   'edit_posts'],
            ['smartlinker_inbound',          __('Inbound Links', 'smartlinker'),   'dashicons-migrate',         'edit_posts'],
            ['smartlinker_ai',               __('AI Suggestions', 'smartlinker'),  'dashicons-superhero',       'edit_posts'],
            ['smartlinker_diagnose',         __('Why not?', 'smartlinker'),        'dashicons-search',          'edit_posts'],
            ['smartlinker_money_pages',      __('Money Pages', 'smartlinker'),     'dashicons-star-filled',     'manage_categories'],
            ['smartlinker_target_keywords',  __('Target Keywords', 'smartlinker'), 'dashicons-tag',             'manage_categories'],
            ['smartlinker_autolinks',        __('Auto-Linking', 'smartlinker'),    'dashicons-admin-links',     'manage_categories'],
            ['smartlinker_link_map',         __('Link Map', 'smartlinker'),        'dashicons-networking',      'edit_others_posts'],
            ['smartlinker_url_changer',      __('URL Changer', 'smartlinker'),     'dashicons-randomize',       'manage_categories'],
            ['smartlinker_search_console',   __('Search Console', 'smartlinker'),  'dashicons-search',          'edit_posts'],
            ['smartlinker_external',         __('External Sites', 'smartlinker'),  'dashicons-admin-site-alt3', 'manage_categories'],
            ['smartlinker_activity',         __('Activity', 'smartlinker'),        'dashicons-backup',          'edit_posts'],
            ['smartlinker_settings',         __('Settings', 'smartlinker'),        'dashicons-admin-generic',   'manage_options'],
        ];
    }

    protected static function meta($slug)
    {
        foreach (self::sections() as $s) {
            if ($s[0] === $slug) {
                return $s;
            }
        }
        return ['smartlinker', 'SmartLinker', 'dashicons-admin-links', 'edit_posts'];
    }

    /**
     * Render a full page: shell + the section's content callback.
     */
    public static function render($slug, $renderer)
    {
        self::open($slug);
        call_user_func($renderer);
        self::close();
    }

    protected static function open($active)
    {
        // The five report slugs all live under one nav item now. Without this
        // an old bookmark to ?page=smartlinker_broken would highlight nothing
        // and title the page "SmartLinker".
        if (class_exists('Slk_Reports') && in_array($active, Slk_Reports::slugs(), true)) {
            $active = Slk_Reports::PAGE;
        }

        list($aslug, $alabel, $aicon) = self::meta($active);

        echo '<div class="slk-app">';

        // Sidebar.
        echo '<nav class="slk-nav">';
        echo '<div class="slk-brand">' . self::logo() . '<span>SmartLinker</span></div>';
        foreach (self::sections() as $s) {
            if (!current_user_can($s[3])) {
                continue;
            }
            printf(
                '<a href="%s" class="%s"><span class="dashicons %s"></span>%s</a>',
                esc_url(admin_url('admin.php?page=' . $s[0])),
                $active === $s[0] ? 'active' : '',
                esc_attr($s[2]),
                esc_html($s[1])
            );
        }
        echo '</nav>';

        // Main column: dark header + content panel.
        echo '<div class="slk-main">';
        echo '<div class="slk-topbar">';
        echo '<div class="slk-topbar-title"><span class="dashicons ' . esc_attr($aicon) . '"></span>' . esc_html($alabel) . '</div>';
        echo '<div class="slk-ver">' . esc_html(sprintf(__('Version %s', 'smartlinker'), SLK_VERSION)) . '</div>';
        echo '</div>';
        echo '<div class="slk-panel">';
    }

    protected static function close()
    {
        echo '</div></div></div>'; // .slk-panel .slk-main .slk-app
    }

    /**
     * Inline brand mark (chain link).
     */
    protected static function logo()
    {
        return '<svg class="slk-logo" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
            . '<defs><linearGradient id="slkGrad" x1="0" y1="0" x2="1" y2="1">'
            . '<stop offset="0%" stop-color="#a855f7"/><stop offset="100%" stop-color="#4f7bf7"/>'
            . '</linearGradient></defs>'
            . '<rect width="24" height="24" rx="6" fill="url(#slkGrad)"/>'
            . '<path d="M10 13.5a2.5 2.5 0 0 0 3.5 0l2-2a2.5 2.5 0 0 0-3.5-3.5l-.8.8" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>'
            . '<path d="M14 10.5a2.5 2.5 0 0 0-3.5 0l-2 2a2.5 2.5 0 0 0 3.5 3.5l.8-.8" stroke="#fff" stroke-width="1.6" stroke-linecap="round"/>'
            . '</svg>';
    }

    /**
     * A "?" help bubble that explains a setting or column on hover/focus.
     * Keyboard reachable, and announced to screen readers via aria-label.
     *
     * @param string $tip   The explanation.
     * @param string $align Pass 'left' to left-anchor the bubble near a page edge.
     */
    public static function help($tip, $align = '')
    {
        // Accept a single string or an array of paragraphs.
        $paragraphs = is_array($tip) ? array_values(array_filter($tip)) : [$tip];
        if (empty($paragraphs) || $paragraphs[0] === '') {
            return '';
        }

        $body = '';
        foreach ($paragraphs as $p) {
            $body .= '<span class="slk-help-p">' . esc_html($p) . '</span>';
        }

        $class = 'slk-help' . ($align === 'left' ? ' slk-help-flip' : '');
        return '<span class="' . esc_attr($class) . '" tabindex="0" role="note"'
            . ' aria-label="' . esc_attr(implode(' ', $paragraphs)) . '">?'
            . '<span class="slk-help-bubble">' . $body . '</span></span>';
    }

    /**
     * Helper: render a toggle switch bound to a checkbox input.
     */
    public static function toggle($name, $checked, $value = '1')
    {
        return '<label class="slk-switch"><input type="checkbox" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" '
            . checked($checked, true, false) . ' /><span class="slk-slider"></span></label>';
    }
}
