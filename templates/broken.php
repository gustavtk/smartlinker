<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows @var array $counts @var string $filter @var string $last_scan */
$scan = wp_nonce_url(Slk_Reports::url('broken', ['slk_scan' => 1, 'offset' => 0]), 'slk_broken_scan');
$base = Slk_Reports::url('broken');

$tabs = [
    /* translators: %d: total number of links */
    'all'      => sprintf(__('All (%d)', 'smartlinker'), $counts['total']),
    '404'      => __('404 Only', 'smartlinker'),
    'redirect' => __('Redirects', 'smartlinker'),
    'timeout'  => __('Timeouts', 'smartlinker'),
];
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Broken Links', 'smartlinker'); ?></h1>

    <?php if (!empty($_GET['scanned'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Scan complete.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <p class="slk-intro">
        <?php esc_html_e('Every broken link is a dead end for users and wastes crawl budget. SmartLinker checks each link and reports the exact response.', 'smartlinker'); ?>
        <?php if ($last_scan) : ?>
            <br><?php /* translators: %s: date and time of the last scan */ printf(esc_html__('Last scan: %s.', 'smartlinker'), esc_html($last_scan)); ?>
        <?php endif; ?>
    </p>

    <div class="slk-stat-tiles">
        <div class="slk-tile slk-tile-bad">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['404'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('404 Not Found', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile slk-tile-warn">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['redirect'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Redirects', 'smartlinker'); ?><?php echo Slk_Admin::help(__('These links still work, but each one costs an extra hop for visitors and search crawlers. Updating them to the final URL is a quick win.', 'smartlinker')); ?></span>
        </div>
        <div class="slk-tile slk-tile-warn">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['timeout'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Timeout / errors', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['other'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Other errors', 'smartlinker'); ?></span>
        </div>
    </div>

    <p class="slk-toolbar">
        <a href="<?php echo esc_url($scan); ?>" data-slk-scan="broken" data-slk-label="Checking links" class="button button-primary"><?php esc_html_e('Scan for broken links', 'smartlinker'); ?></a>
        <?php if (!empty($rows)) : ?>
            <a href="<?php echo esc_url(Slk_CSV::export_url('broken')); ?>" class="button"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
        <?php endif; ?>
    </p>

    <div class="slk-tabs">
        <?php foreach ($tabs as $key => $label) : ?>
            <?php // Numeric array keys become ints in PHP — compare as strings. ?>
            <a href="<?php echo esc_url(add_query_arg('filter', (string) $key, $base)); ?>"
               class="slk-tab<?php echo $filter === (string) $key ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($rows)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-yes-alt"></span>
            <strong><?php esc_html_e('Nothing to fix here', 'smartlinker'); ?></strong>
            <?php esc_html_e('No links match this filter.', 'smartlinker'); ?>
        </div>
    <?php else : ?>
    <table class="slk-table">
        <thead>
            <tr>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Found in', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Anchor', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('URL', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Status', 'smartlinker'); ?><?php echo Slk_Admin::help(__('The HTTP response the link returned. 404 = page not found, 5xx = server error, 3xx = redirect.', 'smartlinker')); ?></th>
                <th class="slk-th-actions" style="width:110px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) :
            $code = (int) $row->status_code;
            $cls = 'slk-badge-bad';
            if ($row->broken_type === 'redirect') {
                $cls = 'slk-badge-warn';
            } elseif ($row->broken_type === 'timeout' || $row->broken_type === 'error') {
                $cls = 'slk-badge-warn';
            }
            $code_label = $code > 0 ? $code : strtoupper($row->broken_type);
            ?>
            <tr class="slk-tr<?php echo $row->broken_type === 'redirect' ? '' : ' slk-tr-warn'; ?>">
                <td><a class="slk-title-link" href="<?php echo esc_url(Slk_Admin::edit_url($row->post_id)); ?>"><?php echo esc_html($row->post_title ?: '(no title)'); ?></a></td>
                <td><?php echo esc_html($row->anchor ?: '—'); ?></td>
                <td class="slk-detail-sub"><a href="<?php echo esc_url($row->url); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html($row->url); ?></a></td>
                <td><span class="slk-badge <?php echo esc_attr($cls); ?>"><?php echo esc_html($code_label); ?></span></td>
                <td class="slk-td-actions">
                    <button type="button" class="button button-small button-primary slk-fix-link"
                        data-link="<?php echo esc_attr($row->id); ?>"
                        aria-expanded="false"><?php esc_html_e('Fix', 'smartlinker'); ?></button>
                </td>
            </tr>
            <?php // Repairs happen here; the editor is for writing, not chores. ?>
            <tr class="slk-fix-row" data-for="<?php echo esc_attr($row->id); ?>" hidden>
                <td colspan="5"><div class="slk-fixlink-panel" data-link="<?php echo esc_attr($row->id); ?>"></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
