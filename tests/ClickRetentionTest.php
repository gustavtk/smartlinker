<?php

use PHPUnit\Framework\TestCase;

/**
 * Click-log retention.
 *
 * The database half is exercised against a real site. What is pinned here is
 * the shape of the thing, because the click table is the one table in the
 * plugin whose size is set by visitor traffic rather than by anything the
 * owner does — so the guards on it are the ones that must not quietly
 * regress.
 */
class ClickRetentionTest extends TestCase
{
    protected function setUp(): void
    {
        require_once SLK_PLUGIN_DIR . 'core/Slk/ClickTracker.php';
    }

    /**
     * A throttle high enough to be invisible to a real reader and low enough
     * to matter. Someone genuinely reading fast might click a handful of links
     * a minute; 30 leaves room for that without letting a loop run free.
     */
    public function test_the_throttle_leaves_room_for_a_real_reader()
    {
        $this->assertGreaterThanOrEqual(10, Slk_ClickTracker::THROTTLE_MAX);
        $this->assertLessThanOrEqual(100, Slk_ClickTracker::THROTTLE_MAX);
        $this->assertGreaterThan(0, Slk_ClickTracker::THROTTLE_WINDOW);
    }

    /**
     * Pruning in batches is the point: the first pass on a table that has been
     * filling for years could otherwise be a single DELETE over millions of
     * rows, which can lock the table long enough to take the site down.
     */
    public function test_pruning_is_batched()
    {
        $this->assertGreaterThan(0, Slk_ClickTracker::PRUNE_BATCH);
        $this->assertLessThanOrEqual(
            10000,
            Slk_ClickTracker::PRUNE_BATCH,
            'a batch this large stops being a batch'
        );
    }

    /**
     * The prune must not use "DELETE ... LIMIT".
     *
     * It is a MySQL extension. SQLite rejects it unless compiled with a
     * non-default flag, and WordPress ships an official SQLite integration —
     * so that form works on most installs and fails silently on the rest,
     * leaving the table growing exactly where nobody thinks to look. This was
     * a real bug here, caught only because the demo runs on SQLite.
     */
    public function test_prune_does_not_use_the_mysql_only_delete_limit()
    {
        $src = file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/ClickTracker.php');
        $this->assertDoesNotMatchRegularExpression(
            '/DELETE\s+FROM[^"\']*LIMIT/i',
            $src,
            'DELETE ... LIMIT is not portable; select ids first, then delete by id'
        );
    }

    /**
     * The IP is used to bucket requests and must never be stored.
     *
     * Logging visitor IPs would turn a link-click counter into something
     * carrying data-protection obligations, for no gain to the feature.
     */
    public function test_the_client_key_is_hashed_and_the_ip_is_never_stored()
    {
        $src = file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/ClickTracker.php');

        // The key is a hash...
        $this->assertMatchesRegularExpression(
            "/hash\(\s*'sha256'/",
            $src,
            'the throttle key must be hashed'
        );

        // ...and no IP reaches the insert.
        preg_match('/\$wpdb->insert\(.*?\]\);/s', $src, $m);
        $this->assertNotEmpty($m, 'could not find the click insert');
        foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'ip'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $m[0],
                "the click row must not carry {$forbidden}"
            );
        }
    }

    /**
     * Turning tracking off must not freeze existing rows in the database
     * forever — they should still age out.
     */
    public function test_the_prune_event_is_registered_even_when_tracking_is_off()
    {
        $src = file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/ClickTracker.php');
        $register = substr($src, strpos($src, 'public function register()'));
        $register = substr($register, 0, strpos($register, "\n    }"));

        $prune_pos = strpos($register, 'PRUNE_EVENT');
        $guard_pos = strpos($register, "Slk_Settings::get('track_clicks')");

        $this->assertNotFalse($prune_pos);
        $this->assertNotFalse($guard_pos);
        $this->assertLessThan(
            $guard_pos,
            $prune_pos,
            'the prune event must be hooked before the track_clicks early return'
        );
    }
}
