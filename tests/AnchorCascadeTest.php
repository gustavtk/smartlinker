<?php

use PHPUnit\Framework\TestCase;

/**
 * The anchor cascade — the part of the plugin that decides what words become
 * a link, and therefore the part that decides whether suggestions are good.
 *
 * This is the logic behind the failure that prompted the rewrite: anchors
 * like "equipment", "municipal" and "documents" pointing at unrelated pages,
 * because anchors were drawn from the target's body vocabulary rather than
 * from what the target IS. The cascade now works down four phases and returns
 * which one fired, so these tests assert the ORDER, not just the result.
 *
 *   1. exact keyword    — the target's focus keyword, verbatim
 *   2. partial keyword  — the longest 2+ word run of it
 *   3. title phrase     — words from the title, padding trimmed
 *   4. salient word     — a single distinctive word, last resort
 *
 * pick_anchor() is protected, so it is reached by reflection rather than by
 * loosening the real class's visibility for a test's convenience.
 */
class AnchorCascadeTest extends TestCase
{
    /**
     * @param string $body     the source post's text, where the anchor must appear
     * @param string $title    the target post's title
     * @param string $kw       the target's focus keyword, if any
     * @param array  $existing anchors already used, keyed by lowercase text
     * @return array{0:string,1:string}|null [anchor, tier] or null
     */
    private function pick($body, $title, $kw = '', $use_stem = true, array $existing = [], $min_len = 3)
    {
        $m = new ReflectionMethod('Slk_Suggestion', 'pick_anchor');
        $m->setAccessible(true);

        return $m->invoke(
            null,
            $body,
            Slk_Word::tokenize($body),
            new Slk_Test_Post(99, $title),
            $kw,
            $use_stem,
            $existing,
            $min_len
        );
    }

    private function anchorOf($result)
    {
        return $result === null ? null : $result[0];
    }

    private function tierOf($result)
    {
        return $result === null ? null : $result[1];
    }

    /* -----------------------------------------------------------------
     * Phase order.
     * ----------------------------------------------------------------- */

    public function test_phase_1_prefers_the_exact_focus_keyword()
    {
        $r = $this->pick(
            'A good burr grinder matters more than the machine.',
            'Choosing a Burr Grinder',
            'burr grinder'
        );
        $this->assertSame('exact keyword', $this->tierOf($r));
        $this->assertSame('burr grinder', strtolower($this->anchorOf($r)));
    }

    public function test_the_keyword_beats_the_title_when_both_are_present()
    {
        $r = $this->pick(
            'We compare espresso machines and the burr grinder we use.',
            'Espresso Machines Compared',
            'burr grinder'
        );
        $this->assertSame('exact keyword', $this->tierOf($r));
    }

    public function test_phase_2_uses_part_of_the_keyword_when_the_whole_is_absent()
    {
        // Full keyword "best burr grinder 2026" is not in the text; a run of it is.
        $r = $this->pick(
            'We tested the burr grinder for a month.',
            'Grinder Reviews',
            'best burr grinder 2026'
        );
        $this->assertSame('partial keyword', $this->tierOf($r));
        $this->assertSame('burr grinder', strtolower($this->anchorOf($r)));
    }

    public function test_phase_3_falls_back_to_the_title_when_no_keyword_matches()
    {
        $r = $this->pick(
            'Making pour over coffee takes patience.',
            'How to Make Pour Over Coffee'
        );
        $this->assertSame('title phrase', $this->tierOf($r));
        $this->assertStringContainsStringIgnoringCase('pour over coffee', $this->anchorOf($r));
    }

    /* -----------------------------------------------------------------
     * Weak-word handling in the title. Both of these are regressions.
     * ----------------------------------------------------------------- */

    /**
     * The bug: the extractor split on weak words, so "How to Make Pour Over
     * Coffee" produced nothing — "over" is a stop word, and splitting there
     * left only fragments. Weak words are trimmed from the ENDS and never
     * split a phrase down the middle.
     */
    public function test_a_stop_word_inside_the_title_does_not_split_the_phrase()
    {
        $r = $this->pick(
            'We wrote about pour over coffee last week.',
            'How to Make Pour Over Coffee'
        );
        $this->assertNotNull($r, 'a stop word inside the title must not destroy the phrase');
        $this->assertStringContainsStringIgnoringCase('over', $this->anchorOf($r));
    }

