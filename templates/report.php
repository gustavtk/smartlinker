<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $summary @var array $rows @var int $total @var array $args */
$base = Slk_Reports::url('overview');
$rescan = wp_nonce_url(add_query_arg('slk_rescan', 1, $base), 'slk_rescan');

$per_page = max(5, min(200, (int) $args['per_page']));
$pages = max(1, (int) ceil($total / $per_page));
$page = min(max(1, (int) $args['page']), $pages);

/** Keep the current query state when building a link. */
$keep = [
    's'        => $args['search'],
    'filter'   => $args['filter'],
    'orderby'  => $args['orderby'],
    'order'    => $args['order'],
    'per_page' => $per_page,
];

/** Sortable column header. */
if (!function_exists('slk_sort_th')) :
function slk_sort_th($label, $key, $args, $base, $keep, $help = '')
{
    $active = $args['orderby'] === $key;
    $next = ($active && strtolower($args['order']) === 'asc') ? 'desc' : 'asc';
    $url = add_query_arg(array_merge($keep, ['orderby' => $key, 'order' => $next, 'paged' => 1]), $base);
    $arrow = $active ? ($args['order'] === 'asc' ? '▲' : '▼') : '';
    echo '<th class="slk-th-sort' . ($active ? ' active' : '') . '">';
    echo '<a href="' . esc_url($url) . '">' . esc_html($label);
    if (!empty($help)) {
        echo Slk_Admin::help($help);
    }
    echo ' <span class="slk-arrow">' . $arrow . '</span></a></th>';
}
endif;

