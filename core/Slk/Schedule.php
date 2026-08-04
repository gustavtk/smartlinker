<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Scheduled scans and the email digest.
 *
 * The reports only tell the truth when someone remembers to press Scan. This
 * runs them on a schedule and mails what changed, so a link that broke in
 * March is not discovered in July.
 *
 * The digest reports CHANGE, not totals. "42 broken links" is noise once you
 * have seen it twice; "3 links broke this week" is the thing worth acting on.
 * Totals are still shown, but the newly-broken list is what leads.
 *
 * Everything here is defensive about time: WP-Cron runs inside a normal page
 * request, so the scan works in batches against a wall-clock budget and picks
 * up where it left off on the next run rather than risking a timeout.
 */
class Slk_Schedule
{
    const HOOK = 'slk_scheduled_scan';
    const SNAPSHOT = 'slk_digest_snapshot';
    const LAST_RUN = 'slk_digest_last_run';

    /** Seconds a scheduled scan may spend before deferring the rest. */
    const BUDGET = 20;

    public function register()
    {
        add_filter('cron_schedules', [__CLASS__, 'add_weekly']);
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        // Catches installs upgraded before this existed, and any case where the
        // event was lost (some hosts clear cron on deploy).
        add_action('admin_init', [__CLASS__, 'ensure_scheduled'], 20);
    }

    /**
     * WordPress ships hourly/twicedaily/daily but not weekly.
     */
    public static function add_weekly($schedules)
    {
        if (!isset($schedules['weekly'])) {
            $schedules['weekly'] = [
                'interval' => WEEK_IN_SECONDS,
                'display'  => __('Once weekly', 'smartlinker'),
            ];
        }
        return $schedules;
    }

    public static function enabled()
    {
        return (int) Slk_Settings::get('digest_enabled') === 1;
    }

    public static function frequency()
    {
        $f = Slk_Settings::get('digest_frequency', 'weekly');
        return in_array($f, ['daily', 'weekly'], true) ? $f : 'weekly';
    }

    /**
     * Who the digest goes to. Falls back to the site admin so enabling the
     * feature without filling anything in still does something sensible.
     */
    public static function recipients()
    {
        $raw = trim((string) Slk_Settings::get('digest_recipients', ''));
        if ($raw === '') {
            return [get_option('admin_email')];
        }
        $out = array_filter(array_map('trim', explode(',', $raw)), 'is_email');
        return $out ? array_values($out) : [get_option('admin_email')];
    }

    public static function next_run()
    {
        $ts = wp_next_scheduled(self::HOOK);
        return $ts ? (int) $ts : 0;
    }

    public static function last_run()
    {
        return (int) get_option(self::LAST_RUN, 0);
    }

    /**
     * Pin the cron event to the current settings.
     */
    public static function reschedule()
    {
        wp_clear_scheduled_hook(self::HOOK);
        if (!self::enabled()) {
            return;
        }
        wp_schedule_event(self::first_timestamp(), self::frequency(), self::HOOK);
    }

    public static function ensure_scheduled()
    {
        if (self::enabled() && !wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(self::first_timestamp(), self::frequency(), self::HOOK);
        } elseif (!self::enabled() && wp_next_scheduled(self::HOOK)) {
            wp_clear_scheduled_hook(self::HOOK);
        }
    }

    /**
     * When the first run should land: early morning site time, so a weekly
     * digest arrives before the working day rather than in the middle of it.
     */
    protected static function first_timestamp()
    {
        $offset = (float) get_option('gmt_offset', 0) * HOUR_IN_SECONDS;
        $local = time() + $offset;

        // Next 06:00 local.
        $target = strtotime('tomorrow 06:00', $local);
        if (self::frequency() === 'weekly') {
            $day = (int) Slk_Settings::get('digest_day', 1);
            $names = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
            $target = strtotime('next ' . $names[$day] . ' 06:00', $local);
        }

        return (int) ($target - $offset);
    }

    /* ---------------------------------------------------------------------
     * The scheduled run.
     * ------------------------------------------------------------------ */

