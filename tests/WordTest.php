<?php

use PHPUnit\Framework\TestCase;

/**
 * Tokenizing and phrase matching.
 *
 * Several of these encode bugs that shipped. The hyphen cases in particular:
 * "pour-over" was tokenized as a single token, so the phrase "pour over
 * coffee" never matched text that wrote it hyphenated, and a correct
 * suggestion was silently dropped. Both the tokenizer and the needle splitter
 * had to change, so both directions are pinned here.
 *
 * find() returns the ORIGINAL substring rather than the phrase it was asked
 * for, which is what lets an inserted anchor keep the author's own
 * capitalization. That is behaviour worth protecting, so it is asserted too.
 */
class WordTest extends TestCase
{
    /** @return array token structs as tokenize() produces them */
    private function toks($text)
    {
        return Slk_Word::tokenize($text);
    }

    private function words($text)
    {
        return array_column(Slk_Word::tokenize($text), 'o');
    }

    public function test_tokenize_splits_on_whitespace()
    {
        $this->assertSame(['Good', 'espresso', 'starts', 'here'], $this->words('Good espresso starts here'));
    }

    public function test_tokenize_records_offsets_and_stems()
    {
        $t = $this->toks('Burr grinders');
        $this->assertSame('Burr', $t[0]['o']);
        $this->assertSame(0, $t[0]['start']);
        $this->assertSame(4, $t[0]['len']);
        $this->assertSame(Slk_Word::stem('grinders'), $t[1]['s']);
    }

    /**
     * The bug: a hyphen used to be a word character, so "pour-over" was one
     * token and never matched the phrase "pour over".
     */
    public function test_tokenize_splits_hyphenated_words()
    {
        $this->assertSame(['pour', 'over', 'coffee'], $this->words('pour-over coffee'));
    }

    public function test_tokenize_keeps_apostrophes_inside_a_word()
    {
        $this->assertSame(["barista's", 'choice'], $this->words("barista's choice"));
    }

    public function test_tokenize_drops_punctuation()
    {
        $this->assertSame(['Hello', 'world'], $this->words('Hello, world!'));
    }

    public function test_tokenize_handles_empty_input()
    {
        $this->assertSame([], Slk_Word::tokenize(''));
        $this->assertSame([], Slk_Word::tokenize('   '));
    }

    public function test_find_locates_a_simple_phrase()
    {
        $plain = 'we recommend a burr grinder for espresso';
        $this->assertSame('burr grinder', Slk_Word::find($plain, $this->toks($plain), 'burr grinder', false));
    }

    public function test_find_returns_null_when_absent()
    {
        $plain = 'we recommend a blade grinder';
        $this->assertNull(Slk_Word::find($plain, $this->toks($plain), 'burr grinder', false));
    }

    /**
     * The returned string is the author's text, not the phrase we searched
     * for — this is what keeps an inserted anchor reading naturally.
     */
    public function test_find_returns_the_original_casing()
    {
        $plain = 'We sell a Burr Grinder here';
        $this->assertSame('Burr Grinder', Slk_Word::find($plain, $this->toks($plain), 'burr grinder', false));
    }

    /** The other half of the hyphen bug: the needle must split the same way. */
    public function test_find_matches_a_hyphenated_needle_against_spaced_text()
    {
        $plain = 'the pour over method is slow';
        $this->assertSame('pour over method', Slk_Word::find($plain, $this->toks($plain), 'pour-over method', false));
    }

    public function test_find_matches_a_spaced_needle_against_hyphenated_text()
    {
        $plain = 'the pour-over method is slow';
        $this->assertSame('pour-over method', Slk_Word::find($plain, $this->toks($plain), 'pour over method', false));
    }

    public function test_find_matches_across_an_en_dash()
    {
        $plain = 'a light–medium roast';
        $this->assertNotNull(Slk_Word::find($plain, $this->toks($plain), 'light medium roast', false));
    }

    public function test_find_respects_word_boundaries()
    {
        // "grind" must not match inside "grinder" when stemming is off.
        $plain = 'we sell a grinder';
        $this->assertNull(Slk_Word::find($plain, $this->toks($plain), 'grind', false));
    }

    public function test_find_with_stemming_matches_a_plural()
    {
        $plain = 'we sell burr grinders here';
        $this->assertSame('burr grinders', Slk_Word::find($plain, $this->toks($plain), 'burr grinder', true));
    }

    public function test_find_without_stemming_does_not_match_a_plural()
    {
        $plain = 'we sell burr grinders here';
        $this->assertNull(Slk_Word::find($plain, $this->toks($plain), 'burr grinder', false));
    }

    public function test_find_matches_the_first_occurrence()
    {
        $plain = 'a grinder, then another grinder';
        $this->assertSame(0, strpos($plain, Slk_Word::find($plain, $this->toks($plain), 'a grinder', false)));
    }

    public function test_find_handles_an_empty_phrase()
    {
        $plain = 'anything at all';
        $this->assertNull(Slk_Word::find($plain, $this->toks($plain), '', false));
        $this->assertNull(Slk_Word::find($plain, $this->toks($plain), '   ', false));
    }

    public function test_stem_folds_common_inflections()
    {
        $this->assertSame(Slk_Word::stem('grinders'), Slk_Word::stem('grinder'));
    }

    public function test_stem_is_case_insensitive()
    {
        $this->assertSame(Slk_Word::stem('Grinder'), Slk_Word::stem('grinder'));
    }

    public function test_stem_leaves_non_ascii_words_alone()
    {
        $this->assertSame('café', Slk_Word::stem('Café'));
    }
}
