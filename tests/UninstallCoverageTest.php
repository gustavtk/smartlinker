<?php

use PHPUnit\Framework\TestCase;

/**
 * Does uninstall actually remove everything the plugin creates?
 *
 * This failure mode is invisible. Add a table, a cron event or a meta key,
 * forget to extend the cleanup, and nothing breaks — the plugin works
 * perfectly. The only symptom appears months later, in someone else's
 * database, as rows nobody can account for. There is no error to notice and
 * no screen that shows it.
 *
 * It has already happened once here: `uninstall.php` hardcodes its table list,
 * a ninth table (`slk_history`) was added, and the list was only updated
 * because it was caught by hand. These tests make the next one impossible to
 * miss.
 *
 * The list is deliberately NOT generated from the schema. Dropping a table
 * cannot be undone, so the one destructive step in the plugin gets an explicit
 * list a human wrote — and a test that says when the list has fallen behind.
 */
class UninstallCoverageTest extends TestCase
{
    protected static function uninstall()
    {
        return file_get_contents(SLK_PLUGIN_DIR . 'uninstall.php');
    }

    protected static function source($relative)
    {
        return file_get_contents(SLK_PLUGIN_DIR . $relative);
    }

    /** Every `slk_*` table name Slk_Query declares an accessor for. */
    protected static function declared_tables()
    {
        preg_match_all(
            "/return \\\$wpdb->prefix \. '(slk_\w+)'/",
            self::source('core/Slk/Query.php'),
            $m
        );
        return array_unique($m[1]);
    }

    /* ---------------------------------------------------------------------
     * Tables
     * ------------------------------------------------------------------ */

    public function test_the_schema_declares_tables_at_all()
    {
        // Guards the regex above: if Slk_Query's style changes, the other
        // tests here would silently pass on an empty list.
        $this->assertNotEmpty(
            self::declared_tables(),
            'no tables parsed from Slk_Query — this test can no longer see the schema'
        );
    }

    public function test_every_table_is_dropped_on_uninstall()
    {
        $uninstall = self::uninstall();
        foreach (self::declared_tables() as $table) {
            $this->assertStringContainsString(
                "'{$table}'",
                $uninstall,
                "{$table} is created but never dropped. Add it to the \$tables list in "
                . 'uninstall.php, or deleting the plugin leaves it behind forever.'
            );
        }
    }

    public function test_uninstall_does_not_drop_a_table_that_no_longer_exists()
    {
        preg_match('/\$tables = \[(.*?)\];/s', self::uninstall(), $m);
        $this->assertNotEmpty($m, 'could not find the $tables list in uninstall.php');
        preg_match_all("/'(slk_\w+)'/", $m[1], $listed);

        foreach ($listed[1] as $table) {
            $this->assertContains(
                $table,
                self::declared_tables(),
                "uninstall.php drops {$table}, which the schema no longer creates. "
                . 'Stale entries are harmless but they hide real drift.'
            );
        }
    }

    /* ---------------------------------------------------------------------
     * Scheduled events
     * ------------------------------------------------------------------ */

    /**
     * A cron event outlives the plugin. WordPress keeps firing the hook, finds
     * nothing listening, and quietly retries forever.
     */
    public function test_every_cron_event_is_cleared_on_deactivate_and_uninstall()
    {
        $events = [];
        foreach (glob(SLK_PLUGIN_DIR . 'core/Slk/*.php') as $file) {
            if (preg_match_all("/const (?:HOOK|EVENT|PRUNE_EVENT) *= *'(slk_\w+)'/", file_get_contents($file), $m)) {
                $events = array_merge($events, $m[1]);
            }
        }
        $events = array_unique($events);
        $this->assertNotEmpty($events, 'no cron event constants found — has the naming changed?');

        $deactivate = self::source('core/Slk/Base.php');
        $uninstall = self::uninstall();

        foreach ($events as $event) {
            // Deactivation clears via the constant, uninstall via the literal
            // (it runs without the plugin loaded, so no class is available).
            $this->assertMatchesRegularExpression(
                '/wp_clear_scheduled_hook\([^)]*(' . preg_quote($event, '/') . '|::(HOOK|EVENT|PRUNE_EVENT))/',
                $deactivate,
                "{$event} is scheduled but not cleared in Slk_Base::deactivate()"
            );
            $this->assertStringContainsString(
                "'{$event}'",
                $uninstall,
                "{$event} is scheduled but not cleared in uninstall.php"
            );
        }
    }

    /* ---------------------------------------------------------------------
     * Prefixes
     * ------------------------------------------------------------------ */

    /**
     * Options and transients are removed by prefix match, so anything not
     * named `slk_*` survives deletion silently.
     */
    public function test_every_option_and_transient_key_uses_the_slk_prefix()
    {
        foreach (glob(SLK_PLUGIN_DIR . 'core/Slk/*.php') as $file) {
            preg_match_all(
                "/const (\w*(?:OPTION|TRANSIENT|SNAPSHOT|LAST_RUN)\w*) *= *'([^']+)'/",
                file_get_contents($file),
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as $hit) {
                $this->assertStringStartsWith(
                    'slk_',
                    $hit[2],
                    basename($file) . "::{$hit[1]} = '{$hit[2]}' — uninstall deletes options by "
                    . "the 'slk_' prefix, so this key would be left behind."
                );
            }
        }
    }

    /**
     * Post meta is removed by the `_slk_` prefix. Embeddings are one packed
     * vector per post, so a missed key here is the largest thing the plugin
     * can leave behind on a big site.
     */
    public function test_every_post_meta_key_uses_the_underscore_slk_prefix()
    {
        foreach (glob(SLK_PLUGIN_DIR . 'core/Slk/*.php') as $file) {
            preg_match_all(
                "/const (\w*META\w*) *= *'([^']+)'/",
                file_get_contents($file),
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as $hit) {
                $this->assertStringStartsWith(
                    '_slk_',
                    $hit[2],
                    basename($file) . "::{$hit[1]} = '{$hit[2]}' — uninstall deletes post meta by "
                    . "the '_slk_' prefix, so this key would be left behind."
                );
            }
        }
    }

    /* ---------------------------------------------------------------------
     * The safety property
     * ------------------------------------------------------------------ */

    /**
     * The whole file must stay behind an explicit opt-in. People delete a
     * plugin to reinstall it, to move hosts, to test a conflict — none of
     * those should destroy a link index and an undo history.
     */
    public function test_cleanup_stays_opt_in()
    {
        $src = self::uninstall();
        $this->assertStringContainsString('delete_data_on_uninstall', $src);
        $this->assertStringContainsString('WP_UNINSTALL_PLUGIN', $src, 'must refuse to run when included directly');
        $this->assertStringContainsString('current_user_can', $src, 'must check capability');
        $this->assertStringContainsString('is_multisite', $src, 'each site must decide for itself');
    }

    public function test_the_default_is_to_keep_everything()
    {
        $this->assertMatchesRegularExpression(
            "/'delete_data_on_uninstall'\s*=>\s*0/",
            self::source('core/Slk/Settings.php'),
            'data deletion must default to OFF'
        );
    }
}
