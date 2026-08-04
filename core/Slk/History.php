<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A daily snapshot of the site's linking, so the reports can answer
 * "is this getting better?" as well as "what is it now".
 *
 * Every other report is a photograph. You cannot tell from any of them whether
 * last month was worse, which is the only question that tells you if the work
 * is paying off. The numbers were already being computed — the scheduled digest
 * calculates exactly these and then throws them away — so this stores one row a
 * day and lets the dashboard draw a line through them.
 *
 * ONE ROW PER DAY, enforced by a unique key on the date. Capture is called from
 * several places (the daily event, the end of a link scan, the digest), and
 * without that key a busy day would produce a jagged line made of the same
 * afternoon sampled six times. A same-day capture overwrites, so the row is
 * always the most recent reading for that date.
 *
 * The value only appears after a few weeks of rows, which is the argument for
 * collecting them before anyone asks for the chart rather than after.
 */
class Slk_History
{
    const EVENT = 'slk_daily_snapshot';

    /** Days kept. Two years of daily rows is well under a megabyte. */
    const KEEP_DAYS = 730;

    public function register()
    {
        add_action(self::EVENT, [__CLASS__, 'capture']);
        add_action('admin_init', [__CLASS__, 'handle_actions']);
        add_action('admin_init', [__CLASS__, 'ensure_scheduled'], 20);
    }

    /**
     * Its own daily event, deliberately not tied to the digest.
     *
     * The digest is off by default and can be switched off at any time; history
     * that stops silently when an unrelated setting changes is worse than no
     * history, because the gap is invisible on a chart.
     */
    public static function ensure_scheduled()
    {
        if (!wp_next_scheduled(self::EVENT)) {
            // Just after midnight site time, so a day's row reflects a whole day.
            $offset = (float) get_option('gmt_offset', 0) * HOUR_IN_SECONDS;
            $next = strtotime('tomorrow 00:20', time() + $offset) - $offset;
            wp_schedule_event($next, 'daily', self::EVENT);
        }
    }

    /* ---------------------------------------------------------------------
     * Writing
     * ------------------------------------------------------------------ */

