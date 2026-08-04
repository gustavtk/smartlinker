<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Word tools: a compact English stemmer plus phrase matching that can match
 * word variants (plurals, verb tenses) so suggestions catch more relevant
 * links. Matching returns the ORIGINAL substring from the content so the
 * anchor text and subsequent insertion stay exact.
 */
class Slk_Word
{
    /**
     * Reduce a word to a rough stem (simplified Porter). Good enough to unify
     * plurals and common tenses: machines→machin, running→run, linked→link.
     */
    public static function stem($word)
    {
        $w = mb_strtolower($word, 'UTF-8');
        // Keep non-ascii words as-is (stemmer is English-oriented).
        if (!preg_match('/^[a-z][a-z\'-]*$/', $w)) {
            return $w;
        }
        $len = strlen($w);
        if ($len <= 3) {
            return $w;
        }

        // Step 1a: plurals.
        if (self::ends($w, 'sses')) {
            $w = substr($w, 0, -2);            // caresses -> caress
        } elseif (self::ends($w, 'ies')) {
            $w = substr($w, 0, -3) . 'i';       // ponies -> poni
        } elseif (self::ends($w, 'ss')) {
            // keep
        } elseif (self::ends($w, 's')) {
            $w = substr($w, 0, -1);            // cats -> cat
        }

        // Step 1b: -eed / -ed / -ing.
        if (self::ends($w, 'eed')) {
            if (self::measure(substr($w, 0, -3)) > 0) {
                $w = substr($w, 0, -1);        // agreed -> agree
            }
        } elseif (self::ends($w, 'ed') && self::has_vowel(substr($w, 0, -2))) {
            $w = substr($w, 0, -2);
            $w = self::fix_after_1b($w);
        } elseif (self::ends($w, 'ing') && self::has_vowel(substr($w, 0, -3))) {
            $w = substr($w, 0, -3);
            $w = self::fix_after_1b($w);
        }

        // Step 1c: y -> i when a vowel precedes.
        if (self::ends($w, 'y') && self::has_vowel(substr($w, 0, -1))) {
            $w = substr($w, 0, -1) . 'i';
        }

        // Light step for common derivational suffixes.
        if (self::ends($w, 'ly') && strlen($w) > 4) {
            $w = substr($w, 0, -2);
        }

        return $w;
    }

    protected static function fix_after_1b($w)
    {
        if (self::ends($w, 'at') || self::ends($w, 'bl') || self::ends($w, 'iz')) {
            return $w . 'e';                    // troubl -> trouble
        }
        // Undouble a final double consonant (but not l, s, z).
        if (strlen($w) > 2 && $w[strlen($w) - 1] === $w[strlen($w) - 2]
            && !in_array($w[strlen($w) - 1], ['l', 's', 'z'], true)
            && !self::is_vowel($w[strlen($w) - 1])) {
            return substr($w, 0, -1);
        }
        return $w;
    }

    protected static function ends($w, $suffix)
    {
        return substr($w, -strlen($suffix)) === $suffix;
    }

    protected static function is_vowel($c)
    {
        return strpos('aeiou', $c) !== false;
    }

    protected static function has_vowel($s)
    {
        return (bool) preg_match('/[aeiouy]/', $s);
    }

    /**
     * Porter "measure": count of vowel-consonant sequences.
     */
    protected static function measure($s)
    {
        $s = preg_replace('/[^a-z]/', '', $s);
        // Collapse to V/C sequence, then count VC transitions.
        $seq = preg_replace('/[aeiou]+/', 'V', $s);
        $seq = preg_replace('/[^V]+/', 'C', $seq);
        return substr_count($seq, 'VC');
    }

    /**
     * Tokenize text into words, recording byte offsets and stems.
     *
     * @return array of ['o'=>original,'s'=>stem,'start'=>int,'len'=>int]
     */
    public static function tokenize($text)
    {
        // Hyphens separate tokens rather than joining them, so "pour-over"
        // becomes "pour" + "over". Without this a target titled "Pour Over
        // Coffee" can never match body text reading "pour-over coffee", which
        // is how the same idea is usually written. find() splits the needle on
        // hyphens too, so both sides agree. Apostrophes stay inside a word.
        $tokens = [];
        if (preg_match_all('/\p{L}[\p{L}\p{N}\'’]*/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $mm) {
                $tokens[] = [
                    'o'     => $mm[0],
                    's'     => self::stem($mm[0]),
                    'start' => $mm[1],
                    'len'   => strlen($mm[0]),
                ];
            }
        }
        return $tokens;
    }

    /**
     * Find $phrase within pre-tokenized content. When $use_stem is true,
     * matching is stem-based (so variants match). Returns the ORIGINAL matched
     * substring from $plain, or null if not found.
     */
    public static function find($plain, array $tokens, $phrase, $use_stem = true)
    {
        // Split on hyphens as well as spaces, to match how tokenize() works.
        $words = preg_split('/[\s\-–—]+/u', trim($phrase), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return null;
        }
        $needle = [];
        foreach ($words as $pw) {
            $needle[] = $use_stem ? self::stem($pw) : mb_strtolower($pw, 'UTF-8');
        }
        $n = count($needle);
        $count = count($tokens);

        for ($i = 0; $i + $n <= $count; $i++) {
            $match = true;
            for ($j = 0; $j < $n; $j++) {
                $tok = $tokens[$i + $j];
                $val = $use_stem ? $tok['s'] : mb_strtolower($tok['o'], 'UTF-8');
                if ($val !== $needle[$j]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                $start = $tokens[$i]['start'];
                $last = $tokens[$i + $n - 1];
                $end = $last['start'] + $last['len'];
                return substr($plain, $start, $end - $start);
            }
        }
        return null;
    }
}
