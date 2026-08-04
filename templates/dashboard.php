<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $stats @var int $health @var float $quality @var array $recommendations @var array $unused */
$user = wp_get_current_user();
$name = $user && $user->first_name ? $user->first_name : ($user ? $user->display_name : '');

list($health_label, $health_level)   = Slk_Dashboard::band($health);
list($cover_label, $cover_level)     = Slk_Dashboard::band($stats['coverage']);
list($quality_label, $quality_level) = Slk_Dashboard::band($quality * 10);

/** Render a +/- delta line comparing two periods. */
if (!function_exists('slk_delta')) :
function slk_delta($now, $prev)
{
    if ($prev <= 0) {
        $pct = $now > 0 ? 100 : 0;
    } else {
        $pct = round((($now - $prev) / $prev) * 100);
    }
    $cls = $pct > 0 ? 'slk-up' : ($pct < 0 ? 'slk-down' : '');
    $sign = $pct > 0 ? '+' : '';
    return '<span class="slk-delta ' . esc_attr($cls) . '">' . esc_html($sign . $pct) . '% '
        . esc_html__('vs previous 30 days', 'smartlinker') . '</span>';
}
endif;
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Dashboard', 'smartlinker'); ?></h1>

    <div class="slk-greeting">
        <h2>👋 <?php echo esc_html(Slk_Dashboard::greeting()); ?><?php echo $name ? ', ' . esc_html($name) : ''; ?>!</h2>
        <p><?php esc_html_e('Here is your latest internal linking snapshot.', 'smartlinker'); ?></p>
    </div>

    <?php
    /*
     * Above the snapshot on purpose: on a fresh install the snapshot is all
     * zeroes, and the reason for that is exactly what this panel explains.
     */
    if (!empty($show_setup)) {
        include SLK_PLUGIN_DIR . 'templates/setup.php';
    }
    ?>

    <?php if (!empty($recommendations)) : ?>
        <?php foreach ($recommendations as $r) : ?>
            <div class="slk-reco">
                <span class="slk-reco-icon dashicons dashicons-admin-links"></span>
                <div class="slk-reco-main">
                    <div class="slk-reco-title"><?php echo esc_html($r['title']); ?></div>
                    <div class="slk-reco-desc"><?php echo esc_html($r['desc']); ?></div>
                    <div class="slk-reco-foot">
                        <span class="slk-badge <?php echo $r['impact'] === 'high' ? 'slk-badge-bad' : 'slk-badge-warn'; ?>">
                            <?php printf(esc_html__('Impact: %s', 'smartlinker'), $r['impact'] === 'high' ? esc_html__('High', 'smartlinker') : esc_html__('Medium', 'smartlinker')); ?>
                        </span>
                        <a class="button button-primary button-small" href="<?php echo esc_url($r['url']); ?>"><?php echo esc_html($r['action']); ?></a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php else : ?>
        <div class="slk-callout slk-callout-good">
            <strong><?php esc_html_e('Your internal linking is in great shape.', 'smartlinker'); ?></strong>
            <div class="slk-row-desc"><?php esc_html_e('No orphaned posts, no broken links, and strong link coverage. Keep publishing.', 'smartlinker'); ?></div>
        </div>
    <?php endif; ?>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-cap"><?php esc_html_e('Posts crawled', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['crawled'])); ?></span>
            <div class="slk-progress"><span style="width:<?php echo esc_attr(min(100, $stats['indexed_pct'])); ?>%"></span></div>
            <span class="slk-tile-label">
                <?php printf(esc_html__('%s%% indexed of %s published', 'smartlinker'), esc_html($stats['indexed_pct']), esc_html(number_format_i18n($stats['total_posts']))); ?>
            </span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-cap"><?php esc_html_e('Clicks tracked', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['clicks_30'])); ?></span>
            <?php echo slk_delta($stats['clicks_30'], $stats['clicks_prev']); ?>
            <span class="slk-tile-label"><?php printf(esc_html__('Previous 30 days: %s', 'smartlinker'), esc_html(number_format_i18n($stats['clicks_prev']))); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-cap"><?php esc_html_e('Links created (30d)', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['created_30'])); ?></span>
            <?php echo slk_delta($stats['created_30'], $stats['created_prev']); ?>
            <span class="slk-tile-label"><?php printf(esc_html__('Previous 30 days: %s', 'smartlinker'), esc_html(number_format_i18n($stats['created_prev']))); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-cap"><?php esc_html_e('Time saved this month', 'smartlinker'); ?><?php echo Slk_Admin::help(__('An estimate: the number of links SmartLinker inserted in the last 30 days, multiplied by roughly 2 minutes of manual work per link.', 'smartlinker')); ?></span>
            <span class="slk-tile-num slk-good"><?php echo esc_html(number_format_i18n($stats['time_saved'], 1)); ?> <small><?php esc_html_e('hrs', 'smartlinker'); ?></small></span>
            <span class="slk-tile-label"><?php printf(esc_html__('Based on ~%d min of manual work per link.', 'smartlinker'), Slk_Dashboard::MINUTES_PER_LINK); ?></span>
        </div>
    </div>

    <?php if ($trend_ready) : ?>
        <?php
        // The tiles above say where the site stands. This says which way it is
        // moving, which is the only thing that tells you whether the work is
        // paying off. It stays out of the way until there is history to show.
        ?>
        <div class="slk-card-block">
            <div class="slk-block-head">
                <h2><?php esc_html_e('Last 30 days', 'smartlinker'); ?></h2>
                <a class="slk-block-link" href="<?php echo esc_url(Slk_Reports::url('trends')); ?>"><?php esc_html_e('See the full trend', 'smartlinker'); ?> →</a>
            </div>
            <div class="slk-trend-tiles" style="margin:0;">
                <?php foreach ($trends as $t) :
                    $arrow = abs($t['change']) < 0.0001 ? '' : ($t['change'] > 0 ? '↑ ' : '↓ ');
                    $klass = $t['good'] === null ? '' : ($t['good'] ? 'slk-trend-good' : 'slk-trend-bad');
                    ?>
                    <a class="slk-trend-tile" href="<?php echo esc_url(Slk_Reports::url('trends', ['metric' => $t['key'], 'days' => 30])); ?>"
                       title="<?php echo esc_attr($t['hint']); ?>">
                        <span class="slk-trend-label"><?php echo esc_html($t['label']); ?></span>
                        <span class="slk-trend-row">
                            <span class="slk-trend-now"><?php echo esc_html(number_format_i18n($t['now'])); ?></span>
                            <span class="slk-trend-delta <?php echo esc_attr($klass); ?>">
                                <?php echo esc_html($arrow === '' ? __('no change', 'smartlinker') : $arrow . number_format_i18n(abs($t['change']))); ?>
                            </span>
                        </span>
                        <?php echo Slk_History::spark($t['points'], $t['good']); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="slk-card-block">
        <div class="slk-block-head">
            <h2><?php esc_html_e('Link Distribution', 'smartlinker'); ?></h2>
            <span class="description"><?php printf(esc_html__('Total: %s links', 'smartlinker'), esc_html(number_format_i18n($stats['total_links']))); ?></span>
        </div>
        <?php if ($stats['total_links'] > 0) : ?>
            <div class="slk-dist-bar">
                <span class="slk-dist-internal" style="width:<?php echo esc_attr($stats['internal_pct']); ?>%"></span>
                <span class="slk-dist-external" style="width:<?php echo esc_attr($stats['external_pct']); ?>%"></span>
            </div>
            <div class="slk-legend">
                <div class="slk-legend-item">
                    <span class="slk-dot slk-dot-internal"></span>
                    <div>
                        <strong><?php esc_html_e('Internal Links', 'smartlinker'); ?></strong>
                        <div class="slk-row-desc"><?php echo esc_html(number_format_i18n($stats['internal'])); ?> (<?php echo esc_html($stats['internal_pct']); ?>%)</div>
                    </div>
                </div>
                <div class="slk-legend-item">
                    <span class="slk-dot slk-dot-external"></span>
                    <div>
                        <strong><?php esc_html_e('External Links', 'smartlinker'); ?></strong>
                        <div class="slk-row-desc"><?php echo esc_html(number_format_i18n($stats['external'])); ?> (<?php echo esc_html($stats['external_pct']); ?>%)</div>
                    </div>
                </div>
            </div>
            <div class="slk-note">
                <?php if ($stats['external_pct'] < 10) : ?>
                    <?php esc_html_e('External ratio is low. Consider citing more relevant high-authority sources.', 'smartlinker'); ?>
                <?php elseif ($stats['external_pct'] > 50) : ?>
                    <?php esc_html_e('You link out more than you link internally. Adding internal links will keep more authority on your site.', 'smartlinker'); ?>
                <?php else : ?>
                    <?php esc_html_e('Healthy balance between internal and external links.', 'smartlinker'); ?>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <p class="description"><?php esc_html_e('No links indexed yet — run a scan to see your distribution.', 'smartlinker'); ?></p>
        <?php endif; ?>
    </div>

    <div class="slk-score-cards">
        <div class="slk-score-card">
            <span class="slk-tile-cap"><?php esc_html_e('Site health score', 'smartlinker'); ?><?php echo Slk_Admin::help(__('A 0–100 summary of your internal linking: mostly link coverage, reduced by orphaned posts and broken links. Above 85 is excellent.', 'smartlinker')); ?></span>
            <span class="slk-score-num slk-<?php echo esc_attr($health_level); ?>"><?php echo (int) $health; ?></span>
            <span class="slk-badge slk-badge-<?php echo esc_attr($health_level); ?>"><?php echo esc_html($health_label); ?></span>
            <p><?php esc_html_e('Overall internal linking health, weighted by coverage, orphans and broken links.', 'smartlinker'); ?></p>
            <a class="button button-small" href="<?php echo esc_url(Slk_Reports::url('overview')); ?>"><?php esc_html_e('View breakdown', 'smartlinker'); ?></a>
        </div>
        <div class="slk-score-card">
            <span class="slk-tile-cap"><?php esc_html_e('Link quality score', 'smartlinker'); ?><?php echo Slk_Admin::help(__('How well-connected your content is: the average number of internal links per post, plus how many posts link out at all. Aim for roughly 3 internal links per post.', 'smartlinker')); ?></span>
            <span class="slk-score-num slk-<?php echo esc_attr($quality_level); ?>"><?php echo esc_html(number_format_i18n($quality, 1)); ?><small>/10</small></span>
            <span class="slk-badge slk-badge-<?php echo esc_attr($quality_level); ?>"><?php echo esc_html($quality_label); ?></span>
            <p><?php esc_html_e('Link density and how many posts link out to other content.', 'smartlinker'); ?></p>
            <a class="button button-small" href="<?php echo esc_url(Slk_Reports::url('overview')); ?>"><?php esc_html_e('View details', 'smartlinker'); ?></a>
        </div>
        <div class="slk-score-card">
            <span class="slk-tile-cap"><?php esc_html_e('Link coverage', 'smartlinker'); ?><?php echo Slk_Admin::help(__('The share of your published content that has at least one internal link pointing at it. Pages with none are effectively invisible to search engines.', 'smartlinker')); ?></span>
            <span class="slk-score-num slk-<?php echo esc_attr($cover_level); ?>"><?php echo esc_html(number_format_i18n($stats['coverage'], 1)); ?>%</span>
            <span class="slk-badge slk-badge-<?php echo esc_attr($cover_level); ?>"><?php echo esc_html($cover_label); ?></span>
            <p><?php printf(esc_html__('%1$s of %2$s posts have at least one inbound internal link.', 'smartlinker'), esc_html(number_format_i18n($stats['linked_posts'])), esc_html(number_format_i18n($stats['total_posts']))); ?></p>
            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_inbound')); ?>"><?php esc_html_e('View details', 'smartlinker'); ?></a>
        </div>
        <div class="slk-score-card">
            <span class="slk-tile-cap"><?php esc_html_e('Orphaned posts', 'smartlinker'); ?></span>
            <span class="slk-score-num <?php echo $stats['orphaned'] ? 'slk-bad' : 'slk-good'; ?>"><?php echo esc_html(number_format_i18n($stats['orphaned'])); ?></span>
            <span class="slk-badge <?php echo $stats['orphaned'] ? 'slk-badge-warn' : 'slk-badge-good'; ?>"><?php echo $stats['orphaned'] ? esc_html__('Needs Work', 'smartlinker') : esc_html__('All good', 'smartlinker'); ?></span>
            <p><?php esc_html_e('Published items with no inbound internal links.', 'smartlinker'); ?></p>
            <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_orphans')); ?>"><?php esc_html_e('Connect posts', 'smartlinker'); ?></a>
        </div>
        <div class="slk-score-card">
            <span class="slk-tile-cap"><?php esc_html_e('Broken links', 'smartlinker'); ?></span>
            <span class="slk-score-num <?php echo $stats['broken'] ? 'slk-bad' : 'slk-good'; ?>"><?php echo esc_html(number_format_i18n($stats['broken'])); ?></span>
            <span class="slk-badge <?php echo $stats['broken'] ? 'slk-badge-warn' : 'slk-badge-good'; ?>"><?php echo $stats['broken'] ? esc_html__('Needs Work', 'smartlinker') : esc_html__('All good', 'smartlinker'); ?></span>
            <p><?php esc_html_e('Links that return an error and need updating or removing.', 'smartlinker'); ?></p>
            <a class="button button-small" href="<?php echo esc_url(Slk_Reports::url('broken')); ?>"><?php esc_html_e('Fix links', 'smartlinker'); ?></a>
        </div>
    </div>

    <div class="slk-two-col">
        <div>
            <h2><?php esc_html_e('Quick actions', 'smartlinker'); ?></h2>
            <a class="slk-action" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_orphans')); ?>">
                <span class="dashicons dashicons-editor-unlink"></span><?php esc_html_e('Fix orphaned posts', 'smartlinker'); ?>
            </a>
            <a class="slk-action" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_inbound')); ?>">
                <span class="dashicons dashicons-migrate"></span><?php esc_html_e('Get linking suggestions', 'smartlinker'); ?>
            </a>
            <a class="slk-action" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_ai')); ?>">
                <span class="dashicons dashicons-superhero"></span><?php esc_html_e('Run AI suggestions', 'smartlinker'); ?>
            </a>
            <a class="slk-action" href="<?php echo esc_url(wp_nonce_url(Slk_Reports::url('overview', ['slk_rescan' => 1]), 'slk_rescan')); ?>">
                <span class="dashicons dashicons-update"></span><?php esc_html_e('Re-scan the site', 'smartlinker'); ?>
            </a>
            <a class="slk-action" href="<?php echo esc_url(wp_nonce_url(Slk_Reports::url('broken', ['slk_scan' => 1, 'offset' => 0]), 'slk_broken_scan')); ?>">
                <span class="dashicons dashicons-warning"></span><?php esc_html_e('Scan for broken links', 'smartlinker'); ?>
            </a>
        </div>
        <div>
            <h2><?php esc_html_e("Features you're not using", 'smartlinker'); ?></h2>
            <?php if (empty($unused)) : ?>
                <p class="description"><?php esc_html_e('Nice — every SmartLinker feature is set up.', 'smartlinker'); ?></p>
            <?php else : foreach ($unused as $f) : ?>
                <div class="slk-feature">
                    <div class="slk-feature-main">
                        <strong><?php echo esc_html($f['title']); ?></strong>
                        <div class="slk-row-desc"><?php echo esc_html($f['desc']); ?></div>
                    </div>
                    <a class="button button-primary button-small" href="<?php echo esc_url($f['url']); ?>"><?php echo esc_html($f['action']); ?></a>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
