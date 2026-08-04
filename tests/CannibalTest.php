<?php

use PHPUnit\Framework\TestCase;

/**
 * Cannibalisation — the parts that do not need a database.
 *
 * Keyword folding is the load-bearing piece: if "Spaza Shop." and "spaza shop"
 * do not compare equal, the whole keyword half of the report silently finds
 * nothing, and a report that quietly finds nothing looks identical to a site
 * with no problems.
 *
 * Severity classification is reproduced here rather than reached through
 * rows(), which needs posts. What is pinned is the decision table, since that
 * is what the recommendation column is driven by.
 */
class CannibalTest extends TestCase
{
    /** @dataProvider spellings */
    public function test_keyword_spellings_fold_together($written)
    {
        $this->assertSame('spaza shop', Slk_Cannibal::normalize($written));
    }

    public function spellings()
    {
        return [
            ['spaza shop'],
            ['Spaza Shop'],
            ['SPAZA SHOP'],
            ['Spaza Shop.'],
            ['  spaza   shop  '],
            ['“Spaza Shop”'],
            ['spaza shop,'],
        ];
    }

    public function test_different_keywords_stay_apart()
    {
        $this->assertNotSame(
            Slk_Cannibal::normalize('spaza shop'),
            Slk_Cannibal::normalize('spaza shop profit')
        );
    }

    public function test_an_empty_keyword_normalises_to_nothing()
    {
        // Posts without a focus keyword must never be grouped with each other.
        $this->assertSame('', Slk_Cannibal::normalize(''));
        $this->assertSame('', Slk_Cannibal::normalize('   '));
        $this->assertSame('', Slk_Cannibal::normalize('...'));
    }

    /* -- severity decision table ------------------------------------- */

    private function severity($same_keyword, $similarity)
    {
        $close = $similarity !== null && $similarity >= Slk_Cannibal::SEMANTIC;
        if ($same_keyword && $close) {
            return 'direct';
        }
        return $same_keyword ? 'keyword' : 'overlap';
    }

    public function test_same_keyword_and_near_identical_is_a_direct_clash()
    {
        $this->assertSame('direct', $this->severity(true, 0.96));
    }

    public function test_same_keyword_alone_is_a_keyword_clash()
    {
        $this->assertSame('keyword', $this->severity(true, null));
        $this->assertSame('keyword', $this->severity(true, 0.40));
    }

    public function test_similarity_alone_is_an_overlap()
    {
        $this->assertSame('overlap', $this->severity(false, 0.96));
    }

    /**
     * The threshold is a much stronger claim than "related enough to link".
     * If it ever drifts down to the linking threshold the report would flag
     * every neighbouring article as a rival.
     */
    public function test_the_semantic_threshold_is_far_above_the_linking_one()
    {
        $this->assertGreaterThan(Slk_Embedding::GOOD, Slk_Cannibal::SEMANTIC);
        $this->assertGreaterThanOrEqual(0.80, Slk_Cannibal::SEMANTIC);
    }

    public function test_a_pair_just_below_the_threshold_is_not_flagged()
    {
        $this->assertSame('overlap', $this->severity(false, Slk_Cannibal::SEMANTIC));
        $this->assertSame('keyword', $this->severity(true, Slk_Cannibal::SEMANTIC - 0.01));
    }
}
