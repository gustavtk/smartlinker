<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * First-run checklist.
 *
 * SmartLinker does nothing useful until several things have been done in an
 * order nobody can guess: the links have to be indexed before any report has
 * data, focus keywords have to come from somewhere before anchors are any
 * good, and the site has to be scanned before there is a worklist. Someone who
 * built the plugin knows that sequence. Everyone else lands on a dashboard of
 * empty reports and concludes it is broken.
 *
 * Each step reports whether it is already satisfied by looking at the actual
 * state of the site, so nothing has to be ticked off by hand and the list
 * cannot claim something is done when it is not. Steps that cost money are
 * marked optional and never block completion.
 *
 * The panel disappears once everything required is done. A permanent checklist
 * is just clutter, and one that has to be dismissed to stop nagging teaches
 * people to dismiss things.
 */
class Slk_Setup
{
    const OPTION_DISMISSED = 'slk_setup_dismissed';

    /**
     * @return array list of ['id','title','why','done','optional','action','action_label','detail']
     */
    public static function steps()
    {
        global $wpdb;

        $indexed = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Slk_Query::links_table());
        $sources = Slk_KeywordImport::sources();
        $keywords = Slk_Suggestion::focus_keywords();
        $vectors = Slk_Embedding::is_enabled() ? count(Slk_Embedding::all()) : 0;
        $opps = Slk_Opportunity::all('standard');

        $seo_names = implode(', ', array_column($sources, 'label'));

        return [
            [
                'id'      => 'index',
                'title'   => __('Index the links you already have', 'smartlinker'),
                'why'     => __('Every report reads from this index. Until it is built, the reports are empty because nothing has been looked at yet — not because there is nothing to find.', 'smartlinker'),
                'done'    => $indexed > 0,
                'optional' => false,
                'detail'  => $indexed > 0
                    ? sprintf(
                        /* translators: %s: number of links */
                        _n('%s link indexed.', '%s links indexed.', $indexed, 'smartlinker'),
                        number_format_i18n($indexed)
                    )
                    : __('Nothing indexed yet.', 'smartlinker'),
                'action'  => wp_nonce_url(Slk_Reports::url('overview', ['slk_rescan' => 1]), 'slk_rescan'),
                'action_label' => __('Scan my links', 'smartlinker'),
            ],
            [
                'id'      => 'keywords',
                'title'   => __('Decide where focus keywords come from', 'smartlinker'),
                'why'     => __('Anchor text is chosen from what a page is actually about. A focus keyword is the strongest signal available, and without one SmartLinker falls back to the title, which is weaker.', 'smartlinker'),
                'done'    => !empty($keywords),
                'optional' => false,
                'detail'  => !empty($keywords)
                    ? sprintf(
                        /* translators: 1: number of posts, 2: detected SEO plugins */
                        __('%1$s posts have a focus keyword%2$s.', 'smartlinker'),
                        number_format_i18n(count($keywords)),
                        /* translators: %s: name of the SEO plugin keywords are read from */
                        $seo_names !== '' ? ' — ' . sprintf(__('read live from %s', 'smartlinker'), $seo_names) : ''
                    )
                    : ($seo_names !== ''
                        ? sprintf(
                            /* translators: %s: detected SEO plugin names */
                            __('%s found, but no keywords are set yet.', 'smartlinker'),
                            $seo_names
                        )
                        : __('No SEO plugin detected. You can set keywords yourself under Target Keywords.', 'smartlinker')),
                'action'  => admin_url('admin.php?page=smartlinker_target_keywords'),
                'action_label' => $seo_names !== ''
                    ? __('Import from my SEO plugin', 'smartlinker')
                    : __('Set target keywords', 'smartlinker'),
            ],
            [
                'id'      => 'scan',
                'title'   => __('Find your first linking opportunities', 'smartlinker'),
                'why'     => __('This is the payoff: every place on the site where one post already says something that names another post, ready to apply in one click.', 'smartlinker'),
                'done'    => !empty($opps['scanned']),
                'optional' => false,
                'detail'  => !empty($opps['scanned'])
                    ? sprintf(
                        /* translators: 1: opportunities found, 2: posts scanned */
                        __('%1$s opportunities across %2$s posts.', 'smartlinker'),
                        number_format_i18n(count($opps['rows'])),
                        number_format_i18n($opps['scanned'])
                    )
                    : __('Not scanned yet.', 'smartlinker'),
                'action'  => admin_url('admin.php?page=smartlinker_opportunities'),
                'action_label' => __('Scan the site', 'smartlinker'),
            ],
            [
                'id'      => 'semantic',
                'title'   => __('Build the semantic index', 'smartlinker'),
                'why'     => __('Lets SmartLinker match posts that mean the same thing in different words, and unlocks the cannibalisation report. It calls OpenAI and is billed to your own account, which is why it is optional — everything else works without it.', 'smartlinker'),
                'done'    => $vectors > 0,
                'optional' => true,
                'detail'  => $vectors > 0
                    ? sprintf(
                        /* translators: %s: number of posts */
                        __('%s posts in the semantic index.', 'smartlinker'),
                        number_format_i18n($vectors)
                    )
                    : __('Not built. Needs an OpenAI API key.', 'smartlinker'),
                'action'  => admin_url('admin.php?page=smartlinker_settings&tab=ai'),
                'action_label' => __('Set up AI', 'smartlinker'),
            ],
        ];
    }

    /** Required steps only — an optional step never keeps the panel on screen. */
    public static function is_complete(?array $steps = null)
    {
        $steps = $steps === null ? self::steps() : $steps;
        foreach ($steps as $s) {
            if (empty($s['optional']) && empty($s['done'])) {
                return false;
            }
        }
        return true;
    }

    public static function dismissed()
    {
        return (int) get_option(self::OPTION_DISMISSED, 0) === 1;
    }

    /**
     * Should the panel be shown at all?
     *
     * Hidden once the required steps are done, without anyone having to close
     * it. Dismissing is there for the person who genuinely does not want the
     * remaining step and would otherwise look at an unfinished list forever.
     */
    public static function should_show(?array $steps = null)
    {
        if (self::dismissed()) {
            return false;
        }
        return !self::is_complete($steps);
    }

    public function register()
    {
        add_action('admin_init', [__CLASS__, 'handle_actions']);
    }

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker') {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }
        if (!empty($_GET['slk_setup_dismiss']) && check_admin_referer('slk_setup_dismiss')) {
            update_option(self::OPTION_DISMISSED, 1, false);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker'));
            exit;
        }
        if (!empty($_GET['slk_setup_show']) && check_admin_referer('slk_setup_show')) {
            update_option(self::OPTION_DISMISSED, 0, false);
            wp_safe_redirect(admin_url('admin.php?page=smartlinker'));
            exit;
        }
    }
}
