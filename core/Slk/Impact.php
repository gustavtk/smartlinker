<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Which link should you add FIRST?
 *
 * Every report in this plugin tells you what is wrong. None of them told you
 * what to do first, and Link Opportunities sorted purely on how well the anchor
 * matched — so a perfectly-worded link to a page that already has forty inbound
 * links outranked a decent link to a starved page sitting one position off page
 * one. Relevance is a precondition, not a priority.
 *
 * Three signals, all of which the plugin already computes and two of which it
 * was throwing away:
 *
 *   RELEVANCE   how well the anchor and the destination actually fit. Already
 *               the sort key. Kept as the largest single weight, because an
 *               irrelevant link is worthless however valuable the target.
 *
 *   NEED        how starved of internal links the target is (Slk_Equity). A
 *               link to a page nothing points at moves it a long way; the
 *               fortieth link to a hub page moves nothing.
 *
 *   DEMAND      whether the target is nearly ranking (Search Console). This is
 *               the one that was sitting unused in a table. A page at position
 *               11-20 with real impressions is one nudge off page one, and
 *               internal links are the cheapest nudge available. A page at
 *               position 80, or with no impressions, will not be rescued by a
 *               link.
 *
 * DEMAND IS A BONUS, NEVER A PENALTY. Most sites have no Search Console data at
 * all, and the ones that do have it for a fraction of their pages. If its
 * absence pushed a row down, importing a partial export would silently bury
 * every page the export happened to omit. So a page with no data scores exactly
 * as it did before, and a striking-distance page is lifted above it.
 */
class Slk_Impact
{
    /**
     * Weights. Relevance dominates; the other two reorder within it rather
     * than overturning it.
     */
    const W_RELEVANCE = 0.60;
    const W_NEED      = 0.25;
    const W_DEMAND    = 0.15;

    /** Positions that count as "striking distance" — page two, essentially. */
    const STRIKING_FROM = 10.5;
    const STRIKING_TO   = 20.5;

    /** Below this many impressions a position is noise, not demand. */
    const MIN_IMPRESSIONS = 10;

    /* ---------------------------------------------------------------------
     * Search Console signal
     * ------------------------------------------------------------------ */

    /**
     * post_id => ['position','impressions','clicks'] for everything imported.
     *
     * Cached per request: the opportunity list asks about the same handful of
     * targets hundreds of times.
     */
    public static function search_data()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        if (!class_exists('Slk_SearchConsole') || !Slk_SearchConsole::has_data()) {
            return $map;
        }

