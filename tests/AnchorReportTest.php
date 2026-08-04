<?php

use PHPUnit\Framework\TestCase;

/**
 * Anchor text report — normalization and the generic-anchor test.
 *
 * The whole report rests on normalize(): if two spellings of the same anchor
 * do not fold together, "Pour Over Method" and "pour over method" appear as
 * two unrelated rows and the ambiguity detection silently misses cases. The
 * database-backed parts (rows(), single_anchor_targets()) are exercised
 * against a real site instead.
 */
class AnchorReportTest extends TestCase
{
    /** @dataProvider pourOverVariants */
    public function test_normalize_folds_spelling_variants($input)
    {
        $this->assertSame('pour over method', Slk_Anchor::normalize($input));
    }

    public function pourOverVariants()
    {
        return [
            'plain'            => ['pour over method'],
            'title case'       => ['Pour Over Method'],
            'shouting'         => ['POUR OVER METHOD'],
            'trailing period'  => ['Pour Over Method.'],
            'trailing comma'   => ['pour over method,'],
            'curly quotes'     => ['“Pour Over Method”'],
            'straight quotes'  => ['"pour over method"'],
            'extra whitespace' => ["  pour   over \n method  "],
            'wrapped in tags'  => ['<em>Pour Over</em> Method'],
            'parenthesised'    => ['(pour over method)'],
            'exclaimed'        => ['Pour Over Method!'],
        ];
    }

    public function test_normalize_returns_empty_for_nothing_useful()
    {
        $this->assertSame('', Slk_Anchor::normalize(''));
        $this->assertSame('', Slk_Anchor::normalize('   '));
        $this->assertSame('', Slk_Anchor::normalize('...'));
        $this->assertSame('', Slk_Anchor::normalize('<span></span>'));
    }

    public function test_normalize_keeps_internal_punctuation()
    {
        // Only the ENDS are stripped — a hyphen or apostrophe inside the
        // phrase is part of how the author wrote it.
        $this->assertSame("barista's pour-over", Slk_Anchor::normalize("Barista's Pour-Over."));
    }

    /** @dataProvider genericAnchors */
    public function test_generic_anchors_are_flagged($anchor)
    {
        $this->assertTrue(Slk_Anchor::is_generic($anchor), "[$anchor] should be flagged");
    }

    public function genericAnchors()
    {
        return [
            ['click here'], ['Click Here'], ['CLICK HERE'], ['click here.'],
            ['read more'], ['Read More'], ['here'], ['this page'],
            ['learn more'], ['continue reading'], ['more info'], ['download'],
        ];
    }

    /** @dataProvider descriptiveAnchors */
    public function test_descriptive_anchors_are_not_flagged($anchor)
    {
        $this->assertFalse(Slk_Anchor::is_generic($anchor), "[$anchor] should NOT be flagged");
    }

    public function descriptiveAnchors()
    {
        return [
            ['burr grinder'],
            ['espresso guide'],
            // The distinction that matters: a bare "read more" says nothing,
            // but "read more about grinders" names its destination.
            ['read more about grinders'],
            ['click here for the grinder guide'],
            ['our pour over method'],
            ['water chemistry'],
        ];
    }

    public function test_an_empty_anchor_is_not_called_generic()
    {
        // Empty is a different problem (an image link, usually) and must not
        // be reported as a writing fault.
        $this->assertFalse(Slk_Anchor::is_generic(''));
        $this->assertFalse(Slk_Anchor::is_generic('   '));
    }

    public function test_counts_summarises_a_row_set()
    {
        $rows = [
            ['uses' => 3, 'generic' => false, 'ambiguous' => true,  'repetitive' => false],
            ['uses' => 12, 'generic' => false, 'ambiguous' => false, 'repetitive' => true],
            ['uses' => 1, 'generic' => true,  'ambiguous' => false, 'repetitive' => false],
            ['uses' => 1, 'generic' => true,  'ambiguous' => false, 'repetitive' => false],
        ];
        $c = Slk_Anchor::counts($rows);

        $this->assertSame(4, $c['unique']);
        $this->assertSame(17, $c['total']);
        $this->assertSame(2, $c['generic']);
        $this->assertSame(1, $c['ambiguous']);
        $this->assertSame(1, $c['repetitive']);
    }

    public function test_counts_handles_an_empty_report()
    {
        $c = Slk_Anchor::counts([]);
        $this->assertSame(0, $c['unique']);
        $this->assertSame(0, $c['total']);
    }
}
