<?php

use PHPUnit\Framework\TestCase;

/**
 * Link equity (PageRank) and click depth (BFS).
 *
 * Pure graph maths with no database, which makes it exactly the kind of code
 * that can drift silently: a wrong damping term or a mishandled dangling node
 * still produces plausible-looking numbers. So the textbook graph is pinned to
 * its published values, and the invariant that matters — total value is
 * conserved — is asserted on every shape.
 */
class EquityTest extends TestCase
{
    private function graph(array $nodes, array $out)
    {
        $in = [];
        foreach ($out as $from => $targets) {
            foreach ($targets as $to) {
                $in[$to][] = $from;
            }
        }
        return ['nodes' => array_fill_keys($nodes, 'x'), 'out' => $out, 'in' => $in];
    }

    /* -- PageRank ---------------------------------------------------- */

    /**
     * The canonical three-node example: A→B, A→C, B→C, C→A. Published values
     * for d=0.85 are A 0.3878, B 0.2148, C 0.3974.
     */
    public function test_matches_the_textbook_three_node_graph()
    {
        $g = $this->graph([1, 2, 3], [1 => [2, 3], 2 => [3], 3 => [1]]);
        $r = Slk_Equity::pagerank($g);

        $this->assertEqualsWithDelta(0.3878, $r[1], 0.0005);
        $this->assertEqualsWithDelta(0.2148, $r[2], 0.0005);
        $this->assertEqualsWithDelta(0.3974, $r[3], 0.0005);
    }

    public function test_total_value_is_always_one()
    {
        $shapes = [
            'chain'     => $this->graph([1, 2, 3, 4], [1 => [2], 2 => [3], 3 => [4]]),
            'star'      => $this->graph([1, 2, 3, 4], [2 => [1], 3 => [1], 4 => [1]]),
            'cycle'     => $this->graph([1, 2, 3], [1 => [2], 2 => [3], 3 => [1]]),
            'no links'  => $this->graph([1, 2, 3], []),
            'one node'  => $this->graph([1], []),
        ];
        foreach ($shapes as $name => $g) {
            $this->assertEqualsWithDelta(1.0, array_sum(Slk_Equity::pagerank($g)), 0.0001, $name . ' leaked value');
        }
    }

    /**
     * A page with no outbound links must not swallow value out of the system.
     */
    public function test_a_dangling_node_does_not_leak_value()
    {
        $g = $this->graph([1, 2, 3, 4], [1 => [2], 2 => [3], 3 => [4]]);
        $r = Slk_Equity::pagerank($g);
        $this->assertEqualsWithDelta(1.0, array_sum($r), 0.0001);
        $this->assertGreaterThan($r[1], $r[4], 'the sink should hold the most');
    }

    public function test_an_unlinked_site_shares_value_evenly()
    {
        $g = $this->graph([1, 2, 3, 4], []);
        $r = Slk_Equity::pagerank($g);
        foreach ($r as $v) {
            $this->assertEqualsWithDelta(0.25, $v, 0.0001);
        }
    }

    public function test_more_inbound_links_means_more_value()
    {
        // 2 and 3 both point at 1; nothing points at 4.
        $g = $this->graph([1, 2, 3, 4], [2 => [1], 3 => [1]]);
        $r = Slk_Equity::pagerank($g);
        $this->assertGreaterThan($r[4], $r[1]);
    }

    /**
     * The recursive part, and the whole reason this beats counting inbound
     * links: one link from a strong page beats two from weak ones.
     */
    public function test_a_link_from_a_strong_page_is_worth_more()
    {
        // 1 is strong — three pages point at it — and links only to 2.
        // 3 and 4 are weak (nothing points at them) and both link to 5.
        $g = $this->graph([1, 2, 3, 4, 5, 8, 9, 10], [
            8 => [1], 9 => [1], 10 => [1],
            1 => [2],
            3 => [5], 4 => [5],
        ]);
        $r = Slk_Equity::pagerank($g);
        $this->assertGreaterThan($r[5], $r[2], 'one link from a strong page should beat two from weak ones');
    }

    public function test_an_empty_graph_returns_nothing()
    {
        $this->assertSame([], Slk_Equity::pagerank(['nodes' => [], 'out' => [], 'in' => []]));
    }

    /* -- Depth -------------------------------------------------------- */

    public function test_depth_counts_clicks_from_the_seed()
    {
        $g = $this->graph([1, 2, 3, 4, 5, 6], [1 => [2, 5], 2 => [3], 3 => [4], 4 => [2]]);
        $d = Slk_Equity::depths($g, [1]);

        $this->assertSame(0, $d[1]);
        $this->assertSame(1, $d[2]);
        $this->assertSame(2, $d[3]);
        $this->assertSame(3, $d[4]);
        $this->assertSame(1, $d[5]);
        $this->assertArrayNotHasKey(6, $d, 'nothing links to 6');
    }

    public function test_depth_takes_the_shortest_route()
    {
        // 4 is reachable both directly and via a three-step chain.
        $g = $this->graph([1, 2, 3, 4], [1 => [2, 4], 2 => [3], 3 => [4]]);
        $d = Slk_Equity::depths($g, [1]);
        $this->assertSame(1, $d[4]);
    }

    public function test_a_cycle_does_not_hang()
    {
        $g = $this->graph([1, 2, 3], [1 => [2], 2 => [3], 3 => [1]]);
        $d = Slk_Equity::depths($g, [1]);
        $this->assertSame([1 => 0, 2 => 1, 3 => 2], $d);
    }

    public function test_multiple_seeds_all_start_at_zero()
    {
        $g = $this->graph([1, 2, 3, 4], [1 => [3], 2 => [4]]);
        $d = Slk_Equity::depths($g, [1, 2]);
        $this->assertSame(0, $d[1]);
        $this->assertSame(0, $d[2]);
        $this->assertSame(1, $d[3]);
        $this->assertSame(1, $d[4]);
    }

    public function test_no_seeds_means_nothing_is_reachable()
    {
        $g = $this->graph([1, 2, 3], [1 => [2], 2 => [3]]);
        $this->assertSame([], Slk_Equity::depths($g, []));
    }
}
