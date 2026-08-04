<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows @var array $candidates */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Money Pages', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Mark your most important revenue pages. SmartLinker tracks how many inbound internal links each one has so you can spot — and fix — the gaps first. Weakest pages are listed at the top.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['added'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Money page added.', 'smartlinker'); ?></p></div>
    <?php elseif (!empty($_GET['removed'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Money page removed.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-form-card">
        <h2><?php esc_html_e('Add a money page', 'smartlinker'); ?></h2>
        <form method="post" class="slk-inline-form">
            <?php wp_nonce_field('slk_money'); ?>
            <select name="post_id" required style="min-width:340px;">
                <option value=""><?php esc_html_e('— Select a page —', 'smartlinker'); ?></option>
                <?php foreach ($candidates as $c) : ?>
                    <option value="<?php echo esc_attr($c->ID); ?>"><?php echo esc_html($c->post_title !== '' ? $c->post_title : '(no title)'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" name="slk_add_money" value="1" class="button button-primary">★ <?php esc_html_e('Mark as Money Page', 'smartlinker'); ?></button>
        </form>
    </div>

    <h2><?php esc_html_e('Your money pages', 'smartlinker'); ?></h2>
    <?php if (empty($rows)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-star-empty"></span>
            <strong><?php esc_html_e('No money pages yet', 'smartlinker'); ?></strong>
            <?php esc_html_e('Mark the pages that earn you money and SmartLinker will track how well they are linked.', 'smartlinker'); ?>
        </div>
    <?php else : ?>
    <table class="slk-table">
        <thead>
            <tr>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Page', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:130px;"><?php esc_html_e('Inbound', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:150px;"><?php esc_html_e('Status', 'smartlinker'); ?><?php echo Slk_Admin::help(__('Based on inbound internal links: 0 = Needs links, 1–2 = Under-linked, 3 or more = Healthy.', 'smartlinker')); ?></th>
                <th class="slk-th-actions" style="width:200px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row) :
            $inbound = (int) $row->inbound;
            $remove = wp_nonce_url(admin_url('admin.php?page=smartlinker_money_pages&slk_remove_money=' . $row->ID), 'slk_remove_money');
            if ($inbound === 0) {
                $badge = ['slk-badge-bad', __('Needs links', 'smartlinker')];
                $metric = 'slk-metric-bad';
            } elseif ($inbound < 3) {
                $badge = ['slk-badge-warn', __('Under-linked', 'smartlinker')];
                $metric = '';
            } else {
                $badge = ['slk-badge-good', __('Healthy', 'smartlinker')];
                $metric = 'slk-metric-good';
            }
            ?>
            <tr class="slk-tr<?php echo $inbound === 0 ? ' slk-tr-warn' : ''; ?>">
                <td>
                    <div class="slk-title-wrap">
                        <a class="slk-title-link" href="<?php echo esc_url(Slk_Admin::edit_url($row->ID)); ?>"><?php echo esc_html(get_the_title($row->ID) ?: '(no title)'); ?></a>
                        <div class="slk-title-meta"><span class="slk-chip slk-chip-money">★ <?php esc_html_e('Money page', 'smartlinker'); ?></span></div>
                    </div>
                </td>
                <td><span class="slk-metric <?php echo esc_attr($metric); ?>"><?php echo esc_html(number_format_i18n($inbound)); ?></span></td>
                <td><span class="slk-badge <?php echo esc_attr($badge[0]); ?>"><?php echo esc_html($badge[1]); ?></span></td>
                <td class="slk-td-actions">
                    <a class="button button-small button-primary" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_inbound')); ?>"><?php esc_html_e('Add links', 'smartlinker'); ?></a>
                    <a class="slk-icon-btn" title="<?php esc_attr_e('Remove', 'smartlinker'); ?>" href="<?php echo esc_url($remove); ?>"><span class="dashicons dashicons-no-alt"></span></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
