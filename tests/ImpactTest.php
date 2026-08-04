<?php

use PHPUnit\Framework\TestCase;

require_once SLK_PLUGIN_DIR . 'core/Slk/Impact.php';
require_once SLK_PLUGIN_DIR . 'core/Slk/Error.php';

/**
 * The impact ranking — which link to add first.
 *
 * The database halves (Search Console rows, equity need) are exercised against
 * a real site. What is pinned here is the shape of the decision, because the
 * whole feature is a judgement about priority: get the weights or the guards
 * wrong and the list still renders perfectly, just recommending the wrong work.
 */
class ImpactTest extends TestCase
{
    protected static function source()
    {
        return file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/Impact.php');
    }

    /**
     * Relevance must stay the dominant term.
     *
     * An irrelevant link is worthless however valuable the destination. Need
     * and demand reorder within relevance; they must not be able to overturn
     * it, or the plugin starts recommending links that do not make sense to a
     * reader.
     */
    public function test_relevance_outweighs_the_other_signals_combined()
    {
        $this->assertGreaterThan(
            Slk_Impact::W_NEED + Slk_Impact::W_DEMAND,
            Slk_Impact::W_RELEVANCE,
            'relevance must outweigh need and demand together'
        );
    }

    public function test_the_weights_sum_to_one()
    {
        $this->assertEqualsWithDelta(
            1.0,
            Slk_Impact::W_RELEVANCE + Slk_Impact::W_NEED + Slk_Impact::W_DEMAND,
            0.0001
        );
    }

    /**
     * Striking distance is page two — where a link can plausibly change the
     * outcome. Page one needs no rescuing; page five will not be rescued.
     */
    public function test_striking_distance_is_page_two()
    {
        $this->assertGreaterThan(10, Slk_Impact::STRIKING_FROM);
        $this->assertLessThanOrEqual(21, Slk_Impact::STRIKING_TO);
        $this->assertLessThan(Slk_Impact::STRIKING_TO, Slk_Impact::STRIKING_FROM);
    }

    /**
     * A position with almost no impressions is noise, not demand. Without a
     * floor, a page seen twice at position 12 would outrank a page seen ten
     * thousand times at position 14.
     */
    public function test_there_is_an_impressions_floor()
    {
        $this->assertGreaterThan(0, Slk_Impact::MIN_IMPRESSIONS);
    }

    /**
     * THE important guard. Most sites have no Search Console data, and those
     * that do have it for a fraction of their pages. If a missing figure
     * counted as zero demand, importing a partial export would silently bury
     * every page the export happened to omit — the plugin would get worse the
     * moment you gave it more information.
     */
    public function test_missing_search_data_redistributes_rather_than_penalises()
    {
        $src = self::source();
        $this->assertStringContainsString(
            'if ($demand === null)',
            $src,
            'a missing demand signal must be handled explicitly, not treated as zero'
        );
        $this->assertMatchesRegularExpression(
            '/\$total\s*=\s*self::W_RELEVANCE\s*\+\s*self::W_NEED;/',
            $src,
            'with no search data the demand weight must be redistributed across the other two'
        );
    }

    /**
     * Every ranked row explains itself. A ranked list nobody can interrogate
     * is a ranked list nobody acts on — and acting on it is the entire point.
     */
    public function test_every_score_carries_a_reason()
    {
        $src = self::source();
        $this->assertStringContainsString("'why'", $src);
        $this->assertStringContainsString('protected static function why(', $src);
    }

    public function test_ranking_is_deterministic_when_scores_tie()
    {
        // Relevance breaks ties, so two equal-impact rows always order the
        // same way rather than however usort happened to leave them.
        $this->assertStringContainsString(
            "return \$b['match'] <=> \$a['match'];",
            self::source(),
            'ties must fall back to relevance so the order is stable and explicable'
        );
    }

    /* ---------------------------------------------------------------------
     * Bulk redirect repointing
     * ------------------------------------------------------------------ */

    /**
     * Only PERMANENT redirects may be repointed.
     *
     * A 302 or 307 means "keep using the original URL" — a login wall, a
     * country splash, maintenance. Following one bakes today's temporary
     * answer permanently into the content. The first redirect found while
     * testing this was a link to /wp-admin/ that 302s to a login URL with a
     * reauth token; repointing it would have been actively wrong.
     */
    public function test_only_permanent_redirects_are_repointed()
    {
        $this->assertSame([301, 308], Slk_Error::PERMANENT);

        $src = file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/Error.php');
        $start = strpos($src, 'function redirect_rows(');
        $body = substr($src, $start, 1200);

        $this->assertStringContainsString(
            'l.status_code IN',
            $body,
            'redirect_rows() must filter on status code, or temporary redirects get followed'
        );
        $this->assertStringContainsString(
            "l.redirects_to <> l.url",
            $body,
            'a link that redirects to itself must not be rewritten'
        );
    }

    /** The bulk rewrite must be undoable, like every other bulk write. */
    public function test_repointing_is_recorded_as_an_undoable_batch()
    {
        $src = file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/Error.php');
        $start = strpos($src, 'function repoint_redirects(');
        $body = substr($src, $start, 3000);

        $this->assertStringContainsString('Slk_Activity::new_batch', $body);
        $this->assertStringContainsString('Slk_Activity::record', $body);
        $this->assertLessThan(
            strpos($body, 'wp_update_post'),
            strpos($body, 'Slk_Activity::record'),
            'the snapshot must be taken before the write'
        );
    }

    /**
     * A Location header may be relative. Storing it raw would produce a link
     * to the wrong host the moment it was applied.
     */
    public function test_relative_redirect_targets_are_made_absolute()
    {
        $src = file_get_contents(SLK_PLUGIN_DIR . 'core/Slk/Error.php');
        $this->assertStringContainsString('function absolute_url(', $src);
        $this->assertStringContainsString("preg_match('#^https?://#i'", $src);
    }
}
