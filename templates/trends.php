<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows @var array $trends @var array $metrics @var string $metric @var int $days @var int $total_rows */
$base = Slk_Reports::url('trends');
$snapshot_url = wp_nonce_url(Slk_Reports::url('trends', ['slk_snapshot' => 1, 'days' => $days, 'metric' => $metric]), 'slk_snapshot');

$ranges = [
    30  => __('30 days', 'smartlinker'),
    90  => __('90 days', 'smartlinker'),
    365 => __('12 months', 'smartlinker'),
];

/** A change, phrased so the direction reads without decoding a colour. */
$phrase = function ($change, $good) {
    if (abs($change) < 0.0001) {
        return ['', __('no change', 'smartlinker')];
    }
    $arrow = $change > 0 ? '↑' : '↓';
    $klass = $good ? 'slk-trend-good' : 'slk-trend-bad';
    return [$klass, $arrow . ' ' . number_format_i18n(abs($change))];
};
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Trends', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Every other report here is a photograph of right now. This one is the only place that answers whether the linking is actually improving — orphans falling, internal links climbing, broken links cleared and staying cleared. SmartLinker records where the site stands once a day and after every scan, so the line builds itself while you work.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['snapshot'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Snapshot recorded.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <?php if ($total_rows < 2) : ?>

        <?php
        // Honesty matters more than a chart here. One reading is not a trend,
        // and drawing a flat line through a single point would imply a
        // stability that has not been measured.
        $today = $rows ? $rows[count($rows) - 1] : null;
        ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-chart-line"></span>
            <strong><?php esc_html_e('Not enough history yet', 'smartlinker'); ?></strong>
            <?php
            echo $total_rows === 1
                ? esc_html__('One reading has been recorded. A second one — tomorrow, or after your next scan — is what turns it into a line.', 'smartlinker')
                : esc_html__('Nothing has been recorded yet. The first snapshot is taken automatically overnight, or you can take one now.', 'smartlinker');
            ?>
            <p style="margin-top:14px;">
                <a class="button button-primary" href="<?php echo esc_url($snapshot_url); ?>">
                    <?php esc_html_e('Record a snapshot now', 'smartlinker'); ?>
                </a>
            </p>
        </div>

        <?php if ($today) : ?>
            <h2 class="slk-h2"><?php esc_html_e('Today’s reading', 'smartlinker'); ?></h2>
            <div class="slk-stat-tiles slk-stat-tiles-wide">
                <?php
                $tiles = [
                    'internal'      => __('Internal links', 'smartlinker'),
                    'orphaned'      => __('Orphaned posts', 'smartlinker'),
                    'broken'        => __('Broken links', 'smartlinker'),
                    'opportunities' => __('Open opportunities', 'smartlinker'),
                ];
                foreach ($tiles as $k => $label) :
                    ?>
                    <div class="slk-tile">
                        <span class="slk-tile-num"><?php echo esc_html(number_format_i18n((int) $today[$k])); ?></span>
                        <span class="slk-tile-label"><?php echo esc_html($label); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else : ?>

        <div class="slk-trend-tiles">
            <?php foreach ($trends as $t) :
                [$klass, $text] = $phrase($t['change'], $t['good']);
                $url = add_query_arg(['metric' => $t['key'], 'days' => $days], $base);
                ?>
                <a class="slk-trend-tile<?php echo $metric === $t['key'] ? ' is-active' : ''; ?>"
                   href="<?php echo esc_url($url); ?>" title="<?php echo esc_attr($t['hint']); ?>">
                    <span class="slk-trend-label"><?php echo esc_html($t['label']); ?></span>
                    <span class="slk-trend-row">
                        <span class="slk-trend-now"><?php echo esc_html(number_format_i18n($t['now'])); ?></span>
                        <span class="slk-trend-delta <?php echo esc_attr($klass); ?>"><?php echo esc_html($text); ?></span>
                    </span>
                    <?php echo Slk_History::spark($t['points'], $t['good']); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="slk-trend-head">
            <h2 class="slk-h2"><?php echo esc_html($metrics[$metric]['label']); ?></h2>
            <div class="slk-tabs slk-tabs-underline">
                <?php foreach ($ranges as $d => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['days' => $d, 'metric' => $metric], $base)); ?>"
                       class="slk-tab<?php echo $days === $d ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="slk-chart-wrap">
            <?php echo Slk_History::chart($rows, $metric, $metrics[$metric]['good']); ?>
        </div>

        <p class="slk-trend-verdict">
            <?php
            $t = $metrics[$metric];
            if (abs($t['change']) < 0.0001) {
                printf(
                    /* translators: 1: metric name, 2: number of days */
                    esc_html__('%1$s has not moved in the last %2$d days. Steady is fine for broken links; for internal links it means nothing new is being connected.', 'smartlinker'),
                    esc_html($t['label']),
                    (int) $days
                );
            } else {
                printf(
                    /* translators: 1: metric, 2: old value, 3: new value, 4: days, 5: verdict */
                    esc_html__('%1$s went from %2$s to %3$s over the last %4$d days — %5$s.', 'smartlinker'),
                    esc_html($t['label']),
                    esc_html(number_format_i18n($t['then'])),
                    esc_html(number_format_i18n($t['now'])),
                    (int) $days,
                    $t['good']
                        ? '<strong class="slk-trend-good">' . esc_html__('the right direction', 'smartlinker') . '</strong>'
                        : '<strong class="slk-trend-bad">' . esc_html__('the wrong direction', 'smartlinker') . '</strong>'
                );
            }
            ?>
        </p>

        <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:18px;">
            <a class="button" href="<?php echo esc_url($snapshot_url); ?>"><?php esc_html_e('Record a snapshot now', 'smartlinker'); ?></a>
            <a class="button" href="<?php echo esc_url(Slk_CSV::export_url('trends')); ?>"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
            <span class="description">
                <?php
                printf(
                    /* translators: %s: number of readings */
                    esc_html(_n('%s reading recorded so far.', '%s readings recorded so far.', $total_rows, 'smartlinker')),
                    esc_html(number_format_i18n($total_rows))
                );
                ?>
            </span>
        </p>

        <h2 class="slk-h2" style="margin-top:26px;"><?php esc_html_e('Every reading', 'smartlinker'); ?></h2>
        <table class="slk-table">
            <thead>
                <tr>
                    <th style="text-align:left;width:130px;"><?php esc_html_e('Date', 'smartlinker'); ?></th>
                    <th style="text-align:right;"><?php esc_html_e('Internal links', 'smartlinker'); ?></th>
                    <th style="text-align:right;"><?php esc_html_e('Orphans', 'smartlinker'); ?></th>
                    <th style="text-align:right;"><?php esc_html_e('Broken', 'smartlinker'); ?></th>
                    <th style="text-align:right;"><?php esc_html_e('Opportunities', 'smartlinker'); ?></th>
                    <th style="text-align:right;"><?php esc_html_e('Posts', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            // Newest first here, the opposite of the chart: a table is read
            // top-down and the most recent reading is the one you came for.
            $table_rows = array_reverse($rows);
            foreach ($table_rows as $i => $r) :
                $prev = isset($table_rows[$i + 1]) ? $table_rows[$i + 1] : null;
                ?>
                <tr class="slk-tr">
                    <td><?php echo esc_html(mysql2date('j M Y', $r['taken_on'])); ?></td>
                    <?php
                    foreach ([['internal', 'up'], ['orphaned', 'down'], ['broken', 'down'], ['opportunities', 'down']] as [$k, $dir]) :
                        $delta = $prev ? ((int) $r[$k] - (int) $prev[$k]) : 0;
                        $good = $delta === 0 ? null : ($dir === 'up' ? $delta > 0 : $delta < 0);
                        ?>
                        <td style="text-align:right;">
                            <?php echo esc_html(number_format_i18n((int) $r[$k])); ?>
                            <?php if ($delta !== 0) : ?>
                                <span class="slk-trend-inline <?php echo $good ? 'slk-trend-good' : 'slk-trend-bad'; ?>">
                                    <?php echo esc_html(($delta > 0 ? '+' : '−') . number_format_i18n(abs($delta))); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td style="text-align:right;"><?php echo esc_html(number_format_i18n((int) $r['posts'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>
