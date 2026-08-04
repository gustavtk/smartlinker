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
