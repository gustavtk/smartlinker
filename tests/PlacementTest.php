<?php

use PHPUnit\Framework\TestCase;

/**
 * Link placement.
 *
 * The measurement is the whole feature, and it has one property that is easy
 * to get wrong and impossible to notice: position must be measured in VISIBLE
 * TEXT, not in the HTML source. Markup is unevenly distributed — a post opening
 * with a gallery carries far more tags per word at the top — so measuring raw
 * offsets reports a link as halfway down a post the reader sees as near the
 * start. That property is pinned first.
 */
class PlacementTest extends TestCase
{
    private $home = 'https://example.test';

    private function link($text)
    {
        return '<a href="' . $this->home . '/target/">' . $text . '</a>';
    }

    /** 500 characters of visible body copy. */
    private function filler()
    {
        return str_repeat('word ', 100);
    }

    public function test_a_link_at_the_start_is_at_zero()
    {
        $c = $this->link('here') . $this->filler();
        $p = Slk_Placement::positions($c, $this->home);
        $this->assertEqualsWithDelta(0.0, $p[0]['position'], 0.02);
    }

    public function test_a_link_at_the_end_is_at_one()
    {
        $c = $this->filler() . $this->link('here');
        $p = Slk_Placement::positions($c, $this->home);
        $this->assertEqualsWithDelta(1.0, $p[0]['position'], 0.02);
    }

    public function test_a_link_in_the_middle_is_at_a_half()
    {
        $c = $this->filler() . $this->link('here') . $this->filler();
        $p = Slk_Placement::positions($c, $this->home);
        $this->assertEqualsWithDelta(0.5, $p[0]['position'], 0.02);
    }

    /**
     * The property that matters: heavy markup before a link must not push its
     * reported position down the article.
     */
    public function test_markup_does_not_shift_the_measurement()
    {
        $plain = $this->filler() . $this->link('here') . $this->filler();
        $heavy = '<figure class="' . str_repeat('x', 400) . '"><img src="a.jpg" /></figure>'
            . $this->filler() . $this->link('here') . $this->filler();

        $a = Slk_Placement::positions($plain, $this->home)[0]['position'];
        $b = Slk_Placement::positions($heavy, $this->home)[0]['position'];
        $this->assertEqualsWithDelta($a, $b, 0.02);
    }

    public function test_external_links_are_excluded()
    {
        $c = $this->link('internal') . $this->filler() . '<a href="https://elsewhere.example/">external</a>';
        $p = Slk_Placement::positions($c, $this->home);
        $this->assertCount(1, $p);
        $this->assertSame('internal', $p[0]['anchor']);
    }

    public function test_relative_links_count_as_internal()
    {
        $c = '<a href="/some-post/">relative</a>' . $this->filler();
        $p = Slk_Placement::positions($c, $this->home);
        $this->assertCount(1, $p);
    }

    public function test_content_without_links_yields_nothing()
    {
        $this->assertSame([], Slk_Placement::positions($this->filler(), $this->home));
        $this->assertSame([], Slk_Placement::positions('', $this->home));
        $this->assertSame([], Slk_Placement::positions('   ', $this->home));
    }

    /* -- shape classification ---------------------------------------- */

    private function shapeOf(array $fractions)
    {
        return Slk_Placement::summarise(array_map(function ($f) {
            return ['anchor' => 'x', 'url' => '/x/', 'position' => $f];
        }, $fractions));
    }

    public function test_links_late_in_the_post_are_called_buried()
    {
        $s = $this->shapeOf([0.9, 0.95]);
        $this->assertSame('bottom', $s['shape']);
        $this->assertSame(2, $s['last_quarter']);
        $this->assertSame(0, $s['first_quarter']);
    }

    public function test_links_early_in_the_post_are_called_well_placed()
    {
        $s = $this->shapeOf([0.05, 0.30]);
        $this->assertSame('top', $s['shape']);
        $this->assertSame(1, $s['first_quarter']);
    }

    public function test_links_spread_through_the_post_are_neither()
    {
        $this->assertSame('mixed', $this->shapeOf([0.1, 0.9])['shape']);
    }

    /**
     * One link is a position, not a pattern. Calling a single late link
     * "bottom-heavy" would be an overreach the author cannot act on.
     */
    public function test_a_single_link_gets_no_shape_verdict()
    {
        $this->assertSame('single', $this->shapeOf([0.99])['shape']);
        $this->assertSame('single', $this->shapeOf([0.01])['shape']);
    }

    public function test_no_links_is_reported_as_none()
    {
        $s = $this->shapeOf([]);
        $this->assertSame('none', $s['shape']);
        $this->assertNull($s['avg']);
        $this->assertSame(0, $s['links']);
    }

    public function test_the_average_is_the_mean_position()
    {
        $this->assertEqualsWithDelta(0.5, $this->shapeOf([0.25, 0.75])['avg'], 0.0001);
    }
}
