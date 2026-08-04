<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $keywords */
$posts = get_posts([
    'post_type'   => Slk_Settings::enabled_post_types(),
    'post_status' => 'publish',
    'numberposts' => 500,
    'orderby'     => 'title',
    'order'       => 'ASC',
]);
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Target Keywords', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Assign a focus keyword to a post, and SmartLinker will find every other post that mentions that keyword but does not yet link to it — so you can add a keyword-anchored internal link in one click.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['added'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Target keyword added.', 'smartlinker'); ?></p></div>
    <?php elseif (!empty($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Target keyword removed.', 'smartlinker'); ?></p></div>
    <?php elseif (isset($_GET['kw_imported'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php printf(
            /* translators: 1: keywords imported, 2: source name, 3: duplicates skipped */
            esc_html__('Imported %1$d keyword(s) from %2$s. %3$d already existed and were left alone.', 'smartlinker'),
            (int) $_GET['kw_imported'],
            esc_html(Slk_KeywordImport::label(sanitize_key(wp_unslash($_GET['kw_source'] ?? '')))),
            (int) ($_GET['kw_skipped'] ?? 0)
        ); ?></p></div>
    <?php endif; ?>

    <?php if (!empty($import_sources)) : ?>
        <div class="slk-form-card">
            <h2><?php esc_html_e('Import from your SEO plugin', 'smartlinker'); ?><?php echo Slk_Admin::help([
                __('SmartLinker found focus keywords already stored by another SEO plugin on this site.', 'smartlinker'),
                __('Only the primary focus keyword of each published post is imported. Yoast Premium, Rank Math and AIOSEO can store extra keyphrases per post, but Target Keywords drives link-building — importing five phrases per post buries the one that matters.', 'smartlinker'),
                __('Nothing is written until you confirm, and keywords you already have are never duplicated.', 'smartlinker'),
            ]); ?></h2>

            <table class="slk-table">
                <thead>
                    <tr>
                        <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Source', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Found', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('New', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="width:150px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($import_sources as $src) : ?>
                    <tr class="slk-tr">
                        <td><span class="slk-title-link"><?php echo esc_html($src['label']); ?></span></td>
                        <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($src['total'])); ?></span></td>
                        <td>
                            <span class="slk-metric<?php echo $src['new'] ? '' : ' slk-metric-zero'; ?>">
                                <?php echo esc_html(number_format_i18n($src['new'])); ?>
                            </span>
                        </td>
                        <td class="slk-td-actions">
                            <?php if ($src['new']) : ?>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_target_keywords&kw_preview=' . $src['id'])); ?>">
                                    <?php esc_html_e('Preview', 'smartlinker'); ?>
                                </a>
                            <?php else : ?>
                                <span class="slk-title-meta"><?php esc_html_e('all imported', 'smartlinker'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($preview_source && !empty($preview_rows)) :
                $new_rows = array_values(array_filter($preview_rows, function ($r) { return !$r['dupe']; }));
                ?>
                <h3 style="margin-top:22px;"><?php printf(
                    /* translators: %s: name of the file or source being previewed */
                    esc_html__('Preview — %s', 'smartlinker'),
                    esc_html(Slk_KeywordImport::label($preview_source))
                ); ?></h3>
                <p class="description"><?php printf(
                    /* translators: 1: keywords to add, 2: duplicates skipped */
                    esc_html__('%1$d will be added, %2$d skipped as duplicates. Nothing has been saved yet.', 'smartlinker'),
                    count($new_rows),
                    count($preview_rows) - count($new_rows)
                ); ?></p>

                <table class="slk-table">
                    <thead>
                        <tr>
                            <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Post', 'smartlinker'); ?></th>
                            <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Keyword', 'smartlinker'); ?></th>
                            <th class="slk-th-actions" style="text-align:left;width:130px;"><?php esc_html_e('Status', 'smartlinker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($preview_rows as $row) : ?>
                        <tr class="slk-tr">
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($row['post_id'])); ?>">
                                    <?php echo esc_html($row['title'] !== '' ? $row['title'] : '(no title)'); ?>
                                </a>
                            </td>
                            <td><span class="slk-chip"><?php echo esc_html($row['keyword']); ?></span></td>
                            <td>
                                <?php if ($row['dupe']) : ?>
                                    <span class="slk-badge slk-badge-warn"><?php esc_html_e('Already have it', 'smartlinker'); ?></span>
                                <?php else : ?>
                                    <span class="slk-badge slk-badge-good"><?php esc_html_e('Will add', 'smartlinker'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <form method="post" style="margin-top:14px;">
                    <?php wp_nonce_field('slk_import_keywords'); ?>
                    <input type="hidden" name="source" value="<?php echo esc_attr($preview_source); ?>" />
                    <button type="submit" name="slk_import_keywords" value="1" class="button button-primary"
                        <?php disabled(empty($new_rows)); ?>>
                        <?php printf(
                            /* translators: %d: number of keywords to import */
                            esc_html(_n('Import %d keyword', 'Import %d keywords', count($new_rows), 'smartlinker')),
                            count($new_rows)
                        ); ?>
                    </button>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_target_keywords')); ?>"><?php esc_html_e('Cancel', 'smartlinker'); ?></a>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="slk-form-card">
    <h2><?php esc_html_e('Add a target keyword', 'smartlinker'); ?></h2>
    <form method="post">
        <?php wp_nonce_field('slk_target_keyword'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="slk-tk-post"><?php esc_html_e('Post / page', 'smartlinker'); ?></label></th>
                <td>
                    <select id="slk-tk-post" name="post_id" required class="slk-search-select" style="min-width:360px;max-width:100%;">
                        <option value=""><?php esc_html_e('— Select content to rank —', 'smartlinker'); ?></option>
                        <?php foreach ($posts as $p) : ?>
                            <option value="<?php echo esc_attr($p->ID); ?>"><?php echo esc_html($p->post_title !== '' ? $p->post_title : '(no title)'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="slk-tk-keyword"><?php esc_html_e('Target keyword', 'smartlinker'); ?></label></th>
                <td>
                    <input type="text" id="slk-tk-keyword" name="keyword" class="regular-text" required />
                    <p class="description"><?php esc_html_e('The phrase you want this content to rank for and be linked with.', 'smartlinker'); ?></p>
                </td>
            </tr>
        </table>
        <p style="margin-bottom:0;"><button type="submit" name="slk_add_keyword" value="1" class="button button-primary"><?php esc_html_e('Add Target Keyword', 'smartlinker'); ?></button></p>
    </form>
    </div>

    <h2><?php esc_html_e('Your target keywords', 'smartlinker'); ?></h2>
    <?php if (empty($keywords)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-tag"></span>
            <strong><?php esc_html_e('No target keywords yet', 'smartlinker'); ?></strong>
            <?php esc_html_e('Add a focus keyword above to find posts that mention it but don’t link to it.', 'smartlinker'); ?>
        </div>
    <?php else : ?>
    <table class="slk-table">
        <thead>
            <tr>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Keyword', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Target content', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:140px;"><?php esc_html_e('Opportunities', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="width:220px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($keywords as $kw) :
            $count = Slk_TargetKeyword::opportunity_count($kw->keyword, $kw->post_id);
            $del = wp_nonce_url(admin_url('admin.php?page=smartlinker_target_keywords&slk_delete_kw=' . $kw->id), 'slk_delete_kw');
            ?>
            <tr class="slk-tr">
                <td>
                    <div class="slk-title-wrap">
                        <span class="slk-title-link"><?php echo esc_html($kw->keyword); ?></span>
                        <div class="slk-title-meta"><span class="slk-chip"><?php esc_html_e('focus keyword', 'smartlinker'); ?></span></div>
                    </div>
                </td>
                <td>
                    <a class="slk-title-link" href="<?php echo esc_url(get_edit_post_link($kw->post_id)); ?>"><?php echo esc_html(get_the_title($kw->post_id) ?: '(missing post)'); ?></a>
                </td>
                <td>
                    <span class="slk-metric <?php echo $count > 0 ? 'slk-metric-good' : ''; ?>"><?php echo (int) $count; ?></span>
                </td>
                <td class="slk-td-actions">
                    <button type="button" class="button button-small button-primary slk-tk-find" data-keyword-id="<?php echo esc_attr($kw->id); ?>"<?php echo $count === 0 ? ' disabled' : ''; ?>>
                        <?php esc_html_e('Find opportunities', 'smartlinker'); ?>
                    </button>
                    <a class="slk-icon-btn" title="<?php esc_attr_e('Delete', 'smartlinker'); ?>" href="<?php echo esc_url($del); ?>" onclick="return confirm('<?php echo esc_js(__('Remove this target keyword?', 'smartlinker')); ?>');"><span class="dashicons dashicons-trash"></span></a>
                </td>
            </tr>
            <tr class="slk-detail-row">
                <td colspan="4" style="padding:0 !important;">
                    <div class="slk-tk-results" data-for="<?php echo esc_attr($kw->id); ?>"></div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
