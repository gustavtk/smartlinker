<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $data */
$rows = $data['rows'];
$engine = isset($_GET['engine']) && $_GET['engine'] === 'ai' ? 'ai' : 'standard';
$ai = Slk_AI::availability();
$scan_url = wp_nonce_url(admin_url('admin.php?page=smartlinker_opportunities&slk_opp_scan=1&engine=' . $engine . '&offset=0'), 'slk_opp_scan');

// Optional narrowing, so a long list stays workable.
$min = isset($_GET['min']) ? max(0, min(100, (int) $_GET['min'])) : 0;
$only_target = isset($_GET['target']) ? (int) $_GET['target'] : 0;
if ($min > 0 || $only_target > 0) {
    $rows = array_values(array_filter($rows, function ($r) use ($min, $only_target) {
        if ($min > 0 && (int) $r['match'] < $min) {
            return false;
        }
        if ($only_target > 0 && (int) $r['target_id'] !== $only_target) {
            return false;
        }
        return true;
    }));
}
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Link Opportunities', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Every place on the site where one post already says something that names another post. Apply a link without opening the editor.', 'smartlinker'); ?>
    </p>

    <div class="slk-engine" style="margin-bottom:14px;">
        <a class="slk-engine-btn<?php echo $engine !== 'ai' ? ' is-on' : ''; ?>"
           href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_opportunities')); ?>"><?php esc_html_e('Standard', 'smartlinker'); ?></a>
        <a class="slk-engine-btn<?php echo $engine === 'ai' ? ' is-on' : ''; ?><?php echo $ai['enabled'] ? '' : ' is-locked'; ?>"
           href="<?php echo $ai['enabled'] ? esc_url(admin_url('admin.php?page=smartlinker_opportunities&engine=ai')) : '#'; ?>"><?php esc_html_e('AI', 'smartlinker'); ?></a>
        <span class="slk-engine-note">
            <?php echo $engine === 'ai'
                ? esc_html__('One OpenAI call per post, billed to your account. Results are cached per post until you edit it.', 'smartlinker')
                : esc_html__('Free. Matches keywords and titles, scored by meaning where the semantic index is built.', 'smartlinker'); ?>
        </span>
    </div>

    <?php if (!$ai['enabled'] && $engine === 'ai') : ?>
        <div class="slk-callout slk-callout-warn"><?php echo esc_html($ai['reason'][0] ?? ''); ?></div>
    <?php endif; ?>

    <?php if (!empty($_GET['scanned'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Scan complete.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n(count($rows))); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Opportunities', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n(count(array_unique(array_column($rows, 'source_id'))))); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Posts to edit', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n(count(array_unique(array_column($rows, 'target_id'))))); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Pages that gain links', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html($data['scanned'] . '/' . $data['total']); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Posts scanned', 'smartlinker'); ?></span>
        </div>
    </div>

    <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a class="button button-primary slk-scan-btn" href="<?php echo esc_url($scan_url); ?>" data-slk-scan="opportunities" data-slk-label="Finding opportunities">
            <?php echo $data['scanned'] ? esc_html__('Re-scan site', 'smartlinker') : esc_html__('Scan the site', 'smartlinker'); ?>
        </a>
        <?php if ($data['generated']) : ?>
            <span class="description"><?php /* translators: %s: date and time of the last scan */ printf(esc_html__('Last scan: %s', 'smartlinker'), esc_html($data['generated'])); ?></span>
        <?php endif; ?>
    </p>

    <?php if (empty($rows)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-admin-links"></span>
            <strong><?php esc_html_e('No opportunities yet', 'smartlinker'); ?></strong>
            <?php echo $data['scanned']
                ? esc_html__('Every phrase that names another post is already linked. Add a focus keyword to a post to widen the net.', 'smartlinker')
                : esc_html__('Run a scan to find them.', 'smartlinker'); ?>
        </div>
    <?php else : ?>

        <form method="get" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;">
            <input type="hidden" name="page" value="smartlinker_opportunities" />
            <label class="description"><?php esc_html_e('Minimum confidence', 'smartlinker'); ?>
                <input type="number" name="min" min="0" max="100" step="5" value="<?php echo esc_attr($min); ?>" style="width:80px;" />
            </label>
            <button type="submit" class="button"><?php esc_html_e('Filter', 'smartlinker'); ?></button>
            <?php if ($min > 0) : ?>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_opportunities')); ?>"><?php esc_html_e('Clear', 'smartlinker'); ?></a>
            <?php endif; ?>
        </form>

        <div class="slk-bulk-bar" hidden>
            <span class="slk-bulk-count"></span>
            <button type="button" class="button button-primary slk-bulk-apply"><?php esc_html_e('Apply selected', 'smartlinker'); ?></button>
            <button type="button" class="button slk-bulk-stop" hidden><?php esc_html_e('Stop', 'smartlinker'); ?></button>
            <span class="slk-bulk-status" role="status" aria-live="polite"></span>
        </div>

        <table class="slk-table slk-opp-table">
            <thead>
                <tr>
                    <th class="slk-th-actions slk-th-check">
                        <input type="checkbox" class="slk-check-all"
                               title="<?php esc_attr_e('Select every row below', 'smartlinker'); ?>" />
                    </th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Add this link in…', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Anchor', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Pointing at', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Confidence', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="width:120px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) :
                $pct = (int) $r['match'];
                $tone = $pct >= 75 ? 'slk-conf-high' : ($pct >= 50 ? 'slk-conf-mid' : 'slk-conf-low');
                ?>
                <tr class="slk-tr slk-suggestion"
                    data-source="<?php echo esc_attr($r['source_id']); ?>"
                    data-target="<?php echo esc_attr($r['target_id']); ?>"
                    data-phrase="<?php echo esc_attr($r['phrase']); ?>"
                    data-url="<?php echo esc_attr($r['url']); ?>">
                    <td class="slk-td-check">
                        <input type="checkbox" class="slk-check-row"
                               aria-label="<?php echo esc_attr(sprintf(
                                   /* translators: 1: anchor text, 2: destination title */
                                   __('Select: link “%1$s” to %2$s', 'smartlinker'),
                                   $r['phrase'],
                                   $r['target_title']
                               )); ?>" />
                    </td>
                    <td>
                        <a class="slk-title-link" href="<?php echo esc_url(Slk_Admin::edit_url($r['source_id'])); ?>">
                            <?php echo esc_html($r['source_title']); ?>
                        </a>
                        <?php if ($r['reason']) : ?>
                            <div class="slk-title-meta"><?php echo esc_html($r['reason']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="slk-kw-chip"><?php echo esc_html($r['phrase']); ?></span></td>
                    <td>
                        <a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($r['target_title']); ?></a>
                        <div class="slk-title-meta"><?php echo esc_html($r['path']); ?></div>
                        <?php if (!empty($r['impact']['why'])) : ?>
                            <?php // Why this sits where it does. A ranked list nobody can
                                  // interrogate is a ranked list nobody acts on. ?>
                            <div class="slk-why"><?php echo esc_html($r['impact']['why']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="slk-conf <?php echo esc_attr($tone); ?>"><?php echo esc_html($pct . '%'); ?></span>
                        <?php if (!empty($r['impact']['striking'])) : ?>
                            <div class="slk-striking"><?php esc_html_e('near page 1', 'smartlinker'); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="slk-td-actions">
                        <button type="button" class="slk-btn-apply slk-insert-inbound"><?php esc_html_e('Apply', 'smartlinker'); ?></button>
                        <span class="slk-sugg-msg" role="status"></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
