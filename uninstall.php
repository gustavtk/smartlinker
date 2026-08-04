<?php

/**
 * Runs when SmartLinker is DELETED from the Plugins screen — not on
 * deactivation, and not on an update.
 *
 * Nothing is removed unless you asked for it. The setting that turns this on is
 * off by default and always will be, because the asymmetry is stark: leaving
 * nine tables behind costs a few megabytes and some tidiness, while deleting
 * them by surprise destroys a link index, a rejection history and an undo log
 * that took real work to build. People delete plugins to reinstall them, to
 * move hosts, to test a conflict. Those must all be safe.
 *
 * WordPress does NOT load the plugin before running this file, so nothing here
 * may reference a Slk_ class or an SLK_ constant. Every name is spelled out.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

if (!current_user_can('activate_plugins')) {
    exit;
}

/**
 * Remove every trace of the plugin from one site.
 */
function slk_uninstall_site()
{
    global $wpdb;

    // --- tables -----------------------------------------------------------
    //
    // Listed explicitly rather than matched with a wildcard. Dropping a table
    // cannot be undone, so the one destructive step in this file is the one
    // that gets no pattern matching.
    $tables = [
        'slk_links',
        'slk_autolinks',
        'slk_activity',
        'slk_clicks',
        'slk_url_changes',
        'slk_target_keywords',
        'slk_gsc',
        'slk_external',
        'slk_history',
    ];
    foreach ($tables as $t) {
        $name = $wpdb->prefix . $t;
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '', $name) . '`');
    }

    // --- options and transients -------------------------------------------
    //
    // Prefix-matched, unlike the tables. These are individual rows in a shared
    // table, so a stale one is harmless while a missed one lingers forever —
    // and the plugin has always owned the slk_ prefix. `_` and `%` are escaped
    // so they are matched literally.
    $like = $wpdb->esc_like('slk_') . '%';
    // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $like
    ));

    foreach (['_transient_', '_transient_timeout_', '_site_transient_', '_site_transient_timeout_'] as $prefix) {
        // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix . 'slk_') . '%'
        ));
    }

    // --- post meta --------------------------------------------------------
    //
    // Embeddings especially: one packed vector per post, which on a large site
    // is the single biggest thing left behind.
    // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like('_slk_') . '%'
    ));

    // --- scheduled events -------------------------------------------------
    wp_clear_scheduled_hook('slk_scheduled_scan');
    wp_clear_scheduled_hook('slk_daily_snapshot');
    wp_clear_scheduled_hook('slk_prune_clicks');
}

/**
 * Was cleanup actually asked for on this site?
 */
function slk_uninstall_wanted()
{
    $settings = get_option('slk_settings', []);
    return is_array($settings) && !empty($settings['delete_data_on_uninstall']);
}

if (is_multisite()) {
    // Each site stores its own settings, so each site decides for itself.
    // A network admin removing the plugin must not wipe data on a sub-site
    // whose owner never opted in.
    $sites = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($sites as $site_id) {
        switch_to_blog((int) $site_id);
        if (slk_uninstall_wanted()) {
            slk_uninstall_site();
        }
        restore_current_blog();
    }
} elseif (slk_uninstall_wanted()) {
    slk_uninstall_site();
}