    /**
     * Record where the site stands today.
     *
     * @return array the row written
     */
    public static function capture()
    {
        global $wpdb;

        $s = Slk_Report::summary();
        $opps = Slk_Opportunity::all('standard');

        // Depth comes from the equity cache when it happens to be warm. It is
        // NOT rebuilt here: a snapshot must stay cheap enough to run daily
        // without anyone noticing, and PageRank over a large site is not that.
        $avg_depth = 0.0;
        $cached = get_transient(Slk_Equity::TRANSIENT);
        if (is_array($cached) && isset($cached['stats']['avg_depth'])) {
            $avg_depth = (float) $cached['stats']['avg_depth'];
        }

        $row = [
            'taken_on'      => current_time('Y-m-d'),
            'posts'         => (int) $s['total_posts'],
            'internal'      => (int) $s['internal'],
            'external'      => (int) $s['external'],
            'orphaned'      => (int) $s['orphaned'],
            'broken'        => (int) $s['broken'],
            'opportunities' => is_array($opps) ? count($opps['rows']) : 0,
            'avg_depth'     => round($avg_depth, 2),
            'taken'         => current_time('mysql'),
        ];

        $table = Slk_Query::history_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE taken_on = %s",
            $row['taken_on']
        ));

        if ($existing) {
            // Later reading on the same day replaces the earlier one.
            $wpdb->update($table, $row, ['id' => (int) $existing]);
        } else {
            $wpdb->insert($table, $row);
        }

        self::prune();
        return $row;
    }

    protected static function prune()
    {
        global $wpdb;
        $table = Slk_Query::history_table();
        // phpcs:ignore WordPress.DB.PreparedSQL
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE taken_on < %s",
            gmdate('Y-m-d', time() - (self::KEEP_DAYS * DAY_IN_SECONDS))
        ));
    }

    /* ---------------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------------ */

    /**
     * @param int $days how far back to look
     * @return array oldest first, so a chart can be drawn left to right
     */
    public static function series($days = 90)
    {
        global $wpdb;
        $table = Slk_Query::history_table();
        $from = gmdate('Y-m-d', time() - (max(1, (int) $days) * DAY_IN_SECONDS));

        // phpcs:ignore WordPress.DB.PreparedSQL
        return $wpdb->get_results($wpdb->prepare(
            "SELECT taken_on, posts, internal, external, orphaned, broken, opportunities, avg_depth
             FROM {$table} WHERE taken_on >= %s ORDER BY taken_on ASC",
            $from
        ), ARRAY_A);
    }

    public static function count_rows()
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Slk_Query::history_table());
    }

    /**
     * What each tracked metric is doing: latest value, change, direction.
     *
     * "Better" is per-metric and stated explicitly — more internal links is
     * good, more orphans is not — because a chart that colours every rise green
     * teaches the wrong thing.
     *
     * @return array list of ['key','label','now','then','change','good','hint','points']
     */
    public static function trends($days = 30)
    {
        return self::compute_trends(self::series($days));
    }

    /**
     * The direction logic, separated from the query so it can be tested
     * without a database. This is the part that decides what "better" means.
     */
    public static function compute_trends(array $rows)
    {
        if (count($rows) < 1) {
            return [];
        }

        $metrics = [
            'internal'      => [__('Internal links', 'smartlinker'), 'up', __('Links between your own posts.', 'smartlinker')],
            'orphaned'      => [__('Orphaned posts', 'smartlinker'), 'down', __('Published posts nothing links to.', 'smartlinker')],
            'broken'        => [__('Broken links', 'smartlinker'), 'down', __('Links that no longer resolve.', 'smartlinker')],
            'opportunities' => [__('Open opportunities', 'smartlinker'), 'down', __('Suggested links you have not applied yet.', 'smartlinker')],
        ];

        $out = [];
        foreach ($metrics as $key => $meta) {
            $points = array_map(function ($r) use ($key) {
                return (float) $r[$key];
            }, $rows);

            $now = end($points);
            $then = $points[0];
            $change = $now - $then;

            // No movement is neither good nor bad; saying so avoids a green
            // tick on a metric that has not budged.
            $good = null;
            if (abs($change) > 0.0001) {
                $good = $meta[1] === 'up' ? $change > 0 : $change < 0;
            }

            $out[] = [
                'key'    => $key,
                'label'  => $meta[0],
                'hint'   => $meta[2],
                'now'    => $now,
                'then'   => $then,
                'change' => $change,
                'good'   => $good,
                'points' => $points,
            ];
        }
        return $out;
    }

    /* ---------------------------------------------------------------------
     * Drawing
     *
     * Inline SVG, generated in PHP. A charting library would be 60KB of
     * JavaScript to draw four polylines, and the plugin has no build step.
     * ------------------------------------------------------------------ */

    /**
     * A sparkline: shape only, no axes, sized to sit inside a stat tile.
     */
    public static function spark(array $points, $good = null, $w = 150, $h = 34)
    {
        if (count($points) < 2) {
            return '';
        }

        $min = min($points);
        $max = max($points);
        // A flat series has no range to scale against; draw it down the middle
        // rather than dividing by zero or spiking it to the top.
        $range = ($max - $min) > 0 ? ($max - $min) : 1;
        $flat = ($max - $min) == 0;

        $pad = 3;
        $step = ($w - ($pad * 2)) / (count($points) - 1);
        $coords = [];
        foreach (array_values($points) as $i => $v) {
            $x = $pad + ($i * $step);
            $y = $flat
                ? $h / 2
                : ($h - $pad) - ((($v - $min) / $range) * ($h - ($pad * 2)));
            $coords[] = round($x, 1) . ',' . round($y, 1);
        }

        $colour = $good === null ? '#8c8f94' : ($good ? '#00844a' : '#b32d2e');
        $line = implode(' ', $coords);
        $last = end($coords);
        [$lx, $ly] = explode(',', $last);

        $area = 'M' . $pad . ',' . $h . ' L' . str_replace(' ', ' L', $line) . ' L' . round($pad + (count($points) - 1) * $step, 1) . ',' . $h . ' Z';

        return '<svg class="slk-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" '
            . 'preserveAspectRatio="none" aria-hidden="true" focusable="false">'
            . '<path d="' . esc_attr($area) . '" fill="' . esc_attr($colour) . '" opacity="0.10"/>'
            . '<polyline points="' . esc_attr($line) . '" fill="none" stroke="' . esc_attr($colour) . '" '
            . 'stroke-width="1.8" stroke-linejoin="round" stroke-linecap="round"/>'
            . '<circle cx="' . esc_attr($lx) . '" cy="' . esc_attr($ly) . '" r="2.6" fill="' . esc_attr($colour) . '"/>'
            . '</svg>';
    }

    /**
     * The full chart on the Trends tab: gridlines, value scale, date labels.
     *
     * @param array $rows series() output
     * @param string $key which column to plot
     */
    public static function chart(array $rows, $key, $good = null)
    {
        $points = array_map(function ($r) use ($key) {
            return (float) $r[$key];
        }, $rows);
        if (count($points) < 2) {
            return '';
        }

        $w = 900;
        $h = 260;
        $left = 46;
        $bottom = 26;
        $top = 12;
        $right = 12;

        $max = max($points);
        $min = min($points);
        // The scale starts at zero unless the values sit far from it. Starting
        // at the minimum makes a change from 100 to 102 look like a cliff.
        $floor = ($min > 0 && ($max - $min) < ($max * 0.4)) ? $min : 0;
        $ceil = $max > $floor ? $max : $floor + 1;
        $range = $ceil - $floor;
        // Breathing room so the highest point is not welded to the top edge.
        $ceil += $range * 0.12;
        $range = $ceil - $floor;

        $plot_w = $w - $left - $right;
        $plot_h = $h - $top - $bottom;
        $step = $plot_w / (count($points) - 1);

        $x_at = function ($i) use ($left, $step) {
            return round($left + ($i * $step), 1);
        };
        $y_at = function ($v) use ($top, $plot_h, $floor, $range) {
            return round($top + $plot_h - ((($v - $floor) / $range) * $plot_h), 1);
        };

        $colour = $good === null ? '#3858e9' : ($good ? '#00844a' : '#b32d2e');

        $svg = '<svg class="slk-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
            . 'aria-label="' . esc_attr__('Trend over time', 'smartlinker') . '">';

        // Horizontal gridlines and the value scale.
        for ($g = 0; $g <= 4; $g++) {
            $v = $floor + (($range / 4) * $g);
            $y = $y_at($v);
            $svg .= '<line x1="' . $left . '" y1="' . $y . '" x2="' . ($w - $right) . '" y2="' . $y . '" stroke="#e0e0e0" stroke-width="1"/>';
            $svg .= '<text x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end" '
                . 'font-size="11" fill="#8c8f94">' . esc_html(number_format_i18n(round($v))) . '</text>';
        }

        $coords = [];
        foreach ($points as $i => $v) {
            $coords[] = $x_at($i) . ',' . $y_at($v);
        }
        $line = implode(' ', $coords);

        $svg .= '<path d="M' . $x_at(0) . ',' . ($top + $plot_h) . ' L' . str_replace(' ', ' L', $line)
            . ' L' . $x_at(count($points) - 1) . ',' . ($top + $plot_h) . ' Z" fill="' . esc_attr($colour) . '" opacity="0.08"/>';
        $svg .= '<polyline points="' . esc_attr($line) . '" fill="none" stroke="' . esc_attr($colour)
            . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';

        // A dot per capture, with the date and value in the tooltip. Only
        // labelled at the ends and the middle — a daily series would otherwise
        // print a hundred overlapping dates along the bottom.
        $label_at = [0, (int) floor((count($points) - 1) / 2), count($points) - 1];
        foreach ($points as $i => $v) {
            $x = $x_at($i);
            $y = $y_at($v);
            $when = mysql2date('j M Y', $rows[$i]['taken_on']);
            $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="' . (count($points) > 40 ? '2' : '3')
                . '" fill="#fff" stroke="' . esc_attr($colour) . '" stroke-width="1.6">'
                . '<title>' . esc_html($when . ' — ' . number_format_i18n($v)) . '</title></circle>';

            if (in_array($i, $label_at, true)) {
                $anchor = $i === 0 ? 'start' : ($i === count($points) - 1 ? 'end' : 'middle');
                $svg .= '<text x="' . $x . '" y="' . ($h - 6) . '" text-anchor="' . $anchor . '" '
                    . 'font-size="11" fill="#8c8f94">' . esc_html($when) . '</text>';
            }
        }

        return $svg . '</svg>';
    }

    /* ---------------------------------------------------------------------
     * Admin
     * ------------------------------------------------------------------ */

    public static function render_page()
    {
        $days = isset($_GET['days']) ? max(7, min(730, (int) $_GET['days'])) : 90;
        $metric = isset($_GET['metric']) ? sanitize_key($_GET['metric']) : 'internal';

        $rows = self::series($days);
        $trends = self::trends($days);
        $total_rows = self::count_rows();

        $metrics = [];
        foreach ($trends as $t) {
            $metrics[$t['key']] = $t;
        }
        if (!isset($metrics[$metric])) {
            $metric = 'internal';
        }

        include SLK_PLUGIN_DIR . 'templates/trends.php';
    }

    public static function handle_actions()
    {
        if (!class_exists('Slk_Reports') || !Slk_Reports::on_tab('trends')) {
            return;
        }
        if (!current_user_can('edit_posts')) {
            return;
        }
        if (!empty($_GET['slk_snapshot']) && check_admin_referer('slk_snapshot')) {
            self::capture();
            wp_safe_redirect(Slk_Reports::url('trends', ['snapshot' => 1]));
            exit;
        }
    }
}
