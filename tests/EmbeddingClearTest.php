<?php

use PHPUnit\Framework\TestCase;

/**
 * Clearing the semantic index.
 *
 * The bug this guards against was reported from the screen, not found by a
 * test: clicking "Clear index" deleted every row and the page still said
 * "Up to date".
 *
 * The cause is a mismatch that is easy to reintroduce. The delete went
 * straight to the postmeta table with $wpdb->delete(), which does not touch
 * WordPress's object cache; status() reads the same values back through
 * get_post_meta(), which does. The data was gone and the screen was reading a
 * cached copy of it.
 *
 * Without a persistent object cache it corrected itself on the next request,
 * which is why it looked intermittent. With Redis or Memcached — most managed
 * WordPress hosting — it would have stayed wrong.
 */
class EmbeddingClearTest extends TestCase
{
    protected static function source()
    {
        return file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/Embedding.php');
    }

    protected static function method($name)
    {
        $src = self::source();
        $start = strpos($src, 'function ' . $name . '(');
        return $start === false ? '' : substr($src, $start, 2500);
    }

    /**
     * Any direct postmeta delete must be followed by a cache invalidation.
     */
    public function test_clearing_invalidates_the_post_meta_cache()
    {
        $body = self::method('clear');
        $this->assertNotSame('', $body, 'clear() is missing');

        $this->assertStringContainsString(
            "wp_cache_delete",
            $body,
            'a direct $wpdb->delete on postmeta must be followed by wp_cache_delete, '
            . 'or get_post_meta() keeps returning the deleted values'
        );
        $this->assertStringContainsString(
            "'post_meta'",
            $body,
            'the post_meta cache group is the one that must be cleared'
        );
    }

    /**
     * The ids have to be read BEFORE the delete — afterwards there is nothing
     * left to look up, and nothing to clear the cache for.
     */
    public function test_ids_are_collected_before_the_delete()
    {
        $body = self::method('clear');
        $select = strpos($body, 'SELECT DISTINCT post_id');
        $delete = strpos($body, '$wpdb->delete');

        $this->assertNotFalse($select, 'clear() must look up the affected posts');
        $this->assertNotFalse($delete);
        $this->assertLessThan($delete, $select, 'the ids must be collected before the rows are deleted');
    }

    /**
     * No handler may delete embedding meta directly any more — that is the
     * shape of the original bug, and clear() is the only safe way.
     */
    public function test_no_handler_deletes_embedding_meta_directly()
    {
        $src = self::source();
        $handlers = substr($src, strpos($src, 'function handle_actions('));

        $this->assertStringNotContainsString(
            '$wpdb->delete($wpdb->postmeta',
            $handlers,
            'handle_actions() must call clear() rather than deleting postmeta itself'
        );
    }

    /**
     * The cache invalidation must NOT be limited to the rows just deleted.
     *
     * The first fix cleared the cache only for ids the delete had found, which
     * is useless in the one case that matters: if an earlier clear removed the
     * rows and left the cache, there is nothing to find, nothing is
     * invalidated, and the screen reports posts as indexed forever. In the
     * field it said "0 posts removed" while still showing 24 of 25 indexed.
     */
    public function test_invalidation_does_not_depend_on_what_was_deleted()
    {
        $body = self::method('clear');

        $this->assertTrue(
            strpos($body, 'flush_group') !== false
                && strpos($body, 'candidate_targets') !== false,
            'clear() must flush the whole post_meta group where supported, and otherwise '
            . 'clear every post the status screen reads — not only the rows it happened to find'
        );

        // The naive version: a single loop over the found ids and nothing else.
        $this->assertStringNotContainsString(
            "foreach (\$ids as \$id) {\n            wp_cache_delete",
            $body,
            'invalidating only the found ids leaves an already-emptied index permanently stale'
        );
    }

    /** The button says "Clear index" — it is a clear, not a rebuild. */
    public function test_the_button_is_labelled_clear()
    {
        $tpl = file_get_contents(SLK_PLUGIN_DIR . 'templates/ai.php');
        $this->assertStringContainsString("'Clear index'", $tpl);
    }
}
