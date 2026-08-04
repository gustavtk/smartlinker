<?php

use PHPUnit\Framework\TestCase;

require_once SLK_PLUGIN_DIR . 'core/Slk/History.php';

/**
 * Trend history: what counts as improvement, and the geometry of the lines
 * drawn from it.
 *
 * The database half (capture, prune, the daily event) needs $wpdb and is out
 * of scope for this suite by design. What is tested here is the part that can
 * be quietly wrong without anyone noticing: a chart that colours a rise in
 * broken links green, or a flat series that divides by zero.
 */
class HistoryTest extends TestCase
{
    /** Three days of readings: linking clearly improving. */
    protected function improving()
    {
        return [
            ['taken_on' => '2026-06-01', 'posts' => 40, 'internal' => 10, 'external' => 4, 'orphaned' => 20, 'broken' => 5, 'opportunities' => 60, 'avg_depth' => 3.1],
            ['taken_on' => '2026-06-15', 'posts' => 41, 'internal' => 30, 'external' => 5, 'orphaned' => 11, 'broken' => 2, 'opportunities' => 35, 'avg_depth' => 2.4],
            ['taken_on' => '2026-07-01', 'posts' => 42, 'internal' => 58, 'external' => 6, 'orphaned' => 3,  'broken' => 0, 'opportunities' => 12, 'avg_depth' => 1.9],
        ];
    }

