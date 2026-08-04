<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tracks clicks on internal links. A tiny frontend script pings an AJAX
 * endpoint when a data-slk link is clicked; we log it for reporting.
 *
 * This is the ONLY table in the plugin written by people who are not logged
 * in, which makes it the only one whose size is set by your traffic rather
 * than by anything you do. Two consequences it did not originally handle:
 *
 *   IT MUST BE PRUNED. Everything else here either prunes (the activity log,
 *   trend history) or is bounded by how much content you have. A click row per
 *   click, kept forever, is the one table that can quietly reach hundreds of
 *   megabytes on a site that is merely doing well.
 *
 *   IT MUST BE THROTTLED. The nonce is close to meaningless here: for logged
 *   out visitors WordPress has no session to tie it to, so every visitor gets
 *   the same value and it stays valid for a day. Anyone who views the page
 *   source can replay it in a loop. The throttle below is not security — it
 *   is a ceiling on how fast one client can grow the table.
 */
class Slk_ClickTracker
{
    /** Clicks accepted from one client per THROTTLE_WINDOW. */
    const THROTTLE_MAX = 30;

    /** Length of the throttle window, in seconds. */
    const THROTTLE_WINDOW = 60;

    /** Rows deleted per prune pass, so a huge backlog cannot stall a request. */
    const PRUNE_BATCH = 2000;

    const PRUNE_EVENT = 'slk_prune_clicks';

    public function register()
    {
        // The prune event is registered even when tracking is switched off:
        // turning tracking off should let existing rows age out, not freeze
        // them in the database forever.
        add_action(self::PRUNE_EVENT, [__CLASS__, 'prune']);
        add_action('admin_init', [__CLASS__, 'ensure_scheduled'], 20);

        if (!Slk_Settings::get('track_clicks')) {
            return;
        }
        add_action('wp_enqueue_scripts', [__CLASS__, 'frontend_script']);
        add_action('wp_ajax_slk_track_click', [__CLASS__, 'ajax_track']);
        add_action('wp_ajax_nopriv_slk_track_click', [__CLASS__, 'ajax_track']);
    }

    public static function ensure_scheduled()
    {
        if (!wp_next_scheduled(self::PRUNE_EVENT)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_EVENT);
        }
    }

    /* ---------------------------------------------------------------------
     * Retention
     * ------------------------------------------------------------------ */

    /** @return int days to keep, or 0 for "keep everything" */
    public static function keep_days()
    {
        return max(0, min(3650, (int) Slk_Settings::get('clicks_keep_days', 365)));
    }

    /**
     * Delete clicks older than the retention window.
     *
     * Deleted in batches rather than one statement: on a table that has been
     * accumulating for years the first prune could touch millions of rows, and
     * a single DELETE that size can lock the table long enough to take the
     * site down. The daily event picks up whatever is left.
     *
     * @return int rows deleted
     */
    public static function prune()
    {
        $days = self::keep_days();
        if ($days === 0) {
            return 0;   // explicitly configured to keep everything
        }

        global $wpdb;
        $table = Slk_Query::clicks_table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));

        // Ids first, then delete by id.
        //
        // The obvious "DELETE ... WHERE clicked_at < %s LIMIT %d" is a MySQL
        // extension. SQLite rejects it unless compiled with a non-default
        // flag, and WordPress ships an official SQLite integration — so that
        // version works on most installs and fails silently on the rest,
        // leaving the table growing exactly where nobody is looking. Two
        // portable queries are worth more than one clever one.
        // phpcs:ignore WordPress.DB.PreparedSQL
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE clicked_at < %s ORDER BY id ASC LIMIT %d",
            $cutoff,
            self::PRUNE_BATCH
        ));

        if (empty($ids)) {
            return 0;
        }

        $ph = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE id IN ($ph)",
            array_map('intval', $ids)
        ));
    }

    /* ---------------------------------------------------------------------
     * Throttle
     * ------------------------------------------------------------------ */

    /**
     * A key identifying the calling client, for rate limiting only.
     *
     * The IP is HASHED with the site's own salt and never stored — it exists
     * for the lifetime of a transient and cannot be read back out. Logging
     * visitor IPs would turn a link-click counter into something with data
     * protection obligations, for no benefit to the feature.
     */
    protected static function client_key()
    {
        $ip = '';
        foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'] as $header) {
            if (!empty($_SERVER[$header])) {
                // A forwarded-for header may be a list; the first entry is the
                // client. It is spoofable, which is fine — this is a ceiling
                // on volume, not an identity check.
                $ip = trim(explode(',', wp_unslash($_SERVER[$header]))[0]);
                break;
            }
        }
        return 'slk_ct_' . hash('sha256', $ip . '|' . wp_salt('nonce'));
    }

    /**
     * @return bool true when this client has used up its allowance
     */
    protected static function throttled()
    {
        $key = self::client_key();
        $hits = (int) get_transient($key);

        if ($hits >= self::THROTTLE_MAX) {
            return true;
        }

        // The window is not extended on each hit: it starts at the first click
        // and expires, rather than sliding forward and locking out a genuinely
        // active reader indefinitely.
        if ($hits === 0) {
            set_transient($key, 1, self::THROTTLE_WINDOW);
        } else {
            set_transient($key, $hits + 1, self::THROTTLE_WINDOW);
        }
        return false;
    }

    public static function frontend_script()
    {
        if (is_admin()) {
            return;
        }
        wp_register_script('slk-clicks', '', [], SLK_VERSION, true);
        wp_enqueue_script('slk-clicks');

        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('slk_click');
        $post_id = (int) get_the_ID();

        $inline = <<<JS
document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('a[data-slk]') : null;
    if (!a) return;
    try {
        var body = new URLSearchParams();
        body.append('action', 'slk_track_click');
        body.append('nonce', '{$nonce}');
        body.append('post_id', '{$post_id}');
        body.append('url', a.href);
        body.append('anchor', (a.textContent || '').slice(0, 190));
        navigator.sendBeacon ? navigator.sendBeacon('{$ajax}', body) :
            fetch('{$ajax}', { method: 'POST', body: body, keepalive: true });
    } catch (err) {}
}, true);
JS;
        wp_add_inline_script('slk-clicks', $inline);
    }

    public static function ajax_track()
    {
        check_ajax_referer('slk_click', 'nonce');

        // Answer success either way. This endpoint is fire-and-forget from a
        // sendBeacon call that nothing is waiting on, and an error would only
        // tell someone probing it that the ceiling is real.
        if (self::throttled()) {
            wp_send_json_success();
        }

        global $wpdb;

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        if ($url === '') {
            wp_send_json_error();
        }

        list($type, $target_id) = Slk_Link::classify($url);
        if ($type !== 'internal') {
            wp_send_json_success(); // only track internal links
        }

        $wpdb->insert(Slk_Query::clicks_table(), [
            'post_id'        => isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0,
            'target_post_id' => $target_id,
            'url'            => $url,
            'anchor'         => isset($_POST['anchor']) ? sanitize_text_field(wp_unslash($_POST['anchor'])) : '',
            'clicked_at'     => current_time('mysql'),
        ], ['%d', '%d', '%s', '%s', '%s']);

        wp_send_json_success();
    }

}
