<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows @var array $counts @var array $state @var string $view */
$base = Slk_Reports::url('cannibal');
$scan = wp_nonce_url(Slk_Reports::url('cannibal', ['slk_cannibal_scan' => 1, 'offset' => 0]), 'slk_cannibal_scan');
$semantic_on = Slk_Cannibal::semantic_available();

$views = [
    'all'     => __('All clashes', 'smartlinker'),
    'direct'  => __('Direct', 'smartlinker'),
    'keyword' => __('Same keyword', 'smartlinker'),
    'overlap' => __('Overlapping content', 'smartlinker'),
];

$labels = [
    'direct'  => __('Direct clash', 'smartlinker'),
    'keyword' => __('Same target keyword', 'smartlinker'),
    'overlap' => __('Overlapping content', 'smartlinker'),
];
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Cannibalisation', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Posts on your own site competing with each other. When two articles chase the same query a search engine has to pick one — usually not the one you would have chosen — and both rank worse than a single stronger article would. Internal linking makes it worse, not better: your links get split between the rivals, so neither accumulates enough authority to win.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['scanned'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Scan complete.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <?php if (!$semantic_on) : ?>
        <div class="slk-callout slk-callout-warn">
            <?php
            printf(
                /* translators: %s: link to AI settings */
                esc_html__('Only keyword clashes are shown. Finding posts that mean the same thing without sharing a keyword needs the semantic index — build it under %s. That is the half that finds the clashes you do not already know about.', 'smartlinker'),
                '<a href="' . esc_url(admin_url('admin.php?page=smartlinker_settings&tab=ai')) . '">' . esc_html__('Settings → AI', 'smartlinker') . '</a>'
            );
            ?>
        </div>
    <?php endif; ?>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $counts['total'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($counts['total'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Competing pairs', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $counts['direct'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($counts['direct'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Direct clashes', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['keyword'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Same keyword', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['overlap'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Overlapping only', 'smartlinker'); ?></span>
        </div>
    </div>

    <div class="slk-tabs slk-tabs-underline" style="margin-bottom:14px;">
        <?php foreach ($views as $key => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg('view', $key, $base)); ?>"
               class="slk-tab<?php echo $view === $key ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a class="button button-primary slk-scan-btn" href="<?php echo esc_url($scan); ?>" data-slk-scan="cannibal" data-slk-label="Comparing posts">
            <?php echo $state['scanned'] ? esc_html__('Re-scan for overlaps', 'smartlinker') : esc_html__('Scan for overlapping content', 'smartlinker'); ?>
        </a>
        <span class="description">
            <?php if ($state['generated']) : ?>
                <?php
                printf(
                    /* translators: 1: scanned, 2: total, 3: date */
                    esc_html__('Compared %1$s of %2$s posts. Last run %3$s.', 'smartlinker'),
                    esc_html(number_format_i18n($state['scanned'])),
                    esc_html(number_format_i18n($state['total'])),
                    esc_html(mysql2date('j M Y, H:i', $state['generated']))
                );
                ?>
            <?php else : ?>
                <?php esc_html_e('Keyword clashes are listed without scanning. The scan adds posts that overlap in meaning.', 'smartlinker'); ?>
            <?php endif; ?>
        </span>
        <?php if (!empty($rows)) : ?>
            <a class="button" href="<?php echo esc_url(Slk_CSV::export_url('cannibal')); ?>"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
        <?php endif; ?>
    </p>

    <?php if (empty($rows)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-yes-alt"></span>
            <strong><?php esc_html_e('Nothing competing', 'smartlinker'); ?></strong>
            <?php
            echo $view === 'all'
                ? esc_html__('No two posts share a focus keyword, and none of the pairs compared cover the same ground.', 'smartlinker')
                : esc_html__('Nothing in this category — which is the good outcome.', 'smartlinker');
            ?>
        </div>
    <?php else : ?>
        <table class="slk-table slk-cannibal-table">
            <thead>
                <tr>
                    <th class="slk-th-actions" style="text-align:left;width:150px;"><?php esc_html_e('Clash', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('These two posts compete', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:230px;"><?php esc_html_e('What to do', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) :
                $keep_is_a = $r['stronger'] === $r['a'];
                $keep_title = $keep_is_a ? $r['a_title'] : $r['b_title'];
                $other_title = $keep_is_a ? $r['b_title'] : $r['a_title'];
                $keep_eq = $keep_is_a ? $r['a_equity'] : $r['b_equity'];
                $other_eq = $keep_is_a ? $r['b_equity'] : $r['a_equity'];
                ?>
                <tr class="slk-tr">
                    <td>
                        <span class="slk-badge <?php echo $r['severity'] === 'direct' ? 'slk-badge-warn' : ''; ?>">
                            <?php echo esc_html($labels[$r['severity']]); ?>
                        </span>
                        <?php if ($r['similarity'] !== null) : ?>
                            <div class="slk-title-meta">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %s: cosine similarity as a percentage */
                                    __('%s%% alike', 'smartlinker'),
                                    number_format($r['similarity'] * 100, 0)
                                ));
                                ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="slk-cann-pair">
                            <div class="slk-cann-post">
                                <a class="slk-title-link" href="<?php echo esc_url($r['a_edit']); ?>"><?php echo esc_html($r['a_title']); ?></a>
                                <span class="slk-title-meta">
                                    <?php echo $r['a_keyword'] !== '' ? esc_html('“' . $r['a_keyword'] . '”') : esc_html__('no focus keyword', 'smartlinker'); ?>
                                    · <?php echo esc_html(number_format($r['a_equity'], 2)); ?>×
                                </span>
                            </div>
                            <div class="slk-cann-vs"><?php esc_html_e('vs', 'smartlinker'); ?></div>
                            <div class="slk-cann-post">
                                <a class="slk-title-link" href="<?php echo esc_url($r['b_edit']); ?>"><?php echo esc_html($r['b_title']); ?></a>
                                <span class="slk-title-meta">
                                    <?php echo $r['b_keyword'] !== '' ? esc_html('“' . $r['b_keyword'] . '”') : esc_html__('no focus keyword', 'smartlinker'); ?>
                                    · <?php echo esc_html(number_format($r['b_equity'], 2)); ?>×
                                </span>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="slk-cann-advice">
                            <?php if ($r['severity'] === 'direct') : ?>
                                <strong><?php esc_html_e('Merge them.', 'smartlinker'); ?></strong>
                                <?php esc_html_e('Same keyword and near-identical content — there is no reading of this where two pages help.', 'smartlinker'); ?>
                            <?php elseif ($r['severity'] === 'keyword') : ?>
                                <strong><?php esc_html_e('Re-target one.', 'smartlinker'); ?></strong>
                                <?php esc_html_e('The content differs, so give one a keyword that matches what it actually covers.', 'smartlinker'); ?>
                            <?php else : ?>
                                <strong><?php esc_html_e('Differentiate or merge.', 'smartlinker'); ?></strong>
                                <?php esc_html_e('Different keywords, but they say much the same thing.', 'smartlinker'); ?>
                            <?php endif; ?>
                            <div class="slk-title-meta" style="margin-top:6px;">
                                <?php
                                if ($keep_eq > 0 && $keep_eq > $other_eq) {
                                    echo esc_html(sprintf(
                                        /* translators: 1: title to keep, 2: its equity, 3: the other's equity */
                                        __('Keep “%1$s” as the main page — it already holds more link value (%2$s× vs %3$s×).', 'smartlinker'),
                                        $keep_title,
                                        number_format($keep_eq, 2),
                                        number_format($other_eq, 2)
                                    ));
                                } else {
                                    esc_html_e('Neither has a link-value advantage — pick on merit, then point the other at it.', 'smartlinker');
                                }
                                ?>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
