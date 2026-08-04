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
            'Slk_Post::flush_meta_cache',
            $body,
            'a direct $wpdb->delete on postmeta must be followed by a cache flush, '
            . 'or get_post_meta() keeps returning the deleted values'
        );
    }

    /**
     * THE RULE, not just the two instances.
     *
     * Every direct delete against the postmeta table must be followed by a
     * cache flush. This shipped twice — the semantic index and the cached AI
     * results — and both were invisible without a persistent object cache.
     * The reporter was running LiteSpeed, which has one.
     */
    public function test_every_direct_postmeta_delete_flushes_the_cache()
    {
        $offenders = [];
        foreach (glob(SLK_PLUGIN_DIR . 'core/Slk/*.php') as $file) {
            $src = file_get_contents($file);
            $offset = 0;
            while (($pos = strpos($src, '$wpdb->delete($wpdb->postmeta', $offset)) !== false) {
                $offset = $pos + 1;

                /*
                 * Scan to the END OF THE ENCLOSING METHOD, not a fixed number
                 * of characters. The first version looked 900 chars ahead and
                 * reported a false positive the moment a comment explaining
                 * the flush pushed the call past the window — a guard that
                 * fails on well-documented code trains people to delete it.
                 */
                $end = strpos($src, "\n    }", $pos);
                $body = $end === false ? substr($src, $pos) : substr($src, $pos, $end - $pos);

                if (strpos($body, 'flush_meta_cache') === false
                    && strpos($body, 'wp_cache_flush_group') === false) {
                    $line = substr_count(substr($src, 0, $pos), "\n") + 1;
                    $offenders[] = basename($file) . ':' . $line;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These delete post meta directly without flushing the object cache. On a site "
            . "with Redis, Memcached or LiteSpeed the deleted values keep being served:\n  "
            . implode("\n  ", $offenders)
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

        $this->assertStringContainsString(
            'Slk_Post::flush_meta_cache',
            $body,
            'clear() must invalidate the whole post_meta cache, not only the rows it found'
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
