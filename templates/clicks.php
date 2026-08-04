<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows @var string $range */
$base = Slk_Reports::url('clicks');
$ranges = [
    '7'   => __('Last 7 days', 'smartlinker'),
    '30'  => __('Last 30 days', 'smartlinker'),
    'all' => __('All time', 'smartlinker'),
];
$total = 0;
foreach ($rows as $r) {
    $total += (int) $r->clicks;
}
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Link Clicks', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Which internal links actually get clicked — not just which ones exist. Use this to spot links that need better anchor text or placement.', 'smartlinker'); ?>
    </p>

    <div class="slk-tabs">
        <?php foreach ($ranges as $key => $label) : ?>
            <?php // Numeric array keys become ints in PHP — compare as strings. ?>
            <a href="<?php echo esc_url(add_query_arg('range', (string) $key, $base)); ?>"
               class="slk-tab<?php echo $range === (string) $key ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($rows)) : ?>
        <p class="description">
            <?php esc_html_e('No clicks recorded for this period yet. Click tracking must be enabled in Settings, and data appears once visitors start following your internal links.', 'smartlinker'); ?>
        </p>
    <?php else : ?>
        <div class="slk-stat-tiles">
            <div class="slk-tile">
                <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($total)); ?></span>
                <span class="slk-tile-label"><?php esc_html_e('Total clicks', 'smartlinker'); ?></span>
            </div>
            <div class="slk-tile">
                <span class="slk-tile-num"><?php echo esc_html(number_format_i18n(count($rows))); ?></span>
                <span class="slk-tile-label"><?php esc_html_e('Distinct links clicked', 'smartlinker'); ?></span>
            </div>
        </div>

        <div class="slk-rank-list">
            <?php $i = 0; foreach ($rows as $row) : $i++;
                $title = $row->target_post_id ? get_the_title($row->target_post_id) : $row->url;
                ?>
                <div class="slk-rank-item">
                    <span class="slk-rank-num">#<?php echo (int) $i; ?></span>
                    <div class="slk-rank-main">
                        <div class="slk-rank-title"><?php echo esc_html($title ?: $row->url); ?></div>
                        <div class="slk-rank-sub">
                            <?php esc_html_e('anchor:', 'smartlinker'); ?> <em><?php echo esc_html($row->anchor ?: '—'); ?></em>
                            &nbsp;·&nbsp;
                            <?php /* translators: %d: number of posts the link appears in */ printf(esc_html(_n('from %d post', 'from %d posts', (int) $row->sources, 'smartlinker')), (int) $row->sources); ?>
                        </div>
                    </div>
                    <span class="slk-rank-metric"><?php /* translators: %s: number of clicks, already formatted */ printf(esc_html(_n('%s click', '%s clicks', (int) $row->clicks, 'smartlinker')), number_format_i18n($row->clicks)); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
