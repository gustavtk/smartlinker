<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rules */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Auto-Linking Rules', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Automatically turn a keyword into a link everywhere it appears in your content. Rules are applied on the frontend and never modify your stored posts.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['added'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Rule added.', 'smartlinker'); ?></p></div>
    <?php elseif (!empty($_GET['deleted'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Rule deleted.', 'smartlinker'); ?></p></div>
    <?php elseif (isset($_GET['imported'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php printf(
            /* translators: 1: rules imported, 2: rows skipped */
            esc_html__('Imported %1$d rule(s), skipped %2$d.', 'smartlinker'),
            (int) $_GET['imported'],
            (int) ($_GET['skipped'] ?? 0)
        ); ?></p></div>
    <?php elseif (!empty($_GET['import_err'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Could not read the uploaded CSV file.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-form-card" style="display:flex;flex-wrap:wrap;gap:18px;align-items:center;padding:16px 20px;">
        <a class="button" href="<?php echo esc_url(Slk_CSV::export_url('autolinks')); ?>">
            <span class="dashicons dashicons-download"></span>
            <?php esc_html_e('Export rules (CSV)', 'smartlinker'); ?>
        </a>
        <form method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;margin:0;">
            <?php wp_nonce_field('slk_import_autolinks'); ?>
            <input type="file" name="csv" accept=".csv,text/csv" required />
            <button type="submit" name="slk_import_autolinks" value="1" class="button"><?php esc_html_e('Import rules (CSV)', 'smartlinker'); ?></button>
        </form>
        <span class="description"><?php esc_html_e('CSV columns: keyword, url, case_sensitive, partial_match, new_tab, nofollow, max_per_post, active', 'smartlinker'); ?></span>
    </div>

    <div class="slk-form-card">
    <h2><?php esc_html_e('Add a rule', 'smartlinker'); ?></h2>
    <form method="post" class="slk-autolink-form">
        <?php wp_nonce_field('slk_autolink'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="slk-keyword"><?php esc_html_e('Keyword', 'smartlinker'); ?></label></th>
                <td><input type="text" id="slk-keyword" name="keyword" class="regular-text" required /></td>
            </tr>
            <tr>
                <th scope="row"><label for="slk-url"><?php esc_html_e('Destination URL', 'smartlinker'); ?></label></th>
                <td><input type="url" id="slk-url" name="url" class="regular-text" placeholder="https://…" required /></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Options', 'smartlinker'); ?></th>
                <td>
                    <label><input type="checkbox" name="case_sensitive" value="1" /> <?php esc_html_e('Case sensitive', 'smartlinker'); ?></label><br>
                    <label><input type="checkbox" name="partial_match" value="1" /> <?php esc_html_e('Match inside words (partial)', 'smartlinker'); ?></label><?php echo Slk_Admin::help(__('Off, "cat" only matches the whole word "cat". On, it also matches inside words like "catalogue" — usually not what you want.', 'smartlinker')); ?><br>
                    <label><input type="checkbox" name="new_tab" value="1" /> <?php esc_html_e('Open in new tab', 'smartlinker'); ?></label><br>
                    <label><input type="checkbox" name="nofollow" value="1" /> <?php esc_html_e('rel="nofollow"', 'smartlinker'); ?></label><br>
                    <label><?php esc_html_e('Max links per post:', 'smartlinker'); ?><?php echo Slk_Admin::help(__('How many times this keyword may be linked within a single post. 1 is usually right — repeating the same link adds no SEO value.', 'smartlinker')); ?>
                        <input type="number" name="max_per_post" value="1" min="1" max="20" style="width:70px;" /></label>
                </td>
            </tr>
        </table>
        <p style="margin-bottom:0;"><button type="submit" name="slk_add_rule" value="1" class="button button-primary"><?php esc_html_e('Add Rule', 'smartlinker'); ?></button></p>
    </form>
    </div>

    <h2><?php esc_html_e('Existing rules', 'smartlinker'); ?></h2>
    <?php if (empty($rules)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-admin-links"></span>
            <strong><?php esc_html_e('No auto-link rules yet', 'smartlinker'); ?></strong>
            <?php esc_html_e('Add a keyword above and SmartLinker will link it automatically wherever it appears.', 'smartlinker'); ?>
        </div>
    <?php else : ?>
    <table class="slk-table">
        <thead>
            <tr>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Keyword', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Destination', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:90px;"><?php esc_html_e('Max', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Links', 'smartlinker'); ?><?php echo Slk_Admin::help([
                    __('How many links this rule currently creates across your published content.', 'smartlinker'),
                    __('Auto-links are added as the page is rendered and are never written into your posts, so this is counted by running the rule — the same matching the frontend uses, respecting case sensitivity, partial matching and the max-per-post cap.', 'smartlinker'),
                    __('A paused rule still shows a number: that is what you would get by activating it.', 'smartlinker'),
                ]); ?></th>
                <th class="slk-th-actions" style="text-align:left;width:120px;"><?php esc_html_e('Status', 'smartlinker'); ?></th>
                <th class="slk-th-actions" style="width:170px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rules as $r) :
            $toggle = wp_nonce_url(admin_url('admin.php?page=smartlinker_autolinks&slk_toggle=' . $r->id), 'slk_toggle_rule');
            $delete = wp_nonce_url(admin_url('admin.php?page=smartlinker_autolinks&slk_delete=' . $r->id), 'slk_delete_rule');
            $opts = [];
            if ($r->case_sensitive) $opts[] = 'Aa';
            if ($r->partial_match) $opts[] = __('partial', 'smartlinker');
            if ($r->new_tab) $opts[] = __('new tab', 'smartlinker');
            if ($r->nofollow) $opts[] = 'nofollow';
            ?>
            <tr class="slk-tr">
                <td>
                    <div class="slk-title-wrap">
                        <span class="slk-title-link"><?php echo esc_html($r->keyword); ?></span>
                        <?php if ($opts) : ?>
                            <div class="slk-title-meta">
                                <?php foreach ($opts as $o) : ?><span class="slk-chip"><?php echo esc_html($o); ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
                <td><a href="<?php echo esc_url($r->url); ?>" target="_blank" rel="noopener" style="word-break:break-all;"><?php echo esc_html($r->url); ?></a></td>
                <td><span class="slk-metric"><?php echo (int) $r->max_per_post; ?></span></td>
                <?php $n = isset($counts[(int) $r->id]) ? (int) $counts[(int) $r->id] : 0; ?>
                <td>
                    <span class="slk-metric<?php echo $n === 0 ? ' slk-metric-zero' : ''; ?><?php echo $r->active ? '' : ' slk-metric-muted'; ?>">
                        <?php echo esc_html(number_format_i18n($n)); ?>
                    </span>
                    <?php if ($n === 0 && $r->keyword !== '') : ?>
                        <div class="slk-title-meta"><?php esc_html_e('no matches', 'smartlinker'); ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="slk-badge <?php echo $r->active ? 'slk-badge-good' : 'slk-badge-warn'; ?>">
                        <?php echo $r->active ? esc_html__('Active', 'smartlinker') : esc_html__('Paused', 'smartlinker'); ?>
                    </span>
                </td>
                <td class="slk-td-actions">
                    <a class="button button-small" href="<?php echo esc_url($toggle); ?>"><?php echo $r->active ? esc_html__('Pause', 'smartlinker') : esc_html__('Activate', 'smartlinker'); ?></a>
                    <a class="slk-icon-btn" title="<?php esc_attr_e('Delete', 'smartlinker'); ?>" href="<?php echo esc_url($delete); ?>" onclick="return confirm('<?php echo esc_js(__('Delete this rule?', 'smartlinker')); ?>');"><span class="dashicons dashicons-trash"></span></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