    public function test_padding_words_are_trimmed_from_the_ends_of_a_title()
    {
        $r = $this->pick(
            'Everything about espresso brewing is covered here.',
            'The Complete Guide to Espresso Brewing'
        );
        $this->assertNotNull($r);
        $anchor = strtolower($this->anchorOf($r));
        $this->assertStringNotContainsString('complete', $anchor);
        $this->assertStringNotContainsString('guide', $anchor);
        $this->assertStringContainsString('espresso', $anchor);
    }

    public function test_a_title_of_nothing_but_padding_yields_no_anchor()
    {
        $r = $this->pick(
            'The ultimate guide is here for you.',
            'The Ultimate Guide'
        );
        $this->assertNull($r, 'a title with no content words must not produce an anchor');
    }

    /* -----------------------------------------------------------------
     * The gate — what must never become a link.
     * ----------------------------------------------------------------- */

    public function test_an_unrelated_post_produces_no_anchor_at_all()
    {
        $r = $this->pick(
            'This article is entirely about municipal water rates.',
            'Choosing a Burr Grinder',
            'burr grinder'
        );
        $this->assertNull($r, 'an unrelated post must produce no anchor');
    }

    public function test_never_returns_a_bare_stop_word()
    {
        $r = $this->pick('It is here and it is for you and this is that.', 'The And Of It');
        if ($r !== null) {
            $this->assertArrayNotHasKey(
                strtolower($this->anchorOf($r)),
                Slk_Suggestion::weak_words()
            );
        }
        $this->assertTrue(true); // null is the expected and acceptable outcome
    }

    public function test_respects_the_minimum_length()
    {
        $r = $this->pick('We add ice at the end.', 'Ice', 'ice', true, [], 8);
        $this->assertNull($r);
    }

    public function test_skips_an_anchor_already_used_in_this_post()
    {
        $r = $this->pick(
            'A good burr grinder matters more than the machine.',
            'Choosing a Burr Grinder',
            'burr grinder',
            true,
            ['burr grinder' => true]
        );
        $this->assertNotSame('burr grinder', strtolower((string) $this->anchorOf($r)));
    }

    /* -----------------------------------------------------------------
     * Invariants the insertion step depends on.
     * ----------------------------------------------------------------- */

    public function test_matches_a_plural_in_the_text_when_stemming_is_on()
    {
        $r = $this->pick(
            'We tested several burr grinders this month.',
            'Choosing a Burr Grinder',
            'burr grinder',
            true
        );
        $this->assertNotNull($r);
        $this->assertStringContainsStringIgnoringCase('grinder', $this->anchorOf($r));
    }

    /**
     * Whatever comes back must be findable verbatim in the source, or the
     * insert step cannot place the link. This is the contract between the two.
     */
    public function test_the_anchor_always_exists_verbatim_in_the_source_text()
    {
        $bodies = [
            ['We tested several burr grinders this month.', 'Choosing a Burr Grinder', 'burr grinder'],
            ['Making pour over coffee takes patience.', 'How to Make Pour Over Coffee', ''],
            ['A guide to espresso brewing at home.', 'Espresso Brewing', 'espresso brewing'],
        ];
        foreach ($bodies as [$body, $title, $kw]) {
            $r = $this->pick($body, $title, $kw);
            if ($r === null) {
                continue;
            }
            $this->assertStringContainsString(
                $this->anchorOf($r),
                $body,
                'anchor "' . $this->anchorOf($r) . '" is not present in the source text'
            );
        }
    }

    public function test_every_tier_returned_is_one_the_ui_can_explain()
    {
        // tier_phrase() turns these into the reason line shown to the user, so
        // a tier it does not know about would surface as a blank explanation.
        $known = ['exact keyword', 'partial keyword', 'title phrase', 'salient word'];
        $cases = [
            ['A good burr grinder here.', 'Choosing a Burr Grinder', 'burr grinder'],
            ['We tested the burr grinder.', 'Grinder Reviews', 'best burr grinder 2026'],
            ['Making pour over coffee.', 'How to Make Pour Over Coffee', ''],
        ];
        foreach ($cases as [$body, $title, $kw]) {
            $r = $this->pick($body, $title, $kw);
            if ($r !== null) {
                $this->assertContains($this->tierOf($r), $known);
            }
        }
    }
}
