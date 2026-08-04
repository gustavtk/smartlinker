<?php

use PHPUnit\Framework\TestCase;

/**
 * The equity boost — how a starved target is promoted in the suggestion order.
 *
 * The rule this pins is a design decision, not an implementation detail:
 * confidence answers "is this the right link" and must stay a claim about
 * correctness. How starved a page is answers "is this a useful link" — a claim
 * about value. Folding the second into the first would make the percentage on
 * screen a lie. So the boost moves rows, never the number.
 *
 * The arithmetic is reproduced here rather than reached through for_post(),
 * which needs a database. What matters is that the BOUNDS hold: a near-equal
 * gets reordered and a clearly worse match never does.
 */
class EquityBoostTest extends TestCase
{
    /** priority = confidence × (1 + boost × need), as for_post() computes it. */
    private function priority($confidence, $need, $boost)
    {
        return $confidence * (1 + ($boost * $need));
    }

    public function test_no_boost_leaves_the_order_alone()
    {
        $strong = $this->priority(0.77, 0.0, 0.0);
        $starved = $this->priority(0.75, 1.0, 0.0);
        $this->assertGreaterThan($starved, $strong);
    }

    public function test_a_starved_page_overtakes_a_near_equal()
    {
        // 75% starved vs 77% well-linked — the case the feature exists for.
        $starved = $this->priority(0.75, 0.5, 0.15);
        $linked = $this->priority(0.77, 0.0, 0.15);
        $this->assertGreaterThan($linked, $starved);
    }

    /**
     * The bound that makes this safe to ship on by default: at the default
     * boost, a clearly worse match cannot be promoted over a good one however
     * starved it is.
     */
    public function test_a_clearly_worse_match_is_never_promoted()
    {
        $best = $this->priority(0.90, 0.0, 0.15);
        $desperate = $this->priority(0.62, 1.0, 0.15);
        $this->assertLessThan($best, $desperate);
    }

    public function test_the_maximum_overtake_is_the_boost_itself()
    {
        // A fully starved page can beat a rival scoring up to `boost` higher,
        // and no more. At 0.15 that is 15%.
        $boost = 0.15;
        $starved = $this->priority(1.00, 1.0, $boost);
        $this->assertEqualsWithDelta(1.15, $starved, 0.0001);

        $this->assertGreaterThan($this->priority(1.149, 0.0, $boost), $starved);
        $this->assertLessThan($this->priority(1.151, 0.0, $boost), $starved);
    }

    public function test_a_well_linked_page_is_never_penalised()
    {
        // need 0 must leave the score exactly as it was — the feature promotes,
        // it does not demote.
        foreach ([0.0, 0.15, 0.5] as $boost) {
            $this->assertSame(0.80, $this->priority(0.80, 0.0, $boost));
        }
    }

    public function test_need_scales_the_promotion_smoothly()
    {
        $none = $this->priority(0.70, 0.0, 0.15);
        $half = $this->priority(0.70, 0.5, 0.15);
        $full = $this->priority(0.70, 1.0, 0.15);
        $this->assertLessThan($half, $none);
        $this->assertLessThan($full, $half);
    }

    /* -- the need curve itself -------------------------------------- */

    /** need = clamp((1 - relative) / 0.75, 0, 1), as Slk_Equity computes it. */
    private function need($relative)
    {
        return max(0.0, min(1.0, (1.0 - $relative) / 0.75));
    }

    public function test_an_average_or_better_page_has_no_need()
    {
        $this->assertSame(0.0, $this->need(1.0));
        $this->assertSame(0.0, $this->need(3.8));
    }

    public function test_a_badly_starved_page_has_maximum_need()
    {
        $this->assertSame(1.0, $this->need(0.25));
        $this->assertSame(1.0, $this->need(0.0));
    }

    public function test_need_is_gradual_between_the_two()
    {
        $this->assertEqualsWithDelta(0.5, $this->need(0.625), 0.0001);
        $this->assertGreaterThan($this->need(0.8), $this->need(0.4));
    }
}
