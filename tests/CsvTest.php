<?php

use PHPUnit\Framework\TestCase;

require_once SLK_PLUGIN_DIR . 'core/Slk/CSV.php';

/**
 * CSV export.
 *
 * The streaming and query halves need WordPress and $wpdb and are out of scope
 * for this suite. What is tested here is `defuse()`, because it is the part
 * where being quietly wrong is a security bug rather than a cosmetic one: a
 * post title is content, content can begin with "=", and Excel and Sheets read
 * a leading "=" as a formula. The failure does not show up on the site — it
 * shows up in whatever spreadsheet the export is opened in, possibly on
 * someone else's machine.
 */
class CsvTest extends TestCase
{
    /**
     * The attack this exists to stop: a post titled with a formula becomes a
     * live formula in the exported sheet.
     */
    public function test_a_formula_cannot_survive_as_the_first_character()
    {
        $attack = '=HYPERLINK("http://example.invalid/steal","Click me")';
        $this->assertSame("'" . $attack, Slk_CSV::defuse($attack));
    }

    /**
     * All five leaders, not just "=". Sheets and Excel each treat a different
     * subset as formula starts, so blocking only the obvious one leaves the
     * hole open in whichever application the file lands in.
     */
    public function test_every_formula_leader_is_neutralised()
    {
        foreach (['=cmd', '+1+1', '-1+1', '@SUM(A1)', "\tSUM", "\rSUM"] as $bad) {
            $this->assertSame("'" . $bad, Slk_CSV::defuse($bad), "unescaped: " . json_encode($bad));
        }
    }

    /**
     * A negative number is not a formula. Prefixing it would turn a numeric
     * column into text and break every SUM in the sheet — the export would be
     * safe and useless.
     */
    public function test_negative_numbers_stay_numbers()
    {
        $this->assertSame('-5', Slk_CSV::defuse('-5'));
        $this->assertSame('-0.25', Slk_CSV::defuse('-0.25'));
        $this->assertSame(-42, Slk_CSV::defuse(-42));
        $this->assertSame(-1.5, Slk_CSV::defuse(-1.5));
    }

    public function test_ordinary_content_is_untouched()
    {
        foreach (['spaza shop', 'JoJo tank prices', '', 'a=b', 'R1 200', '2026-08-04'] as $ok) {
            $this->assertSame($ok, Slk_CSV::defuse($ok));
        }
    }

    /** Numeric types pass through unchanged so the columns stay numeric. */
    public function test_numeric_types_pass_through()
    {
        $this->assertSame(7, Slk_CSV::defuse(7));
        $this->assertSame(0.5, Slk_CSV::defuse(0.5));
        $this->assertNull(Slk_CSV::defuse(null));
        $this->assertTrue(Slk_CSV::defuse(true));
    }

    /**
     * Only the FIRST character matters. Escaping mid-string equals signs would
     * mangle ordinary anchor text like "price = value" for no benefit.
     */
    public function test_only_the_leading_character_is_considered()
    {
        $this->assertSame('price = value', Slk_CSV::defuse('price = value'));
        $this->assertSame('2 + 2 is 4', Slk_CSV::defuse('2 + 2 is 4'));
    }

    /* ---------------------------------------------------------------------
     * PHP 8.4 forward compatibility
     * ------------------------------------------------------------------ */