$filters = [
    'all'         => __('All content', 'smartlinker'),
    'orphaned'    => __('Orphaned', 'smartlinker'),
    'no_outbound' => __('No outbound links', 'smartlinker'),
    'money'       => __('Money pages', 'smartlinker'),
];
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Links Report', 'smartlinker'); ?></h1>

    <?php if (isset($_GET['rescanned'])) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php /* translators: %d: number of posts re-scanned */ printf(esc_html__('Re-scanned %d posts.', 'smartlinker'), (int) $_GET['rescanned']); ?></p>
        </div>
    <?php endif; ?>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-cap"><?php esc_html_e('Published items', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($summary['total_posts'])); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-cap"><?php esc_html_e('Internal links', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($summary['internal'])); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-cap"><?php esc_html_e('External links', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($summary['external'])); ?></span>
        </div>
        <a class="slk-tile<?php echo $summary['orphaned'] ? ' slk-tile-bad' : ''; ?>" style="text-decoration:none;"
           href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_orphans')); ?>">
            <span class="slk-tile-cap"><?php esc_html_e('Orphaned', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($summary['orphaned'])); ?></span>
        </a>
        <a class="slk-tile<?php echo $summary['broken'] ? ' slk-tile-bad' : ''; ?>" style="text-decoration:none;"
           href="<?php echo esc_url(Slk_Reports::url('broken')); ?>">
            <span class="slk-tile-cap"><?php esc_html_e('Broken links', 'smartlinker'); ?></span>
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($summary['broken'])); ?></span>
        </a>
    </div>

    <div class="slk-table-toolbar">
        <form method="get" class="slk-toolbar-left">
            <input type="hidden" name="page" value="smartlinker_reports" />
            <input type="hidden" name="orderby" value="<?php echo esc_attr($args['orderby']); ?>" />
            <input type="hidden" name="order" value="<?php echo esc_attr($args['order']); ?>" />
            <div class="slk-search">
                <span class="dashicons dashicons-search"></span>
                <input type="search" name="s" value="<?php echo esc_attr($args['search']); ?>"
                       placeholder="<?php esc_attr_e('Search titles…', 'smartlinker'); ?>" />
            </div>
            <select name="filter" onchange="this.form.submit()">
                <?php foreach ($filters as $key => $label) : ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($args['filter'], $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="per_page" onchange="this.form.submit()">
                <?php foreach ([20, 50, 100] as $n) : ?>
                    <option value="<?php /* translators: %d: rows shown per page */ echo $n; ?>" <?php selected($per_page, $n); ?>><?php printf(esc_html__('%d per page', 'smartlinker'), $n); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button"><?php esc_html_e('Apply', 'smartlinker'); ?></button>
        </form>
        <div class="slk-toolbar-right">
            <a href="<?php echo esc_url($rescan); ?>" data-slk-scan="links" data-slk-label="Indexing links" class="button button-primary"><?php esc_html_e('Run a link scan', 'smartlinker'); ?></a>
            <a href="<?php echo esc_url(Slk_CSV::export_url('report')); ?>" class="button"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
        </div>
    </div>

    <div class="slk-table-meta">
        <?php /* translators: %s: number of rows, already formatted */ printf(esc_html(_n('%s item', '%s items', $total, 'smartlinker')), '<strong>' . esc_html(number_format_i18n($total)) . '</strong>'); ?>
        <?php if ($args['search'] !== '' || $args['filter'] !== 'all') : ?>
            &nbsp;·&nbsp;<a href="<?php echo esc_url($base); ?>"><?php esc_html_e('Clear filters', 'smartlinker'); ?></a>
        <?php endif; ?>
    </div>

    <table class="slk-table">
        <thead>
            <tr>
                <?php
                slk_sort_th(__('Title', 'smartlinker'), 'title', $args, $base, $keep);
                slk_sort_th(__('Inbound', 'smartlinker'), 'inbound', $args, $base, $keep, __('Internal links from other posts pointing at this one.', 'smartlinker'));
                slk_sort_th(__('Outbound', 'smartlinker'), 'outbound', $args, $base, $keep, __('Internal links this post sends to other content.', 'smartlinker'));
                slk_sort_th(__('External', 'smartlinker'), 'external', $args, $base, $keep, __('Links this post sends to other websites.', 'smartlinker'));
                slk_sort_th(__('Clicks', 'smartlinker'), 'clicks', $args, $base, $keep, __('Tracked clicks on internal links pointing here.', 'smartlinker'));
                ?>
                <th class="slk-th-actions"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)) : ?>
            <tr><td colspan="6" class="slk-empty"><?php esc_html_e('Nothing matches these filters.', 'smartlinker'); ?></td></tr>
        <?php else : foreach ($rows as $row) :
            $inbound = (int) $row->inbound;
            $is_money = Slk_MoneyPage::is_money_page($row->ID);
            ?>
            <tr class="slk-tr<?php echo $inbound === 0 ? ' slk-tr-warn' : ''; ?>" data-post="<?php echo esc_attr($row->ID); ?>">
                <td class="slk-td-title">
                    <button type="button" class="slk-expand" aria-label="<?php esc_attr_e('Show links', 'smartlinker'); ?>">+</button>
                    <div class="slk-title-wrap">
                        <a class="slk-title-link" href="<?php echo esc_url(get_edit_post_link($row->ID)); ?>"><?php echo esc_html($row->post_title ?: '(no title)'); ?></a>
                        <div class="slk-title-meta">
                            <span class="slk-chip"><?php echo esc_html($row->post_type); ?></span>
                            <?php if ($is_money) : ?><span class="slk-chip slk-chip-money">★ <?php esc_html_e('Money page', 'smartlinker'); ?></span><?php endif; ?>
                            <span><?php echo esc_html(mysql2date('M j, Y', $row->post_date)); ?></span>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if ($inbound === 0) : ?>
                        <span class="slk-metric slk-metric-bad">0</span>
                    <?php else : ?>
                        <span class="slk-metric slk-metric-good"><?php echo esc_html(number_format_i18n($inbound)); ?></span>
                    <?php endif; ?>
                </td>
                <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($row->outbound)); ?></span></td>
                <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($row->external)); ?></span></td>
                <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($row->clicks)); ?></span></td>
                <td class="slk-td-actions">
                    <a class="slk-icon-btn" title="<?php esc_attr_e('Edit', 'smartlinker'); ?>" href="<?php echo esc_url(get_edit_post_link($row->ID)); ?>"><span class="dashicons dashicons-edit"></span></a>
                    <a class="slk-icon-btn" title="<?php esc_attr_e('View', 'smartlinker'); ?>" href="<?php echo esc_url(get_permalink($row->ID)); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-visibility"></span></a>
                    <a class="slk-icon-btn" title="<?php esc_attr_e('Add inbound links', 'smartlinker'); ?>" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_inbound')); ?>"><span class="dashicons dashicons-migrate"></span></a>
                </td>
            </tr>
            <tr class="slk-detail-row" data-detail="<?php echo esc_attr($row->ID); ?>" hidden>
                <td colspan="6"><div class="slk-detail"><?php esc_html_e('Loading…', 'smartlinker'); ?></div></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ($pages > 1) : ?>
        <div class="slk-pagination">
            <?php
            $prev = add_query_arg(array_merge($keep, ['paged' => max(1, $page - 1)]), $base);
            $next = add_query_arg(array_merge($keep, ['paged' => min($pages, $page + 1)]), $base);
            ?>
            <a class="slk-page-btn<?php echo $page <= 1 ? ' disabled' : ''; ?>" href="<?php echo esc_url($prev); ?>">‹ <?php esc_html_e('Previous', 'smartlinker'); ?></a>
            <span class="slk-page-info"><?php /* translators: 1: current page number, 2: total pages */ printf(esc_html__('Page %1$s of %2$s', 'smartlinker'), number_format_i18n($page), number_format_i18n($pages)); ?></span>
            <a class="slk-page-btn<?php echo $page >= $pages ? ' disabled' : ''; ?>" href="<?php echo esc_url($next); ?>"><?php esc_html_e('Next', 'smartlinker'); ?> ›</a>
        </div>
    <?php endif; ?>
</div>
