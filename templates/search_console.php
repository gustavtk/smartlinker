<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Search Console — Link Priorities', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Import your Google Search Console “Pages” export (Search results → Pages → Export → CSV). SmartLinker cross-references impressions with internal links to show which high-visibility pages are under-linked — your best internal-linking opportunities.', 'smartlinker'); ?>
    </p>

    <?php if (isset($_GET['gsc_imported'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php /* translators: %d: number of pages imported */ printf(esc_html__('Imported %d pages from Search Console.', 'smartlinker'), (int) $_GET['gsc_imported']); ?></p></div>
    <?php elseif (!empty($_GET['gsc_err'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Could not read that CSV. Export the Pages report from Search Console and upload the CSV.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-form-card">
        <h2><?php esc_html_e('Import Search Console data', 'smartlinker'); ?></h2>
        <form method="post" enctype="multipart/form-data" class="slk-inline-form">
            <?php wp_nonce_field('slk_gsc_import'); ?>
            <input type="file" name="gsc_csv" accept=".csv,text/csv" required />
            <button type="submit" name="slk_gsc_import" value="1" class="button button-primary">
                <span class="dashicons dashicons-upload"></span> <?php esc_html_e('Import CSV', 'smartlinker'); ?>
            </button>
        </form>
        <p class="slk-field-hint"><?php esc_html_e('Expected columns: Top pages, Clicks, Impressions, CTR, Position.', 'smartlinker'); ?></p>
    </div>

    <?php if (empty($rows)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-search"></span>
            <strong><?php esc_html_e('No Search Console data yet', 'smartlinker'); ?></strong>
            <?php esc_html_e('Import your Pages export to see which high-impression pages need more internal links.', 'smartlinker'); ?>
        </div>
    <?php else : ?>
        <table class="slk-table">
            <thead>
                <tr>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Page', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:120px;"><?php esc_html_e('Impressions', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:100px;"><?php esc_html_e('Clicks', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Avg. pos.', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Inbound', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="width:190px;"><?php esc_html_e('Opportunity', 'smartlinker'); ?><?php echo Slk_Admin::help(__('Pages with 100+ impressions but fewer than 3 inbound internal links. Google already shows them — they just need more internal support.', 'smartlinker')); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) :
                $inbound = (int) $r->inbound;
                // High impressions + few inbound links = strong opportunity.
                $priority = ($r->impressions >= 100 && $inbound < 3);
                ?>
                <tr class="slk-tr<?php echo $priority ? ' slk-tr-warn' : ''; ?>">
                    <td class="slk-detail-sub">
                        <?php if ($r->post_id) : ?>
                            <a class="slk-title-link" href="<?php echo esc_url(get_edit_post_link($r->post_id)); ?>"><?php echo esc_html($r->url); ?></a>
                        <?php else : ?>
                            <?php echo esc_html($r->url); ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($r->impressions)); ?></span></td>
                    <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($r->clicks)); ?></span></td>
                    <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($r->position, 1)); ?></span></td>
                    <td><span class="slk-metric <?php echo $inbound === 0 ? 'slk-metric-bad' : ($inbound >= 3 ? 'slk-metric-good' : ''); ?>"><?php echo $inbound; ?></span></td>
                    <td class="slk-td-actions">
                        <?php if ($priority && $r->post_id) : ?>
                            <a class="button button-small button-primary" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_inbound')); ?>"><?php esc_html_e('Add links', 'smartlinker'); ?></a>
                        <?php elseif ($priority) : ?>
                            <span class="slk-badge slk-badge-warn"><?php esc_html_e('Under-linked', 'smartlinker'); ?></span>
                        <?php else : ?>
                            <span class="slk-badge slk-badge-good"><?php esc_html_e('Healthy', 'smartlinker'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
