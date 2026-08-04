<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $sites */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('External Sites', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Import another site’s XML sitemap (e.g. a sister site you own). Its pages become suggestion targets, so when you scan a post SmartLinker can also propose relevant links to that external content.', 'smartlinker'); ?>
    </p>

    <?php if (isset($_GET['imported'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php /* translators: %d: number of URLs imported */ printf(esc_html__('Imported %d URLs from the sitemap.', 'smartlinker'), (int) $_GET['imported']); ?></p></div>
    <?php elseif (!empty($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Site removed.', 'smartlinker'); ?></p></div>
    <?php elseif (!empty($_GET['err'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Please enter a valid sitemap URL.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-form-card">
    <h2><?php esc_html_e('Add a sitemap', 'smartlinker'); ?></h2>
    <form method="post">
        <?php wp_nonce_field('slk_sitemap'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="slk-sitemap-url"><?php esc_html_e('Sitemap URL', 'smartlinker'); ?></label></th>
                <td><input type="url" id="slk-sitemap-url" name="sitemap_url" class="large-text code" placeholder="https://sister-site.com/sitemap.xml" required /></td>
            </tr>
            <tr>
                <th scope="row"><label for="slk-site-label"><?php esc_html_e('Site label', 'smartlinker'); ?></label></th>
                <td><input type="text" id="slk-site-label" name="site_label" class="regular-text" placeholder="<?php esc_attr_e('e.g. My Other Blog', 'smartlinker'); ?>" />
                    <p class="description"><?php esc_html_e('Optional. Shown next to suggested external links. Defaults to the site’s domain.', 'smartlinker'); ?></p>
                </td>
            </tr>
        </table>
        <p><button type="submit" name="slk_add_sitemap" value="1" class="button button-primary"><?php esc_html_e('Fetch & Import Sitemap', 'smartlinker'); ?></button></p>
    </form>

    <h2><?php esc_html_e('Imported sites', 'smartlinker'); ?></h2>
    <?php if (empty($sites)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-admin-site-alt3"></span>
            <strong><?php esc_html_e('No external sites yet', 'smartlinker'); ?></strong>
            <?php esc_html_e('Import a sitemap above to suggest links to another site you own.', 'smartlinker'); ?>
        </div>
    <?php else : ?>
    <table class="slk-table">
        <thead>
            <tr>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Site', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:120px;"><?php esc_html_e('URLs', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:200px;"><?php esc_html_e('Imported', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="width:110px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sites as $site) :
            $del = wp_nonce_url(admin_url('admin.php?page=smartlinker_external&slk_del_site=' . rawurlencode($site->site_label)), 'slk_del_site');
            ?>
            <tr class="slk-tr">
                <td>
                    <div class="slk-title-wrap">
                        <span class="slk-title-link"><?php echo esc_html($site->site_label); ?></span>
                        <div class="slk-title-meta"><span class="slk-chip"><?php esc_html_e('external site', 'smartlinker'); ?></span></div>
                    </div>
                </td>
                <td><span class="slk-metric"><?php echo (int) $site->url_count; ?></span></td>
                <td><?php echo esc_html(mysql2date('M j, Y g:ia', $site->imported)); ?></td>
                <td class="slk-td-actions">
                    <a class="slk-icon-btn" title="<?php esc_attr_e('Delete', 'smartlinker'); ?>" href="<?php echo esc_url($del); ?>" onclick="return confirm('<?php echo esc_js(__('Remove this site and all its URLs?', 'smartlinker')); ?>');"><span class="dashicons dashicons-trash"></span></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