    /**
     * @param bool $force_send send even when nothing changed (used by the test button)
     * @return array the digest payload, so the caller can show it
     */
    public static function run($force_send = false)
    {
        $started = microtime(true);

        if ((int) Slk_Settings::get('digest_scan_broken') === 1) {
            self::scan_links($started);
        }
        if ((int) Slk_Settings::get('digest_scan_opportunities') === 1) {
            self::scan_opportunities($started);
        }

        // Rebuild the equity graph while we are already doing heavy work, so
        // the suggestion engine finds it warm rather than paying for a
        // PageRank pass inside an editor request.
        Slk_Equity::flush();
        Slk_Equity::build();

        // Equity is warm and the summary is about to be computed anyway, so
        // this is the cheapest moment in the week to record where the site
        // stands. The daily event captures on its own too; a same-day capture
        // overwrites rather than duplicating.
        Slk_History::capture();

        $data = self::build_digest();
        update_option(self::LAST_RUN, time(), false);
        update_option(self::SNAPSHOT, $data['snapshot'], false);

        $quiet = (int) Slk_Settings::get('digest_only_changes') === 1;
        if (!$force_send && $quiet && !$data['has_changes']) {
            $data['sent'] = false;
            $data['skipped_reason'] = __('Nothing changed since the last run.', 'smartlinker');
            return $data;
        }

        $data['sent'] = self::send($data);
        return $data;
    }

