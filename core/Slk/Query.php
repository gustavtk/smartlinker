<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database schema installer and low-level table access helpers.
 */
class Slk_Query
{
    /** @return string links table name */
    public static function links_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_links';
    }

    /** @return string autolink rules table name */
    public static function autolinks_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_autolinks';
    }

    /** @return string activity / undo log table name */
    public static function activity_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_activity';
    }

    /** @return string click tracking table name */
    public static function clicks_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_clicks';
    }

    /** @return string URL change / redirect table name */
    public static function url_changes_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_url_changes';
    }

    /** @return string target keywords table name */
    public static function target_keywords_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_target_keywords';
    }

    /** @return string history snapshots table name */
    public static function history_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_history';
    }

    /** @return string Search Console metrics table name */
    public static function gsc_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_gsc';
    }

    /** @return string external-site (sitemap) URLs table name */
    public static function external_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'slk_external';
    }

    /**
     * Create/upgrade the plugin's database tables. Runs on activation.
     */
    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $links = self::links_table();
        $autolinks = self::autolinks_table();
        $clicks = self::clicks_table();
        $url_changes = self::url_changes_table();
        $target_keywords = self::target_keywords_table();
        $gsc = self::gsc_table();
        $activity = self::activity_table();
        $external = self::external_table();
        $history = self::history_table();

        $sql = [];

        // Discovered links (one row per link found in content).
        $sql[] = "CREATE TABLE {$links} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            post_type VARCHAR(64) NOT NULL DEFAULT '',
            url TEXT NOT NULL,
            anchor TEXT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'internal',
            target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            broken TINYINT(1) NOT NULL DEFAULT 0,
            status_code INT NOT NULL DEFAULT 0,
            broken_type VARCHAR(20) NOT NULL DEFAULT '',
            host VARCHAR(191) NOT NULL DEFAULT '',
            created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY target_post_id (target_post_id),
            KEY type (type),
            KEY host (host),
            KEY broken_type (broken_type)
        ) {$charset_collate};";

        // Auto-linking rules.
        $sql[] = "CREATE TABLE {$autolinks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            keyword VARCHAR(255) NOT NULL DEFAULT '',
            url TEXT NOT NULL,
            target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
            partial_match TINYINT(1) NOT NULL DEFAULT 0,
            new_tab TINYINT(1) NOT NULL DEFAULT 0,
            nofollow TINYINT(1) NOT NULL DEFAULT 0,
            max_per_post INT NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY active (active)
        ) {$charset_collate};";

        // Undo log. content_before holds the whole post as it was, which is
        // the only reliably reversible record: replaying an edit backwards
        // fails as soon as the surrounding text has moved on. after_hash is
        // what we wrote, so a revert can refuse when the post has been edited
        // since rather than silently discarding that work.
        $sql[] = "CREATE TABLE {$activity} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            action VARCHAR(20) NOT NULL DEFAULT '',
            summary TEXT NULL,
            target_url TEXT NULL,
            content_before LONGTEXT NULL,
            after_hash CHAR(32) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            reverted TINYINT(1) NOT NULL DEFAULT 0,
            created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY created (created)
        ) {$charset_collate};";

        // Click tracking.
        $sql[] = "CREATE TABLE {$clicks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            target_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            url TEXT NOT NULL,
            anchor TEXT NULL,
            clicked_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY target_post_id (target_post_id),
            KEY clicked_at (clicked_at)
        ) {$charset_collate};";

        // URL changes + redirects.
        $sql[] = "CREATE TABLE {$url_changes} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            old_url TEXT NOT NULL,
            new_url TEXT NOT NULL,
            old_path VARCHAR(191) NOT NULL DEFAULT '',
            posts_changed INT NOT NULL DEFAULT 0,
            occurrences INT NOT NULL DEFAULT 0,
            redirect TINYINT(1) NOT NULL DEFAULT 0,
            created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY old_path (old_path),
            KEY redirect (redirect)
        ) {$charset_collate};";

        // Target keywords (focus keyword per post).
        $sql[] = "CREATE TABLE {$target_keywords} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            keyword VARCHAR(255) NOT NULL DEFAULT '',
            created DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY post_id (post_id)
        ) {$charset_collate};";

        // Search Console per-URL metrics.
        $sql[] = "CREATE TABLE {$gsc} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            url TEXT NOT NULL,
            url_hash CHAR(32) NOT NULL DEFAULT '',
            post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            clicks INT NOT NULL DEFAULT 0,
            impressions INT NOT NULL DEFAULT 0,
            ctr FLOAT NOT NULL DEFAULT 0,
            position FLOAT NOT NULL DEFAULT 0,
            imported DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY url_hash (url_hash),
            KEY post_id (post_id)
        ) {$charset_collate};";

        // External-site URLs (imported from sitemaps).
        $sql[] = "CREATE TABLE {$history} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            taken_on DATE NOT NULL DEFAULT '0000-00-00',
            posts INT NOT NULL DEFAULT 0,
            internal INT NOT NULL DEFAULT 0,
            external INT NOT NULL DEFAULT 0,
            orphaned INT NOT NULL DEFAULT 0,
            broken INT NOT NULL DEFAULT 0,
            opportunities INT NOT NULL DEFAULT 0,
            avg_depth DECIMAL(6,2) NOT NULL DEFAULT 0,
            taken DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            UNIQUE KEY taken_on (taken_on)
        ) {$charset_collate};";
        $sql[] = "CREATE TABLE {$external} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_label VARCHAR(191) NOT NULL DEFAULT '',
            url TEXT NOT NULL,
            url_hash CHAR(32) NOT NULL DEFAULT '',
            title VARCHAR(255) NOT NULL DEFAULT '',
            imported DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY  (id),
            KEY url_hash (url_hash)
        ) {$charset_collate};";

        foreach ($sql as $statement) {
            dbDelta($statement);
        }

        update_option(SLK_OPTION_DB_VERSION, SLK_DB_VERSION);

        // Seed default settings if missing.
        if (get_option(SLK_OPTION_SETTINGS) === false) {
            update_option(SLK_OPTION_SETTINGS, Slk_Settings::defaults());
        }
        if (get_option(SLK_OPTION_POST_TYPES) === false) {
            update_option(SLK_OPTION_POST_TYPES, ['post', 'page']);
        }
    }

    /**
     * Re-run install if the stored DB version is behind the code.
     */
    public static function maybe_upgrade()
    {
        if (get_option(SLK_OPTION_DB_VERSION) !== SLK_DB_VERSION) {
            self::install();
        }
    }
}
