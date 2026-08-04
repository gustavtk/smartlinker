<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One Reports page with tabs, in place of five separate sidebar entries.
 *
 * These five all answer "what does the site look like right now" and were only
 * separate because they were built at different times. Grouping them the way
 * Settings is grouped shortens a sidebar that had grown to eighteen items.
 *
 * The old slugs are deliberately KEPT and still render — they are linked from
 * the dashboard, from inside other reports, and from every digest email
 * already sent. Rewriting all of those would break links that are out in the
 * world, so instead each legacy slug resolves to its tab and renders the same
 * page. There is one canonical URL going forward and no dead ones behind.
 */
class Slk_Reports
{
    const PAGE = 'smartlinker_reports';

    /**
     * tab => [label, legacy slug, renderer]
     *
     * Orphaned Posts is deliberately absent: it edits posts in place, which
     * makes it a worklist like Link Opportunities rather than a report.
     */
    public static function tabs()
    {
        return [
            'overview' => [__('Overview', 'smartlinker'), 'smartlinker_reports', ['Slk_Report', 'render_page']],
            'anchors'  => [__('Anchor Text', 'smartlinker'), 'smartlinker_anchors', ['Slk_Anchor', 'render_page']],
            'broken'   => [__('Broken Links', 'smartlinker'), 'smartlinker_broken', ['Slk_Error', 'render_page']],
            'clicks'   => [__('Link Clicks', 'smartlinker'), 'smartlinker_clicks', ['Slk_Report', 'render_clicks_page']],
            'domains'  => [__('Domains', 'smartlinker'), 'smartlinker_domains', ['Slk_Report', 'render_domains_page']],
            'equity'   => [__('Link Equity', 'smartlinker'), 'smartlinker_equity', ['Slk_Equity', 'render_page']],
            'cannibal' => [__('Cannibalisation', 'smartlinker'), 'smartlinker_cannibal', ['Slk_Cannibal', 'render_page']],
            'placement' => [__('Placement', 'smartlinker'), 'smartlinker_placement', ['Slk_Placement', 'render_page']],
            'trends'   => [__('Trends', 'smartlinker'), 'smartlinker_trends', ['Slk_History', 'render_page']],
        ];
    }

    /** Every page slug that lands on this screen, canonical first. */
    public static function slugs()
    {
        $out = [self::PAGE];
        foreach (self::tabs() as $t) {
            if ($t[1] !== self::PAGE) {
                $out[] = $t[1];
            }
        }
        return $out;
    }

    /**
     * Which tab the current request is asking for.
     *
     * An explicit ?tab= wins; otherwise a legacy slug picks its own tab. That
     * ordering matters — ?page=smartlinker_broken&tab=clicks should honour the
     * tab, because that is what a tab link looks like mid-navigation.
     */
    public static function current_tab()
    {
        $tabs = self::tabs();

        $asked = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($asked !== '' && isset($tabs[$asked])) {
            return $asked;
        }

        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        foreach ($tabs as $key => $t) {
            if ($t[1] === $page && $page !== self::PAGE) {
                return $key;
            }
        }

        return 'overview';
    }

    /**
     * Is the current request on a given report?
     *
     * Handlers used to test the page slug directly. They cannot any more,
     * because the same report is reachable as ?page=smartlinker_broken and as
     * ?page=smartlinker_reports&tab=broken, and a scan button must work from
     * either. This is the one place that knows about both.
     */
    public static function on_tab($tab)
    {
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        if (!in_array($page, self::slugs(), true)) {
            return false;
        }
        return self::current_tab() === $tab;
    }

    /** Canonical URL for a tab, with optional extra query args. */
    public static function url($tab = 'overview', array $args = [])
    {
        $args = array_merge(['page' => self::PAGE, 'tab' => $tab], $args);
        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Render the tab strip, then hand off to the tab's own renderer.
     *
     * Each renderer still emits its own <div class="wrap"> and <h1>, exactly
     * as it did when it was a standalone page. Nothing about those templates
     * changed — this only wraps them.
     */
    public static function render_page()
    {
        $tab = self::current_tab();
        $tabs = self::tabs();
        ?>
        <div class="slk-reports-tabs">
            <div class="slk-tabs slk-tabs-underline">
                <?php foreach ($tabs as $key => $t) : ?>
                    <a href="<?php echo esc_url(self::url($key)); ?>"
                       class="slk-tab<?php echo $tab === $key ? ' active' : ''; ?>"><?php echo esc_html($t[0]); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        call_user_func($tabs[$tab][2]);
    }
}
