<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows */
/** @var array $all */
/** @var array $counts */
/** @var array $single */
/** @var string $filter */
$base = Slk_Reports::url('anchors');
$tabs = [
    'all'        => __('All anchors', 'smartlinker'),
    'ambiguous'  => __('Same words, different pages', 'smartlinker'),
    'generic'    => __('Says nothing', 'smartlinker'),
    'repetitive' => __('Over-repeated', 'smartlinker'),
    'single'     => __('Pages with no variety', 'smartlinker'),
    'suppressed' => __('Suppressed', 'smartlinker'),
];
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Anchor Text', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('What your internal links actually say. Every other report asks whether a link exists — this one asks whether the words are doing any work. Nothing here is auto-fixed: these are judgement calls about writing, so each row links to the post.', 'smartlinker'); ?>
    </p>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['unique'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Distinct anchors', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($counts['total'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Internal links', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $counts['ambiguous'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($counts['ambiguous'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Point two ways', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $counts['generic'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($counts['generic'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Say nothing', 'smartlinker'); ?></span>
        </div>
    </div>

    <div class="slk-tabs slk-tabs-underline" style="margin-bottom:16px;">
        <?php foreach ($tabs as $key => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg('show', $key, $base)); ?>"
               class="slk-tab<?php echo $filter === $key ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <?php // The export always carries every anchor, so it is offered whenever
         // any exist — not only when the current sub-tab has matches. ?>
    <?php if ($filter !== 'suppressed' && !empty($all)) : ?>
        <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <a class="button" href="<?php echo esc_url(Slk_CSV::export_url('anchors')); ?>"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
            <span class="description"><?php esc_html_e('One row per anchor and destination, so a phrase pointing at three posts exports as three rows.', 'smartlinker'); ?></span>
        </p>
    <?php endif; ?>

    <?php if ($filter === 'suppressed') : ?>

        <?php if (!empty($_GET['restored'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Back in circulation — it can be suggested again.', 'smartlinker'); ?></p></div>
        <?php endif; ?>

        <p class="description" style="margin-bottom:12px;">
            <?php
            printf(
                /* translators: 1: pair threshold, 2: anchor threshold */
                esc_html__('Anchors you have rejected often enough that SmartLinker stopped offering them. Turning down the same anchor and destination on %1$d different posts retires that pairing; turning down the same anchor on %2$d different posts retires the anchor everywhere. Nothing here is permanent — restore anything you want back.', 'smartlinker'),
                (int) Slk_Rejection::pair_threshold(),
                (int) Slk_Rejection::anchor_threshold()
            );
            ?>
        </p>

        <?php if (Slk_Rejection::learning_off()) : ?>
            <div class="slk-callout slk-callout-warn">
                <?php
                printf(
                    /* translators: %s: link to the settings page */
                    esc_html__('Learning from rejections is switched off, so nothing is being suppressed. Anything already learned is kept and will apply again if you turn it back on under %s.', 'smartlinker'),
                    '<a href="' . esc_url(admin_url('admin.php?page=smartlinker_settings&tab=general')) . '">' . esc_html__('Settings → General', 'smartlinker') . '</a>'
                );
                ?>
            </div>
        <?php endif; ?>

        <?php if (empty($suppressed)) : ?>
            <div class="slk-empty-state">
                <span class="dashicons dashicons-yes-alt"></span>
                <strong><?php esc_html_e('Nothing suppressed', 'smartlinker'); ?></strong>
                <?php esc_html_e('Reject the same suggestion on a few different posts and it will stop being offered site-wide.', 'smartlinker'); ?>
            </div>
        <?php else : ?>
            <table class="slk-table">
                <thead>
                    <tr>
                        <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Anchor', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Not offered', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="text-align:left;width:150px;"><?php esc_html_e('You rejected it in', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="width:110px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($suppressed as $sup) :
                    $restore = wp_nonce_url(
                        Slk_Reports::url('anchors', [
                            'show'        => 'suppressed',
                            'slk_restore' => rawurlencode($sup['key']),
                            'slk_bucket'  => $sup['bucket'],
                        ]),
                        'slk_restore'
                    );
                    ?>
                    <tr class="slk-tr">
                        <td><span class="slk-kw-chip"><?php echo esc_html($sup['anchor']); ?></span></td>
                        <td>
                            <?php if ($sup['scope'] === 'anchor') : ?>
                                <strong><?php esc_html_e('anywhere on the site', 'smartlinker'); ?></strong>
                            <?php else : ?>
                                <?php esc_html_e('pointing at', 'smartlinker'); ?>
                                <a href="<?php echo esc_url(get_permalink($sup['target'])); ?>" target="_blank" rel="noopener">
                                    <?php echo esc_html(get_the_title($sup['target'])); ?>
                                </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %d: number of posts */
                                _n('%d post', '%d posts', count($sup['posts']), 'smartlinker'),
                                count($sup['posts'])
                            ));
                            ?>
                            <div class="slk-title-meta">
                                <?php
                                $names = array_filter(array_map('get_the_title', array_slice($sup['posts'], 0, 3)));
                                echo esc_html(implode(', ', $names));
                                if (count($sup['posts']) > 3) {
                                    echo esc_html(sprintf(__(' +%d more', 'smartlinker'), count($sup['posts']) - 3));
                                }
                                ?>
                            </div>
                        </td>
                        <td class="slk-td-actions">
                            <a class="button button-small" href="<?php echo esc_url($restore); ?>"><?php esc_html_e('Restore', 'smartlinker'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if (!empty($pending)) : ?>
            <div class="slk-section-title" style="margin-top:26px;"><?php esc_html_e('On their way', 'smartlinker'); ?></div>
            <p class="description" style="margin-bottom:12px;">
                <?php esc_html_e('Rejected before, but not yet on enough posts to be retired. Shown so a suppression is never a surprise.', 'smartlinker'); ?>
            </p>
            <table class="slk-table">
                <tbody>
                <?php foreach ($pending as $p) : ?>
                    <tr class="slk-tr">
                        <td><span class="slk-kw-chip"><?php echo esc_html($p['anchor']); ?></span></td>
                        <td>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: 1: rejections so far, 2: rejections needed */
                                __('rejected in %1$d of %2$d posts needed', 'smartlinker'),
                                $p['count'],
                                $p['needed']
                            ));
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif ($filter === 'single') : ?>

        <p class="description" style="margin-bottom:12px;">
            <?php
            esc_html_e('Pages with four or more inbound links where every single one uses the identical anchor text. Search engines learn what a page is about partly from the words people use to link to it — when every link says the same thing, that page has no vocabulary around it.', 'smartlinker');
            ?>
        </p>

        <?php if (empty($single)) : ?>
            <div class="slk-empty-state">
                <span class="dashicons dashicons-yes-alt"></span>
                <strong><?php esc_html_e('Nothing to flag', 'smartlinker'); ?></strong>
                <?php esc_html_e('Every page with a few inbound links is described more than one way.', 'smartlinker'); ?>
            </div>
        <?php else : ?>
            <table class="slk-table">
                <thead>
                    <tr>
                        <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Page', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="text-align:left;width:220px;"><?php esc_html_e('Always called', 'smartlinker'); ?></th>
                        <th class="slk-th-actions" style="text-align:left;width:120px;"><?php esc_html_e('Inbound links', 'smartlinker'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($single as $s) : ?>
                    <tr class="slk-tr">
                        <td>
                            <a class="slk-title-link" href="<?php echo esc_url($s['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($s['title']); ?></a>
                        </td>
                        <td><span class="slk-kw-chip"><?php echo esc_html($s['anchor']); ?></span></td>
                        <td><?php echo esc_html(number_format_i18n($s['inbound'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

    <?php elseif (empty($rows)) : ?>

        <div class="slk-empty-state">
            <span class="dashicons dashicons-editor-textcolor"></span>
            <strong><?php esc_html_e('Nothing here', 'smartlinker'); ?></strong>
            <?php
            echo $filter === 'all'
                ? esc_html__('No internal links with anchor text have been indexed yet. Run a re-scan from the Links Report.', 'smartlinker')
                : esc_html__('Nothing matches this filter — which is the good outcome.', 'smartlinker');
            ?>
        </div>

    <?php else : ?>

        <?php if ($filter === 'ambiguous') : ?>
            <p class="description" style="margin-bottom:12px;">
                <?php esc_html_e('The same words pointing at different pages. A reader who learned that a phrase means one article gets sent somewhere else by identical words, and a search engine has no idea which page the phrase belongs to.', 'smartlinker'); ?>
            </p>
        <?php elseif ($filter === 'generic') : ?>
            <p class="description" style="margin-bottom:12px;">
                <?php esc_html_e('Anchors that describe nothing — "click here", "read more", "this page". The link still works; it just does no work. Replace them with words that name the destination.', 'smartlinker'); ?>
            </p>
        <?php elseif ($filter === 'repetitive') : ?>
            <p class="description" style="margin-bottom:12px;">
                <?php
                printf(
                    /* translators: %d: repeat threshold */
                    esc_html__('The identical anchor pointing at one page %d times or more. Natural writing varies; a long run of exact repeats reads as a pattern whether or not it was meant that way.', 'smartlinker'),
                    (int) Slk_Anchor::REPEAT_THRESHOLD
                );
                ?>
            </p>
        <?php endif; ?>

        <table class="slk-table slk-anchor-table">
            <thead>
                <tr>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Anchor text', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:90px;"><?php esc_html_e('Used', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Points at', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="width:110px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r) : ?>
                <tr class="slk-tr slk-anchor-row" data-anchor="<?php echo esc_attr($r['anchor']); ?>">
                    <td>
                        <span class="slk-kw-chip"><?php echo esc_html($r['anchor']); ?></span>
                        <?php if ($r['generic']) : ?>
                            <span class="slk-badge slk-badge-warn" title="<?php esc_attr_e('This tells a reader nothing about where the link goes.', 'smartlinker'); ?>"><?php esc_html_e('says nothing', 'smartlinker'); ?></span>
                        <?php endif; ?>
                        <?php if ($r['repetitive']) : ?>
                            <span class="slk-badge slk-badge-warn" title="<?php esc_attr_e('Repeated many times at the same destination.', 'smartlinker'); ?>"><?php esc_html_e('over-repeated', 'smartlinker'); ?></span>
                        <?php endif; ?>
                        <div class="slk-title-meta">
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %d: number of posts */
                                _n('in %d post', 'in %d posts', $r['source_count'], 'smartlinker'),
                                $r['source_count']
                            ));
                            ?>
                        </div>
                    </td>
                    <td><?php echo esc_html(number_format_i18n($r['uses'])); ?></td>
                    <td>
                        <?php if ($r['ambiguous']) : ?>
                            <div class="slk-anchor-split">
                                <strong>
                                    <?php
                                    echo esc_html(sprintf(
                                        /* translators: %d: number of destinations */
                                        __('%d different pages', 'smartlinker'),
                                        $r['target_count']
                                    ));
                                    ?>
                                </strong>
                            </div>
                        <?php endif; ?>
                        <?php foreach (array_slice($r['targets'], 0, 4) as $t) : ?>
                            <div class="slk-anchor-dest">
                                <a href="<?php echo esc_url($t['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($t['title']); ?></a>
                                <span class="slk-title-meta">×<?php echo esc_html($t['count']); ?></span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($r['target_count'] > 4) : ?>
                            <div class="slk-title-meta">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %d: number of further destinations */
                                    __('…and %d more', 'smartlinker'),
                                    $r['target_count'] - 4
                                ));
                                ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="slk-td-actions">
                        <button type="button" class="button button-small slk-anchor-show"><?php esc_html_e('Where?', 'smartlinker'); ?></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>
</div>
