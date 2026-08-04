<?php

use PHPUnit\Framework\TestCase;

/**
 * Undo coverage for the two operations that rewrite many posts at once.
 *
 * The Link Map and the URL Changer are the most destructive things the plugin
 * does — a find-and-replace across every post, applied from one click — and
 * for a long time they were the only writes with no way back. The preview said
 * how much *would* change; nothing could put it back afterwards.
 *
 * The batch machinery is exercised against a real database elsewhere. What is
 * pinned here is the wiring, because the failure is silent: remove the
 * `record()` call and everything still works perfectly, right up until someone
 * needs to undo.
 */
class BulkUndoTest extends TestCase
{
    protected static function source($file)
    {
        return file_get_contents(SLK_PLUGIN_DIR . $file);
    }

    /** The body of one method, for checking what happens inside it. */
    protected static function method($file, $name)
    {
        $src = self::source($file);
        $start = strpos($src, 'function ' . $name . '(');
        if ($start === false) {
            return '';
        }
        return substr($src, $start, 4000);
    }

    /* ---------------------------------------------------------------------
     * Every bulk write is logged
     * ------------------------------------------------------------------ */

    public function test_url_changer_logs_an_undo_entry()
    {
        $body = self::method('core/Slk/URLChanger.php', 'replace_sitewide');
        $this->assertNotSame('', $body, 'replace_sitewide() not found');
        $this->assertStringContainsString(
            'Slk_Activity::record',
            $body,
            'a site-wide URL replace must record an undo entry for every post it rewrites'
        );
        $this->assertStringContainsString(
            'Slk_Activity::new_batch',
            $body,
            'the rewrite must group its rows under one batch so it can be undone in one action'
        );
    }

    public function test_link_map_logs_an_undo_entry()
    {
        $body = self::method('core/Slk/LinkMap.php', 'handle_upload');
        if ($body === '') {
            // Method name may differ; fall back to the whole file.
            $body = self::source('core/Slk/LinkMap.php');
        }
        $this->assertStringContainsString('Slk_Activity::record', $body);
        $this->assertStringContainsString('Slk_Activity::new_batch', $body);
    }

    /**
     * The snapshot must be taken BEFORE the write.
     *
     * Recording afterwards would store the already-changed content as the
     * "before" state, and undo would restore the change it was meant to
     * reverse — an undo button that does nothing, which is worse than none
     * because it is trusted.
     */
    public function test_the_url_changer_snapshot_is_taken_before_the_write()
    {
        $body = self::method('core/Slk/URLChanger.php', 'replace_sitewide');
        $record = strpos($body, 'Slk_Activity::record');
        $update = strpos($body, 'wp_update_post');

        $this->assertNotFalse($record);
        $this->assertNotFalse($update);
        $this->assertLessThan(
            $update,
            $record,
            'the undo snapshot must be recorded before wp_update_post() changes the content'
        );
    }

    /* ---------------------------------------------------------------------
     * The batch itself
     * ------------------------------------------------------------------ */

    public function test_batch_revert_exists_and_reports_partial_failure()
    {
        $body = self::method('core/Slk/Activity.php', 'revert_batch');
        $this->assertNotSame('', $body, 'revert_batch() is missing');

        // A post edited since cannot be restored without discarding that edit.
        // Reporting "done" in that case is how someone finds out weeks later.
        foreach (['done', 'failed', 'errors'] as $key) {
            $this->assertStringContainsString(
                "'" . $key . "'",
                $body,
                "revert_batch() must report {$key} so partial success is visible"
            );
        }
    }

    /**
     * Pruning must not delete part of the batch currently being written.
     *
     * record() prunes after every row. With the default of 300 kept entries, a
     * 500-post rewrite would delete the first 200 rows of its own operation
     * while still running, leaving an undo that only half works — exactly what
     * the batch exists to prevent.
     */
    public function test_prune_will_not_split_the_newest_batch()
    {
        $body = self::method('core/Slk/Activity.php', 'prune');
        $this->assertNotSame('', $body);
        $this->assertStringContainsString(
            'batch <> %s',
            $body,
            'prune() must exclude the newest batch, or a large bulk operation truncates its own undo'
        );
    }

    public function test_the_activity_schema_has_a_batch_column()
    {
        $schema = self::source('core/Slk/Query.php');
        $start = strpos($schema, 'CREATE TABLE {$activity}');
        $block = substr($schema, $start, 900);

        $this->assertStringContainsString('batch VARCHAR', $block, 'no batch column');
        $this->assertStringContainsString('KEY batch (batch)', $block, 'batch should be indexed — it is grouped and filtered on');
    }

    /** A new column means the schema version must move, or nobody gets it. */
    public function test_the_db_version_was_bumped_for_the_batch_column()
    {
        preg_match("/define\('SLK_DB_VERSION',\s*'(\d+)'\)/", self::source('smartlinker.php'), $m);
        $this->assertNotEmpty($m);
        $this->assertGreaterThanOrEqual(
            9,
            (int) $m[1],
            'the batch column arrived in DB version 9; existing installs only get it if the version moved'
        );
    }

    /** The new action type needs a label, or the log shows a blank filter. */
    public function test_the_rewrite_action_is_labelled()
    {
        $this->assertStringContainsString(
            "'rewrite'",
            self::method('core/Slk/Activity.php', 'actions'),
            'the URL-replace action needs a human label in actions()'
        );
    }
}
