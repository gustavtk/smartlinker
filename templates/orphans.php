<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows */
$count = count($rows);
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Orphaned Posts', 'smartlinker'); ?></h1>

    <div class="slk-callout<?php echo $count ? ' slk-callout-bad' : ' slk-callout-good'; ?>">
        <strong>
            <?php if ($count) : ?>
                <?php /* translators: %d: number of orphaned posts */ printf(esc_html__('%d orphaned posts found.', 'smartlinker'), $count); ?>
            <?php else : ?>
                <?php esc_html_e('No orphaned posts — every published item has at least one inbound internal link.', 'smartlinker'); ?>
            <?php endif; ?>
        </strong>
        <?php if ($count) : ?>
            <div class="slk-row-desc" style="margin-top:4px;">
                <?php esc_html_e('These posts have zero inbound internal links, so search engines have to discover them on their own. Oldest first — fix the longest-abandoned content before anything else.', 'smartlinker'); ?>
            </div>
        <?php endif; ?>
    </div>

    <p class="slk-toolbar">
        <a href="<?php echo esc_url(wp_nonce_url(Slk_Reports::url('overview', ['slk_rescan' => 1]), 'slk_rescan')); ?>" class="button"><?php esc_html_e('Re-scan site', 'smartlinker'); ?></a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_inbound')); ?>" class="button button-primary"><?php esc_html_e('Add inbound links', 'smartlinker'); ?></a>
    </p>

    <?php if ($count) : ?>
    <table class="slk-table">
        <thead>
            <tr>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Post title', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:130px;"><?php esc_html_e('Published', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:120px;"><?php esc_html_e('Outbound', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:120px;"><?php esc_html_e('Inbound', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="width:150px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) : ?>
            <tr class="slk-tr slk-tr-warn">
                <td>
                    <div class="slk-title-wrap">
                        <a class="slk-title-link" href="<?php echo esc_url(get_edit_post_link($row->ID)); ?>"><?php echo esc_html($row->post_title ?: '(no title)'); ?></a>
                        <div class="slk-title-meta"><span class="slk-chip"><?php echo esc_html($row->post_type); ?></span></div>
                    </div>
                </td>
                <td><?php echo esc_html(mysql2date('M j, Y', $row->post_date)); ?></td>
                <td><span class="slk-metric"><?php echo (int) $row->outbound; ?></span></td>
                <td><span class="slk-metric slk-metric-bad">0</span></td>
                <td class="slk-td-actions">
                    <button type="button" class="button button-small button-primary slk-fix-orphan"
                        data-target="<?php echo esc_attr($row->ID); ?>"
                        aria-expanded="false"><?php esc_html_e('Fix', 'smartlinker'); ?></button>
                    <a class="slk-icon-btn" title="<?php esc_attr_e('Edit', 'smartlinker'); ?>" href="<?php echo esc_url(get_edit_post_link($row->ID)); ?>"><span class="dashicons dashicons-edit"></span></a>
                </td>
            </tr>
            <?php
            /*
             * Fixing an orphan happens here, not on another screen. The old
             * Fix button linked to the Inbound page without even carrying the
             * post id, so you arrived and had to find the post again.
             */
            ?>
            <tr class="slk-fix-row" data-for="<?php echo esc_attr($row->ID); ?>" hidden>
                <td colspan="5">
                    <div class="slk-fix-panel"
                         data-target="<?php echo esc_attr($row->ID); ?>"
                         data-title="<?php echo esc_attr($row->post_title ?: '(no title)'); ?>"></div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-yes-alt"></span>
            <strong><?php esc_html_e('No orphaned posts', 'smartlinker'); ?></strong>
            <?php esc_html_e('Every published item has at least one internal link pointing to it.', 'smartlinker'); ?>
        </div>
    <?php endif; ?>
</div>
