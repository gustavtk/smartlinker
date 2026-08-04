<?php

use PHPUnit\Framework\TestCase;

require_once SLK_PLUGIN_DIR . 'core/Slk/Post.php';

/**
 * The SELECT list built for Slk_Post::walk_content().
 *
 * The walk itself needs $wpdb and is verified against a real site instead.
 * What is tested here is the column allow-list, because column names cannot
 * be passed to $wpdb->prepare() as placeholders — they are interpolated into
 * the SQL string — so an unfiltered name is an injection, not a bad query.
 */
class WalkColumnsTest extends TestCase
{
    public function test_id_and_content_are_always_selected()
    {
        $this->assertSame('ID, post_content', Slk_Post::walk_columns([]));
    }

    public function test_allowed_columns_are_added()
    {
        $this->assertSame('ID, post_content, post_title', Slk_Post::walk_columns(['post_title']));
        $this->assertSame(
            'ID, post_content, post_title, post_type',
            Slk_Post::walk_columns(['post_title', 'post_type'])
        );
    }

    /**
     * The reason the list exists. Anything not recognised is dropped, not
     * escaped and not passed through.
     */
    public function test_injection_attempts_are_dropped_entirely()
    {
        $attacks = [
            'post_content FROM wp_posts; DROP TABLE wp_users; --',
            '(SELECT user_pass FROM wp_users LIMIT 1)',
            'post_title, user_pass',
            '*',
            'ID); DELETE FROM wp_posts WHERE (1=1',
        ];
        foreach ($attacks as $bad) {
            $this->assertSame(
                'ID, post_content',
                Slk_Post::walk_columns([$bad]),
                'leaked through: ' . $bad
            );
        }
    }

    /**
     * A near-miss must fail too — matching is exact, not a prefix or substring
     * check, so "post_title" cannot smuggle "post_title, secret" past it.
     */
    public function test_matching_is_exact()
    {
        $this->assertSame('ID, post_content', Slk_Post::walk_columns(['post_titl']));
        $this->assertSame('ID, post_content', Slk_Post::walk_columns(['post_title ']));
        $this->assertSame('ID, post_content', Slk_Post::walk_columns(['POST_TITLE']));
    }

    public function test_a_good_column_beside_a_bad_one_still_drops_the_bad_one()
    {
        $this->assertSame(
            'ID, post_content, post_title',
            Slk_Post::walk_columns(['post_title', 'user_pass'])
        );
    }

    /** Asking twice must not select twice — that is a SQL error, not a nuisance. */
    public function test_duplicates_collapse()
    {
        $this->assertSame(
            'ID, post_content, post_title',
            Slk_Post::walk_columns(['post_title', 'post_title'])
        );
        $this->assertSame('ID, post_content', Slk_Post::walk_columns(['ID', 'post_content']));
    }

    /**
     * The slice size is the memory bound. Measured on a 1,200-post corpus,
     * peak content memory tracked it exactly: 25 → +2MB, 200 → +14MB,
     * 1500 → +82MB. A chunk large enough to swallow a whole site would put
     * the original bug straight back.
     */
    public function test_the_default_chunk_is_a_sane_bound()
    {
        $this->assertGreaterThan(0, Slk_Post::WALK_CHUNK);
        $this->assertLessThanOrEqual(500, Slk_Post::WALK_CHUNK);
    }
}