    /**
     * Re-check links in batches until the time budget runs out.
     */
    protected static function scan_links($started)
    {
        global $wpdb;
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Slk_Query::links_table());
        $offset = 0;
        while ($offset < $total) {
            Slk_Error::scan(Slk_Error::BATCH, $offset);
            $offset += Slk_Error::BATCH;
            if (microtime(true) - $started > self::BUDGET) {
                break;
            }
        }
    }

    protected static function scan_opportunities($started)
    {
        $offset = 0;
        do {
            $out = Slk_Opportunity::scan($offset, 'standard');
            $offset = (int) $out['scanned'];
            if (microtime(true) - $started > self::BUDGET * 2) {
                break;
            }
        } while (empty($out['done']));
    }

    /* ---------------------------------------------------------------------
     * What to say.
     * ------------------------------------------------------------------ */

    /**
     * Editor URL built by hand rather than via get_edit_post_link().
     *
     * The digest is composed under cron, where there is no logged-in user, so
     * get_edit_post_link() fails its capability check and returns null — every
     * link in the email would be dead. The recipient's own session decides
     * access when they click, which is where that check belongs.
     */
    public static function edit_url($post_id)
    {
        return admin_url('post.php?post=' . (int) $post_id . '&action=edit');
    }

    /**
     * Compare the site now against the snapshot taken last run.
     */
    public static function build_digest()
    {
        $stats = Slk_Report::summary();
        $broken_rows = Slk_Error::broken_rows(200, 'all');
        $opps = Slk_Opportunity::all('standard');
        $orphan_rows = Slk_Report::orphan_rows(200);

        // Identify links by URL + post so the comparison survives re-indexing,
        // which reassigns row ids and would otherwise report everything as new.
        $broken_keys = [];
        foreach ($broken_rows as $r) {
            $broken_keys[md5($r->post_id . '|' . $r->url)] = [
                'post_id'  => (int) $r->post_id,
                'title'    => get_the_title($r->post_id),
                'anchor'   => $r->anchor,
                'url'      => $r->url,
                'edit_url' => self::edit_url($r->post_id),
            ];
        }
        $orphan_ids = array_map(function ($r) {
            return (int) $r->ID;
        }, $orphan_rows);

        $prev = get_option(self::SNAPSHOT, []);
        $first_run = empty($prev);

        $prev_broken = isset($prev['broken_keys']) && is_array($prev['broken_keys']) ? $prev['broken_keys'] : [];
        $prev_orphans = isset($prev['orphan_ids']) && is_array($prev['orphan_ids']) ? $prev['orphan_ids'] : [];
        $prev_opps = isset($prev['opportunity_count']) ? (int) $prev['opportunity_count'] : 0;

        // On a first run everything would read as "new", which is a wall of
        // noise. The first digest reports the state of things instead.
        $new_broken = $first_run ? [] : array_values(array_diff_key($broken_keys, array_flip($prev_broken)));
        $fixed_broken = $first_run ? 0 : count(array_diff($prev_broken, array_keys($broken_keys)));
        $new_orphans = $first_run ? [] : array_values(array_diff($orphan_ids, $prev_orphans));

        $opp_count = count($opps['rows']);
        $new_opps = $first_run ? 0 : max(0, $opp_count - $prev_opps);

        $has_changes = (bool) (count($new_broken) || $fixed_broken || count($new_orphans) || $new_opps);

        return [
            'first_run'     => $first_run,
            'has_changes'   => $has_changes,
            'stats'         => $stats,
            'new_broken'    => array_slice($new_broken, 0, 20),
            'new_broken_total' => count($new_broken),
            'fixed_broken'  => $fixed_broken,
            'new_orphans'   => array_slice(array_map(function ($id) {
                return [
                    'id'       => $id,
                    'title'    => get_the_title($id),
                    'url'      => get_permalink($id),
                    'edit_url' => self::edit_url($id),
                ];
            }, $new_orphans), 0, 20),
            'new_orphans_total' => count($new_orphans),
            'opportunity_count' => $opp_count,
            'new_opportunities' => $new_opps,
            'top_opportunities' => array_slice($opps['rows'], 0, 8),
            'generated'     => current_time('mysql'),
            'snapshot'      => [
                'broken_keys'       => array_keys($broken_keys),
                'orphan_ids'        => $orphan_ids,
                'opportunity_count' => $opp_count,
                'taken'             => time(),
            ],
        ];
    }

    /**
     * @return bool whether wp_mail accepted it
     */
    public static function send($data)
    {
        $subject = self::subject($data);

        ob_start();
        include SLK_PLUGIN_DIR . 'templates/email_digest.php';
        $body = ob_get_clean();

        $headers = ['Content-Type: text/html; charset=UTF-8'];
        return (bool) wp_mail(self::recipients(), $subject, $body, $headers);
    }

    protected static function subject($data)
    {
        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $bits = [];
        if (!empty($data['new_broken_total'])) {
            $bits[] = sprintf(
                /* translators: %d: number of links */
                _n('%d link broke', '%d links broke', $data['new_broken_total'], 'smartlinker'),
                $data['new_broken_total']
            );
        }
        if (!empty($data['new_opportunities'])) {
            $bits[] = sprintf(
                /* translators: %d: number of opportunities */
                _n('%d new link opportunity', '%d new link opportunities', $data['new_opportunities'], 'smartlinker'),
                $data['new_opportunities']
            );
        }
        if (!empty($data['new_orphans_total'])) {
            $bits[] = sprintf(
                /* translators: %d: number of posts */
                _n('%d new orphan', '%d new orphans', $data['new_orphans_total'], 'smartlinker'),
                $data['new_orphans_total']
            );
        }

        if (!$bits) {
            return sprintf(__('[%s] Internal links: nothing new', 'smartlinker'), $site);
        }
        return sprintf(__('[%1$s] %2$s', 'smartlinker'), $site, implode(', ', $bits));
    }

    /* ---------------------------------------------------------------------
     * Admin actions.
     * ------------------------------------------------------------------ */

    public static function handle_actions()
    {
        if (empty($_REQUEST['page']) || $_REQUEST['page'] !== 'smartlinker_settings') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!empty($_GET['slk_digest_test']) && check_admin_referer('slk_digest_test')) {
            // Forced, so pressing Test always produces mail to look at even on
            // a quiet site — otherwise you cannot tell "working" from "silent".
            $data = self::run(true);
            $arg = $data['sent'] ? 'digest_sent=1' : 'digest_failed=1';
            wp_safe_redirect(admin_url('admin.php?page=smartlinker_settings&tab=digest&' . $arg));
            exit;
        }
    }
}