    protected function trend_for($rows, $key)
    {
        foreach (Slk_History::compute_trends($rows) as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------------
     * What "better" means
     * ------------------------------------------------------------------ */

    public function test_more_internal_links_is_good()
    {
        $t = $this->trend_for($this->improving(), 'internal');
        $this->assertSame(48.0, $t['change']);
        $this->assertTrue($t['good']);
    }

    /**
     * The one that matters most. Every other metric here is good when it
     * falls; internal links are the exception, and a chart that treats all
     * four the same would tell you the site is improving while orphans climb.
     */
    public function test_more_orphans_is_bad_even_though_it_is_a_rise()
    {
        $rows = $this->improving();
        $rows[2]['orphaned'] = 44; // orphans climbed instead

        $orphans = $this->trend_for($rows, 'orphaned');
        $internal = $this->trend_for($rows, 'internal');

        $this->assertSame(24.0, $orphans['change']);
        $this->assertFalse($orphans['good'], 'a rise in orphans must not read as an improvement');
        $this->assertTrue($internal['good'], 'a rise in internal links must still read as an improvement');
    }

    public function test_fewer_broken_links_is_good()
    {
        $t = $this->trend_for($this->improving(), 'broken');
        $this->assertSame(-5.0, $t['change']);
        $this->assertTrue($t['good']);
    }

    public function test_more_broken_links_is_bad()
    {
        $rows = $this->improving();
        $rows[2]['broken'] = 9;
        $t = $this->trend_for($rows, 'broken');
        $this->assertSame(4.0, $t['change']);
        $this->assertFalse($t['good']);
    }

    /**
     * No movement is neither good nor bad. Without the null a stationary
     * metric would inherit whichever branch the comparison happened to take
     * and show a confident tick or cross for a number that never changed.
     */
    public function test_no_movement_is_neither_good_nor_bad()
    {
        $rows = $this->improving();
        foreach ($rows as $i => $r) {
            $rows[$i]['broken'] = 3;
        }
        $t = $this->trend_for($rows, 'broken');
        $this->assertSame(0.0, $t['change']);
        $this->assertNull($t['good']);
    }

    public function test_change_is_measured_across_the_whole_window()
    {
        // Not first-to-second or second-to-last: oldest reading to newest.
        $t = $this->trend_for($this->improving(), 'opportunities');
        $this->assertSame(60.0, $t['then']);
        $this->assertSame(12.0, $t['now']);
        $this->assertSame(-48.0, $t['change']);
    }

    public function test_all_four_metrics_are_reported()
    {
        $keys = array_column(Slk_History::compute_trends($this->improving()), 'key');
        $this->assertSame(['internal', 'orphaned', 'broken', 'opportunities'], $keys);
    }

    public function test_no_rows_means_no_trends()
    {
        $this->assertSame([], Slk_History::compute_trends([]));
    }

    /**
     * A single reading is not a trend, but it must not crash — the caller
     * decides whether to draw it, and the tiles fall back to showing the value
     * with a zero change.
     */
    public function test_a_single_reading_reports_zero_change()
    {
        $rows = [$this->improving()[0]];
        $t = $this->trend_for($rows, 'internal');
        $this->assertSame(10.0, $t['now']);
        $this->assertSame(10.0, $t['then']);
        $this->assertSame(0.0, $t['change']);
        $this->assertNull($t['good']);
    }

    /* ---------------------------------------------------------------------
     * Sparklines
     * ------------------------------------------------------------------ */

    public function test_sparkline_needs_at_least_two_points()
    {
        $this->assertSame('', Slk_History::spark([]));
        $this->assertSame('', Slk_History::spark([7]));
    }

    public function test_sparkline_plots_one_point_per_reading()
    {
        $svg = Slk_History::spark([1, 4, 9, 16], true);
        preg_match('/points="([^"]+)"/', $svg, $m);
        $this->assertCount(4, explode(' ', $m[1]));
    }

    /**
     * A rising series must go UP the screen. SVG y grows downward, so getting
     * this backwards draws every improvement as a decline and the mistake is
     * invisible in the markup.
     */
    public function test_sparkline_puts_larger_values_higher()
    {
        $svg = Slk_History::spark([1, 50], true);
        preg_match('/points="([^"]+)"/', $svg, $m);
        [$first, $last] = array_map(function ($p) {
            return (float) explode(',', $p)[1];
        }, explode(' ', $m[1]));

        $this->assertGreaterThan($last, $first, 'the higher value must sit nearer the top');
    }

    /**
     * A series that never moves has no range to scale against. Dividing by it
     * would be a fatal error or a NaN in the markup; the line is drawn flat
     * through the middle instead.
     */
    public function test_a_flat_series_does_not_divide_by_zero()
    {
        $svg = Slk_History::spark([12, 12, 12, 12]);
        $this->assertStringNotContainsString('NAN', strtoupper($svg));
        preg_match('/points="([^"]+)"/', $svg, $m);

        $ys = array_map(function ($p) {
            return (float) explode(',', $p)[1];
        }, explode(' ', $m[1]));
        $this->assertSame([$ys[0]], array_values(array_unique($ys)), 'a flat series draws a flat line');
    }

    public function test_sparkline_colour_follows_the_verdict()
    {
        $this->assertStringContainsString('#00844a', Slk_History::spark([1, 2], true));
        $this->assertStringContainsString('#b32d2e', Slk_History::spark([1, 2], false));
        $this->assertStringContainsString('#8c8f94', Slk_History::spark([1, 2], null));
    }

    /* ---------------------------------------------------------------------
     * The full chart
     * ------------------------------------------------------------------ */

    public function test_chart_needs_at_least_two_readings()
    {
        $this->assertSame('', Slk_History::chart([$this->improving()[0]], 'internal'));
    }

    public function test_chart_marks_every_reading_but_labels_only_three_dates()
    {
        // Ten readings, so "one label per point" and "three labels" differ.
        $rows = [];
        for ($d = 1; $d <= 10; $d++) {
            $rows[] = [
                'taken_on' => sprintf('2026-06-%02d', $d),
                'posts' => 40, 'internal' => $d * 3, 'external' => 4,
                'orphaned' => 20 - $d, 'broken' => 0, 'opportunities' => 30, 'avg_depth' => 2,
            ];
        }
        $svg = Slk_History::chart($rows, 'internal', true);

        $this->assertSame(10, substr_count($svg, '<circle'), 'every reading gets a dot');
        // First, middle and last only — otherwise a long series prints a row
        // of overlapping dates along the bottom.
        $this->assertSame(3, substr_count($svg, ' 2026</text>'));
    }

    public function test_chart_scale_starts_at_zero_when_the_values_are_near_it()
    {
        // 10 → 58 is most of the way from zero, so the axis should include it;
        // starting at 10 would exaggerate the climb.
        $svg = Slk_History::chart($this->improving(), 'internal', true);
        $this->assertStringContainsString('>0</text>', $svg);
    }

    public function test_chart_zooms_in_when_the_values_sit_far_from_zero()
    {
        $rows = $this->improving();
        foreach ([980, 1000, 1020] as $i => $v) {
            $rows[$i]['internal'] = $v;
        }
        $svg = Slk_History::chart($rows, 'internal', true);
        // A 4% move plotted against a zero baseline would be a flat line.
        $this->assertStringNotContainsString('>0</text>', $svg);
    }

    public function test_chart_survives_a_completely_flat_series()
    {
        $rows = $this->improving();
        foreach ($rows as $i => $r) {
            $rows[$i]['broken'] = 0;
        }
        $svg = Slk_History::chart($rows, 'broken', null);
        $this->assertStringNotContainsString('NAN', strtoupper($svg));
        $this->assertStringContainsString('<polyline', $svg);
    }

    public function test_chart_tooltip_names_the_date_and_the_value()
    {
        $svg = Slk_History::chart($this->improving(), 'orphaned', true);
        $this->assertStringContainsString('<title>1 Jun 2026 — 20</title>', $svg);
    }
}