        global $wpdb;
        $gsc = Slk_Query::gsc_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results(
            "SELECT post_id, position, impressions, clicks FROM {$gsc} WHERE post_id > 0"
        );
        foreach ($rows as $r) {
            $map[(int) $r->post_id] = [
                'position'    => (float) $r->position,
                'impressions' => (int) $r->impressions,
                'clicks'      => (int) $r->clicks,
            ];
        }
        return $map;
    }

    /**
     * How much a link to this post could plausibly earn, 0–1.
     *
     * Peaks in striking distance and falls away either side. A page already at
     * position 3 has little to gain; a page at 60 will not be saved by an
     * internal link, and pretending otherwise sends people to work on the
     * wrong thing.
     */
    public static function demand($post_id)
    {
        $data = self::search_data();
        if (!isset($data[(int) $post_id])) {
            return null;   // no data is not the same as no demand
        }

        $d = $data[(int) $post_id];
        if ($d['impressions'] < self::MIN_IMPRESSIONS || $d['position'] <= 0) {
            return null;
        }

        $pos = $d['position'];

        if ($pos < self::STRIKING_FROM) {
            // Already on page one. Some value in defending it, not much.
            $score = 0.25;
        } elseif ($pos <= self::STRIKING_TO) {
            // The sweet spot. Strongest at the top of page two, where the
            // gap to close is smallest.
            $span = self::STRIKING_TO - self::STRIKING_FROM;
            $score = 1.0 - (($pos - self::STRIKING_FROM) / $span) * 0.35;
        } else {
            // Beyond page two, decaying towards nothing by roughly position 50.
            $score = max(0.0, 0.55 - (($pos - self::STRIKING_TO) / 30) * 0.55);
        }

        // Weight by how much demand there actually is. A page with 12
        // impressions at position 12 is technically striking distance and
        // practically irrelevant; log keeps big numbers from dominating.
        $volume = min(1.0, log10(max(10, $d['impressions'])) / 4);   // 10k impressions ≈ 1.0

        return max(0.0, min(1.0, $score * (0.4 + 0.6 * $volume)));
    }

    /* ---------------------------------------------------------------------
     * The combined score
     * ------------------------------------------------------------------ */

    /** True only when the target sits in the striking-distance band. */
    public static function is_striking($post_id)
    {
        $data = self::search_data();
        if (!isset($data[(int) $post_id])) {
            return false;
        }
        $d = $data[(int) $post_id];
        return $d['impressions'] >= self::MIN_IMPRESSIONS
            && $d['position'] >= self::STRIKING_FROM
            && $d['position'] <= self::STRIKING_TO;
    }

    /**
     * Score one opportunity.
     *
     * @param int $target_id the post that would GAIN the link
     * @param int $match     relevance 0–100, as the suggestion engine reports it
     * @return array{score:float,relevance:float,need:float,demand:?float,why:string}
     */
    public static function score($target_id, $match)
    {
        $relevance = max(0.0, min(1.0, ((float) $match) / 100));

        $needs = class_exists('Slk_Equity') ? Slk_Equity::need_map() : [];
        $need = isset($needs[(int) $target_id]) ? (float) $needs[(int) $target_id] : 0.0;

        $demand = self::demand($target_id);

        /*
         * With no search data the demand weight is redistributed across the
         * other two rather than counted as zero. Otherwise every page on a
         * site with no Search Console import would carry a permanent 15%
         * penalty for a file the owner never uploaded.
         */
        if ($demand === null) {
            $total = self::W_RELEVANCE + self::W_NEED;
            $score = ($relevance * self::W_RELEVANCE + $need * self::W_NEED) / $total;
        } else {
            $score = $relevance * self::W_RELEVANCE
                   + $need * self::W_NEED
                   + $demand * self::W_DEMAND;
        }

        return [
            'score'     => max(0.0, min(1.0, $score)),
            'relevance' => $relevance,
            'need'      => $need,
            'demand'    => $demand,
            // Distinct from "has demand data": a page already at position 3
            // has a demand score but is emphatically not near page one.
            'striking'  => self::is_striking($target_id),
            'why'       => self::why($target_id, $relevance, $need, $demand),
        ];
    }

    /**
     * A sentence explaining why this ranks where it does.
     *
     * A ranked list nobody can interrogate is a ranked list nobody trusts —
     * and the whole point of ranking is that people act on the top of it.
     */
    protected static function why($target_id, $relevance, $need, $demand)
    {
        $parts = [];

        if ($demand !== null) {
            $d = self::search_data()[(int) $target_id];
            if ($d['position'] >= self::STRIKING_FROM && $d['position'] <= self::STRIKING_TO) {
                $parts[] = sprintf(
                    /* translators: 1: average search position, 2: monthly impressions */
                    __('ranks %1$s with %2$s impressions — one page off the first', 'smartlinker'),
                    number_format_i18n($d['position'], 1),
                    number_format_i18n($d['impressions'])
                );
            } elseif ($d['position'] < self::STRIKING_FROM) {
                $parts[] = sprintf(
                    /* translators: %s: average search position */
                    __('already ranks %s', 'smartlinker'),
                    number_format_i18n($d['position'], 1)
                );
            }
        }

        if ($need >= 0.6) {
            $parts[] = __('very few posts link to it', 'smartlinker');
        } elseif ($need >= 0.3) {
            $parts[] = __('fewer inbound links than average', 'smartlinker');
        }

        if ($relevance >= 0.75) {
            $parts[] = __('a strong anchor match', 'smartlinker');
        }

        if (empty($parts)) {
            return __('A reasonable match, but nothing here makes it urgent.', 'smartlinker');
        }

        // Sentence case, joined naturally.
        $text = implode(', ', $parts);
        return ucfirst($text) . '.';
    }

    /**
     * Sort opportunity rows by impact, attaching the score to each.
     *
     * @param array $rows rows carrying target_id and match
     */
    public static function rank(array $rows)
    {
        foreach ($rows as $i => $r) {
            $rows[$i]['impact'] = self::score(
                isset($r['target_id']) ? $r['target_id'] : 0,
                isset($r['match']) ? $r['match'] : 0
            );
        }

        usort($rows, function ($a, $b) {
            if (abs($a['impact']['score'] - $b['impact']['score']) > 0.0001) {
                return $b['impact']['score'] <=> $a['impact']['score'];
            }
            // Relevance breaks ties, so the order is stable and explicable
            // rather than whatever the sort happened to do.
            return $b['match'] <=> $a['match'];
        });

        return $rows;
    }

    /** True when Search Console data is actually contributing to the ranking. */
    public static function has_search_signal()
    {
        return !empty(self::search_data());
    }

    /**
     * Targets in striking distance, for a report of their own.
     *
     * @return array of ['post_id','title','position','impressions','clicks','inbound','need']
     */
    public static function striking_distance($limit = 100)
    {
        $out = [];
        $needs = class_exists('Slk_Equity') ? Slk_Equity::need_map() : [];

        global $wpdb;
        $links = Slk_Query::links_table();

        foreach (self::search_data() as $post_id => $d) {
            if ($d['position'] < self::STRIKING_FROM || $d['position'] > self::STRIKING_TO) {
                continue;
            }
            if ($d['impressions'] < self::MIN_IMPRESSIONS) {
                continue;
            }
            $title = get_the_title($post_id);
            if ($title === '') {
                continue;   // deleted since the import
            }

            // phpcs:ignore WordPress.DB.PreparedSQL
            $inbound = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$links} WHERE target_post_id = %d AND type = 'internal'",
                (int) $post_id
            ));

            $out[] = [
                'post_id'     => (int) $post_id,
                'title'       => $title,
                'position'    => $d['position'],
                'impressions' => $d['impressions'],
                'clicks'      => $d['clicks'],
                'inbound'     => $inbound,
                'need'        => isset($needs[(int) $post_id]) ? (float) $needs[(int) $post_id] : 0.0,
                'edit_url'    => Slk_Admin::edit_url($post_id),
                'inbound_url' => admin_url('admin.php?page=smartlinker_inbound&target=' . (int) $post_id),
            ];
        }

        // Fewest inbound links first: those are the ones a link actually moves.
        usort($out, function ($a, $b) {
            if ($a['inbound'] !== $b['inbound']) {
                return $a['inbound'] <=> $b['inbound'];
            }
            return $b['impressions'] <=> $a['impressions'];
        });

        return array_slice($out, 0, $limit);
    }
}