    /**
     * fputcsv() and fgetcsv() must be called with an explicit $escape.
     *
     * PHP 8.4 deprecates omitting it because the default changes in PHP 9.
     * That matters more here than a normal deprecation: with display_errors
     * on, the notice is written INTO the download, before the header row, and
     * the spreadsheet opens as gibberish. It shipped that way for a while
     * precisely because a browser saves a CSV rather than showing it, so the
     * corruption is invisible unless you open the file.
     */
    public function test_csv_functions_pass_an_explicit_escape()
    {
        $files = array_merge(
            glob(SLK_PLUGIN_DIR . 'core/Slk/*.php'),
            [SLK_PLUGIN_DIR . 'uninstall.php']
        );

        $this->assertGreaterThan(10, count($files), 'file list looks wrong');

        $bare = [];
        foreach ($files as $file) {
            /*
             * Comments are stripped with PHP's own tokenizer before scanning.
             * The docblock above CSV_ESCAPE explains the rule and names
             * fputcsv() while doing so — a plain text search reported those
             * sentences as violations. A guard that flags its own
             * documentation is one people switch off.
             */
            $src = '';
            foreach (token_get_all(file_get_contents($file)) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $src .= $token[1];
                } else {
                    $src .= $token;
                }
            }

            $offset = 0;
            while (preg_match('/\bf(?:put|get)csv\s*\(/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $open = $m[0][1] + strlen($m[0][0]);
                $offset = $open;

                /*
                 * Walk the argument list counting commas at DEPTH ZERO only.
                 * A naive comma count is fooled by nested calls — the first
                 * version of this test counted the commas inside
                 * array_map([...], $row) and concluded a two-argument call had
                 * five, so it passed on exactly the code it was written to
                 * catch. Verified since by reverting the call and watching it
                 * fail.
                 */
                $depth = 1;
                $args = 1;
                $len = strlen($src);
                for ($i = $open; $i < $len && $depth > 0; $i++) {
                    $c = $src[$i];
                    if ($c === '(' || $c === '[') {
                        $depth++;
                    } elseif ($c === ')' || $c === ']') {
                        $depth--;
                    } elseif ($c === ',' && $depth === 1) {
                        $args++;
                    }
                }

                if ($args < 5) {
                    $call = trim(preg_replace('/\s+/', ' ', substr($src, $m[0][1], min(90, $i - $m[0][1]))));
                    $bare[] = basename($file) . ': ' . $call;
                }
            }
        }

        $this->assertSame(
            [],
            $bare,
            "These call a CSV function without an explicit \$escape. On PHP 8.4 that emits a "
            . "deprecation, and for exports the notice lands inside the downloaded file:\n  "
            . implode("\n  ", $bare)
        );
    }

    /**
     * The export must discard buffered output before it streams.
     *
     * Fixing the deprecation above removes today's cause, but any future
     * notice from any plugin would corrupt a download the same way. Clearing
     * the buffer makes that class of failure impossible rather than fixed
     * once.
     */
    public function test_stream_clears_output_before_sending_the_file()
    {
        $src = file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/CSV.php');
        $stream = substr($src, strpos($src, 'protected static function stream('));
        $stream = substr($stream, 0, strpos($stream, "\n    }"));

        $this->assertStringContainsString('ob_end_clean', $stream, 'stream() must discard buffered output');
        $this->assertLessThan(
            strpos($stream, "header('Content-Type: text/csv"),
            strpos($stream, 'ob_end_clean'),
            'the buffer must be cleared BEFORE the headers are sent'
        );
    }

    /* ---------------------------------------------------------------------
     * The registry
     * ------------------------------------------------------------------ */

    /**
     * Every dataset must name a method that exists. The registry replaced a
     * switch precisely so a new report cannot ship a button that leads to a
     * silent no-op, and that guarantee is worth a test.
     */
    public function test_every_dataset_maps_to_a_real_method()
    {
        foreach (Slk_CSV::datasets() as $type => [$capability, $method]) {
            $this->assertTrue(
                method_exists('Slk_CSV', $method),
                "dataset '{$type}' names a missing method '{$method}'"
            );
            $this->assertNotSame('', $capability, "dataset '{$type}' has no capability");
        }
    }

    /**
     * Auto-link rules are the one dataset that writes as well as reads, so it
     * needs a stronger capability than the read-only reports.
     */
    public function test_autolink_rules_need_a_higher_capability_than_the_reports()
    {
        $sets = Slk_CSV::datasets();
        $this->assertSame('manage_categories', $sets['autolinks'][0]);
        foreach (['report', 'broken', 'anchors', 'equity', 'cannibal', 'placement', 'domains', 'trends'] as $t) {
            $this->assertSame('edit_posts', $sets[$t][0], "{$t} should be readable by an editor");
        }
    }

    public function test_the_reports_built_later_all_have_an_export()
    {
        $types = array_keys(Slk_CSV::datasets());
        foreach (['anchors', 'equity', 'cannibal', 'placement', 'domains', 'trends'] as $t) {
            $this->assertContains($t, $types);
        }
    }
}
