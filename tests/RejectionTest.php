<?php

use PHPUnit\Framework\TestCase;

/**
 * Learned rejections.
 *
 * This is a filter that removes suggestions the user never sees, which makes
 * it the most dangerous kind of feature to get wrong: a bug here looks like
 * "the plugin stopped finding links" rather than like an error. So the rules
 * are pinned tightly — especially the ones that stop it over-reaching.
 *
 * Two of these tests exist because the first implementation got it wrong.
 * Restoring an anchor left the narrower pair block silently in force, and the
 * restore flag acted as a permanent whitelist with no way back.
 */
class RejectionTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['slk_test_options'] = [];
    }

    /* -----------------------------------------------------------------
     * Thresholds.
     * ----------------------------------------------------------------- */

    public function test_a_single_rejection_blocks_nothing()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        $this->assertFalse(Slk_Rejection::pair_blocked('equipment', 100));
        $this->assertFalse(Slk_Rejection::anchor_blocked('equipment'));
        $this->assertFalse(Slk_Rejection::blocked('equipment', 100));
    }

    /**
     * The rule that stops one irritated editor retiring an anchor: opinions
     * are counted per post, not per click.
     */
    public function test_rejecting_twice_on_one_post_counts_once()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(1, 100, 'equipment');
        $this->assertFalse(Slk_Rejection::pair_blocked('equipment', 100));
        $this->assertFalse(Slk_Rejection::anchor_blocked('equipment'));
    }

    public function test_two_distinct_posts_retire_the_pair()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        $this->assertTrue(Slk_Rejection::pair_blocked('equipment', 100));
    }

    public function test_retiring_a_pair_leaves_other_destinations_alone()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        $this->assertTrue(Slk_Rejection::blocked('equipment', 100));
        $this->assertFalse(Slk_Rejection::blocked('equipment', 200), 'a different target must be unaffected');
    }

    public function test_three_distinct_posts_retire_the_anchor_everywhere()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        Slk_Rejection::record(3, 200, 'equipment');
        $this->assertTrue(Slk_Rejection::anchor_blocked('equipment'));
        $this->assertTrue(Slk_Rejection::blocked('equipment', 999), 'any destination at all');
    }

    public function test_an_unrelated_anchor_is_never_touched()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        Slk_Rejection::record(3, 200, 'equipment');
        $this->assertFalse(Slk_Rejection::blocked('burr grinder', 100));
        $this->assertFalse(Slk_Rejection::blocked('burr grinder', 999));
    }

    /* -----------------------------------------------------------------
     * Normalization — the same anchor written differently is one anchor.
     * ----------------------------------------------------------------- */

    public function test_case_and_punctuation_are_the_same_anchor()
    {
        Slk_Rejection::record(1, 100, 'Equipment');
        Slk_Rejection::record(2, 100, 'equipment.');
        Slk_Rejection::record(3, 200, '  EQUIPMENT  ');
        $this->assertTrue(Slk_Rejection::anchor_blocked('equipment'));
        $this->assertTrue(Slk_Rejection::blocked('Equipment!', 999));
    }

    public function test_an_empty_anchor_records_nothing()
    {
        $r = Slk_Rejection::record(1, 100, '');
        $this->assertFalse($r['anchor_blocked']);
        $this->assertFalse($r['pair_blocked']);
        $this->assertSame([], Slk_Rejection::suppressed());
    }

    /* -----------------------------------------------------------------
     * Restore. Both of these are regressions.
     * ----------------------------------------------------------------- */

    /**
     * The bug: restoring an anchor released the site-wide block but left the
     * narrower pair block in force, so the anchor read as restored on screen
     * while still being silently suppressed for its original destination.
     */
    public function test_restoring_an_anchor_also_releases_its_pairs()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');   // pair now blocked
        Slk_Rejection::record(3, 200, 'equipment');   // anchor now blocked
        $this->assertTrue(Slk_Rejection::pair_blocked('equipment', 100));

        Slk_Rejection::restore('anchors', Slk_Rejection::key('equipment'));

        $this->assertFalse(Slk_Rejection::anchor_blocked('equipment'));
        $this->assertFalse(Slk_Rejection::pair_blocked('equipment', 100), 'the pair block must be released too');
        $this->assertFalse(Slk_Rejection::blocked('equipment', 100));
    }

    /**
     * The other bug: restore granted a permanent exemption, so changing your
     * mind back was impossible.
     */
    public function test_restore_is_not_a_one_way_door()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        Slk_Rejection::restore('pairs', Slk_Rejection::pair_key('equipment', 100));
        $this->assertFalse(Slk_Rejection::pair_blocked('equipment', 100));

        // Rejecting it again must be able to retire it again.
        Slk_Rejection::record(4, 100, 'equipment');
        Slk_Rejection::record(5, 100, 'equipment');
        $this->assertTrue(Slk_Rejection::pair_blocked('equipment', 100));
    }

    public function test_restore_clears_the_old_evidence_rather_than_carrying_it()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        Slk_Rejection::restore('pairs', Slk_Rejection::pair_key('equipment', 100));

        // One fresh rejection must not immediately re-trip the threshold; the
        // two that came before the restore no longer count.
        Slk_Rejection::record(3, 100, 'equipment');
        $this->assertFalse(Slk_Rejection::pair_blocked('equipment', 100));
    }

    public function test_restoring_something_unknown_is_harmless()
    {
        $this->assertFalse(Slk_Rejection::restore('anchors', 'never-seen'));
        $this->assertFalse(Slk_Rejection::restore('nonsense', 'whatever'));
    }

    /* -----------------------------------------------------------------
     * The management screen's data.
     * ----------------------------------------------------------------- */

    public function test_suppressed_lists_what_is_actually_blocking()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        $rows = Slk_Rejection::suppressed();

        $this->assertCount(1, $rows);
        $this->assertSame('pair', $rows[0]['scope']);
        $this->assertSame(100, $rows[0]['target']);
        $this->assertSame('equipment', $rows[0]['anchor']);
    }

    /** Once an anchor is retired everywhere, listing its pairs is noise. */
    public function test_a_retired_anchor_hides_its_own_pair_rows()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        Slk_Rejection::record(3, 200, 'equipment');

        $rows = Slk_Rejection::suppressed();
        $this->assertCount(1, $rows);
        $this->assertSame('anchor', $rows[0]['scope']);
    }

    public function test_pending_shows_progress_towards_a_block()
    {
        Slk_Rejection::record(1, 100, 'municipal');
        $pending = Slk_Rejection::pending();

        $this->assertCount(1, $pending);
        $this->assertSame('municipal', $pending[0]['anchor']);
        $this->assertSame(1, $pending[0]['count']);
        $this->assertSame(Slk_Rejection::ANCHOR_THRESHOLD, $pending[0]['needed']);
    }

    public function test_pending_drops_an_anchor_once_it_is_blocked()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        Slk_Rejection::record(3, 200, 'equipment');
        $this->assertSame([], Slk_Rejection::pending());
    }

    /* -----------------------------------------------------------------
     * Configurable thresholds.
     * ----------------------------------------------------------------- */

    private function setThresholds($pair, $anchor)
    {
        $GLOBALS['slk_test_options'][SLK_OPTION_SETTINGS] = [
            'reject_pair_threshold'   => $pair,
            'reject_anchor_threshold' => $anchor,
        ];
    }

    public function test_thresholds_default_when_unset()
    {
        $this->assertSame(Slk_Rejection::PAIR_THRESHOLD, Slk_Rejection::pair_threshold());
        $this->assertSame(Slk_Rejection::ANCHOR_THRESHOLD, Slk_Rejection::anchor_threshold());
    }

    public function test_raising_the_threshold_delays_suppression()
    {
        $this->setThresholds(5, 8);
        for ($i = 1; $i <= 4; $i++) {
            Slk_Rejection::record($i, 100, 'equipment');
        }
        $this->assertFalse(Slk_Rejection::pair_blocked('equipment', 100), '4 posts is short of 5');

        Slk_Rejection::record(5, 100, 'equipment');
        $this->assertTrue(Slk_Rejection::pair_blocked('equipment', 100));
    }

    public function test_lowering_the_threshold_suppresses_sooner()
    {
        $this->setThresholds(1, 1);
        Slk_Rejection::record(1, 100, 'equipment');
        $this->assertTrue(Slk_Rejection::pair_blocked('equipment', 100));
        $this->assertTrue(Slk_Rejection::anchor_blocked('equipment'));
    }

    /**
     * Zero is the off switch, and it must not lose what was already learned —
     * turning it back on should restore the previous behaviour.
     */
    public function test_zero_switches_learning_off_without_losing_data()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        Slk_Rejection::record(2, 100, 'equipment');
        Slk_Rejection::record(3, 200, 'equipment');
        $this->assertTrue(Slk_Rejection::blocked('equipment', 100));

        $this->setThresholds(0, 0);
        $this->assertTrue(Slk_Rejection::learning_off());
        $this->assertFalse(Slk_Rejection::blocked('equipment', 100), 'nothing may be suppressed while off');
        $this->assertSame([], Slk_Rejection::suppressed());

        // Back on: the evidence was never discarded.
        $this->setThresholds(2, 3);
        $this->assertTrue(Slk_Rejection::blocked('equipment', 100));
    }

    public function test_an_out_of_range_threshold_is_clamped()
    {
        $this->setThresholds(-5, 9999);
        $this->assertSame(0, Slk_Rejection::pair_threshold());
        $this->assertSame(50, Slk_Rejection::anchor_threshold());
    }

    public function test_pending_reports_the_configured_target()
    {
        $this->setThresholds(2, 7);
        Slk_Rejection::record(1, 100, 'municipal');
        $pending = Slk_Rejection::pending();
        $this->assertSame(7, $pending[0]['needed']);
    }

    public function test_record_reports_what_it_just_made_true()
    {
        Slk_Rejection::record(1, 100, 'equipment');
        $second = Slk_Rejection::record(2, 100, 'equipment');
        $this->assertTrue($second['pair_blocked']);
        $this->assertFalse($second['anchor_blocked']);

        $third = Slk_Rejection::record(3, 200, 'equipment');
        $this->assertTrue($third['anchor_blocked']);
    }
}
