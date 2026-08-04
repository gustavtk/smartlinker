<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows @var string $detail_host @var array $detail */
$base = Slk_Reports::url('domains');
$total_links = 0;
foreach ($rows as $r) {
    $total_links += (int) $r->links;
}
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Domain Report', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Every external domain you link to across the site. Use it to audit your outbound link profile and see where your affiliate or reference links are concentrated.', 'smartlinker'); ?>
    </p>

    <div class="slk-stat-tiles">
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n(count($rows))); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('External domains', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($total_links)); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Outbound external links', 'smartlinker'); ?></span>
        </div>
    </div>

    <?php if (empty($rows)) : ?>
        <p class="description"><?php esc_html_e('No external links indexed yet. Run a re-scan from the Links Report.', 'smartlinker'); ?></p>
    <?php else : ?>
        <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a class="button" href="<?php echo esc_url(Slk_CSV::export_url('domains')); ?>"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
        </p>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Domain', 'smartlinker'); ?></th>
                    <th style="width:120px;"><?php esc_html_e('Links', 'smartlinker'); ?></th>
                    <th style="width:140px;"><?php esc_html_e('Posts', 'smartlinker'); ?></th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) : ?>
                <tr<?php echo $detail_host === $row->host ? ' class="slk-row-active"' : ''; ?>>
                    <td><strong><?php echo esc_html($row->host); ?></strong></td>
                    <td><?php echo esc_html(number_format_i18n($row->links)); ?></td>
                    <td><?php echo esc_html(number_format_i18n($row->posts)); ?></td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url(add_query_arg('host', rawurlencode($row->host), $base)); ?>">
                            <?php esc_html_e('Breakdown', 'smartlinker'); ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($detail_host !== '') : ?>
        <h2><?php printf(esc_html__('Links to %s', 'smartlinker'), esc_html($detail_host)); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Found in', 'smartlinker'); ?></th>
                    <th><?php esc_html_e('Anchor', 'smartlinker'); ?></th>
                    <th><?php esc_html_e('URL', 'smartlinker'); ?></th>
                    <th style="width:70px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($detail)) : ?>
                <tr><td colspan="4"><?php esc_html_e('No links found.', 'smartlinker'); ?></td></tr>
            <?php else : foreach ($detail as $d) : ?>
                <tr>
                    <td><strong><?php echo esc_html($d->post_title ?: '(no title)'); ?></strong></td>
                    <td><?php echo esc_html($d->anchor ?: '—'); ?></td>
                    <td style="word-break:break-all;"><?php echo esc_html($d->url); ?></td>
                    <td><a class="button button-small" href="<?php echo esc_url(get_edit_post_link($d->post_id)); ?>"><?php esc_html_e('Edit', 'smartlinker'); ?></a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
