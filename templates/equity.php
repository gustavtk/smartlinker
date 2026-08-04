<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $data */
/** @var string $view */
$rows = $data['rows'];
$stats = $data['stats'];
$base = Slk_Reports::url('equity');
$rebuild = wp_nonce_url(Slk_Reports::url('equity', ['slk_equity_rebuild' => 1]), 'slk_equity_rebuild');

$views = [
    'all'         => __('All pages', 'smartlinker'),
    'starved'     => __('Starved of value', 'smartlinker'),
    'buried'      => __('Buried deep', 'smartlinker'),
    'unreachable' => __('No content-link route', 'smartlinker'),
    'hoarding'    => __('Holding the most', 'smartlinker'),
];

if ($view === 'starved') {
    $rows = array_values(array_filter($rows, function ($r) {
        return $r['relative'] < 0.5 && $r['depth'] !== null;
    }));
    usort($rows, function ($a, $b) {
        return $a['relative'] <=> $b['relative'];
    });
} elseif ($view === 'buried') {
    $rows = array_values(array_filter($rows, function ($r) {
        return $r['depth'] !== null && $r['depth'] > Slk_Equity::DEEP;
    }));
    usort($rows, function ($a, $b) {
        return $b['depth'] <=> $a['depth'];
    });
} elseif ($view === 'unreachable') {
    $rows = array_values(array_filter($rows, function ($r) {
        return $r['depth'] === null;
    }));
} elseif ($view === 'hoarding') {
    $rows = array_slice($rows, 0, 25);
}
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Link Equity', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Where link value pools on your site, and how far each page sits from the front door. Orphans are pages with no inbound links; these are the pages whose inbound links are themselves worth very little — nearly as invisible, and far harder to spot by eye.', 'smartlinker'); ?>
    </p>
    <p class="description" style="margin:-8px 0 16px;max-width:80ch;">
        <?php esc_html_e('Both measures follow links written inside your post content — the ones you and SmartLinker place. Menus, category archives, blog pagination and your sitemap are not counted, so a page shown here as having no route is still findable by other means. What it does not have is an editorial path, and that is what carries link value.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['rebuilt'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Recalculated.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['posts'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Pages in the graph', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['avg_depth'], 1)); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Average clicks deep', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $stats['deep'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($stats['deep'])); ?></span>
            <span class="slk-tile-label"><?php printf(esc_html__('More than %d clicks', 'smartlinker'), (int) Slk_Equity::DEEP); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $stats['unreachable'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($stats['unreachable'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('No content-link route', 'smartlinker'); ?></span>
        </div>
    </div>

    <div class="slk-tabs slk-tabs-underline" style="margin-bottom:14px;">
        <?php foreach ($views as $key => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg('view', $key, $base)); ?>"
               class="slk-tab<?php echo $view === $key ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a class="button" href="<?php echo esc_url($rebuild); ?>"><?php esc_html_e('Recalculate', 'smartlinker'); ?></a>
        <?php if (!empty($rows)) : ?>
            <a class="button" href="<?php echo esc_url(Slk_CSV::export_url('equity')); ?>"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
        <?php endif; ?>
        <span class="description">
            <?php
            printf(
                /* translators: 1: date, 2: where depth is measured from */
                esc_html__('Built %1$s. Depth is counted from %2$s.', 'smartlinker'),
                esc_html(mysql2date('j M Y, H:i', $data['generated'])),
                $stats['front'] === 'page'
                    ? esc_html__('your static front page', 'smartlinker')
                    : esc_html(sprintf(
                        /* translators: %d: number of posts */
                        _n('the %d post listed on your blog home page', 'the %d posts listed on your blog home page', $stats['seeds'], 'smartlinker'),
                        $stats['seeds']
                    ))
            );
            ?>
        </span>
    </p>

    <?php if ($view === 'starved') : ?>
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e('Pages holding less than half the link value an average page here holds. Either few pages link to them, or the pages that do are themselves weak. Linking to these from a strong, related article is the single most useful thing you can do with an internal link.', 'smartlinker'); ?>
        </p>
    <?php elseif ($view === 'buried') : ?>
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e('Pages a visitor — or a crawler — has to click through several others to reach. Depth is about routes, not authority: a page can hold decent value and still be hard to arrive at.', 'smartlinker'); ?>
        </p>
    <?php elseif ($view === 'unreachable') : ?>
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e('No chain of in-content links reaches these from the front page. They are still reachable through your menu, category archives, blog pagination and sitemap — this is not "invisible to Google". What it means is that no article leads to them, so no link value flows their way. On a blog whose home page lists recent posts, older articles land here routinely; that is the honest shape of most sites, not an emergency.', 'smartlinker'); ?>
        </p>
    <?php elseif ($view === 'hoarding') : ?>
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e('Where value has collected. These are your strongest pages internally — which makes them the best places to link OUT from when you want to lift something else.', 'smartlinker'); ?>
        </p>
    <?php endif; ?>

    <?php if (empty($rows)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-chart-area"></span>
            <strong><?php esc_html_e('Nothing to show', 'smartlinker'); ?></strong>
            <?php
            echo $stats['posts'] === 0
                ? esc_html__('No published posts have been indexed yet. Run a link scan from the Overview tab.', 'smartlinker')
                : esc_html__('Nothing matches this view — which is the good outcome.', 'smartlinker');
            ?>
        </div>
    <?php else : ?>
        <table class="slk-table slk-equity-table">
            <thead>
                <tr>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Page', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:190px;"><?php esc_html_e('Share of link value', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Clicks deep', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:90px;"><?php esc_html_e('In', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:90px;"><?php esc_html_e('Out', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($rows, 0, 300) as $r) :
                $rel = $r['relative'];
                // The bar is capped at 3× average so one dominant page does not
                // flatten every other bar into invisibility.
                $pct = max(1, min(100, ($rel / 3) * 100));
                $tone = $rel >= 1.0 ? 'slk-conf-high' : ($rel >= 0.5 ? 'slk-conf-mid' : 'slk-conf-low');
                ?>
                <tr class="slk-tr">
                    <td>
                        <a class="slk-title-link" href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($r['title']); ?></a>
                        <?php if ($r['seed']) : ?>
                            <span class="slk-badge slk-badge-good"><?php esc_html_e('front page', 'smartlinker'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="slk-equity-bar"><span class="<?php echo esc_attr($tone); ?>" style="width:<?php echo esc_attr(number_format($pct, 1)); ?>%"></span></div>
                        <div class="slk-title-meta">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: multiple of an average page */
                                __('%s× an average page', 'smartlinker'),
                                number_format($rel, 2)
                            ));
                            ?>
                        </div>
                    </td>
                    <td>
                        <?php if ($r['depth'] === null) : ?>
                            <span class="slk-badge slk-badge-warn" title="<?php esc_attr_e('No path of in-content links reaches this from the front page. Menus, archives and pagination are not counted.', 'smartlinker'); ?>"><?php esc_html_e('no route', 'smartlinker'); ?></span>
                        <?php else : ?>
                            <span class="<?php echo $r['depth'] > Slk_Equity::DEEP ? 'slk-metric-warn' : ''; ?>">
                                <?php echo esc_html(number_format_i18n($r['depth'])); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(number_format_i18n($r['inbound'])); ?></td>
                    <td><?php echo esc_html(number_format_i18n($r['outbound'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (count($rows) > 300) : ?>
            <p class="description"><?php printf(esc_html__('Showing the first 300 of %s.', 'smartlinker'), esc_html(number_format_i18n(count($rows)))); ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
