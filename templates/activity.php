<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $rows */
/** @var array $filters */
/** @var array $posts */
/** @var int $total */
/** @var int $keep */
$labels = Slk_Activity::actions();
$base = admin_url('admin.php?page=smartlinker_activity');
$filtered = $filters['post'] || $filters['action'] || $filters['state'];
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Activity', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Every change SmartLinker wrote to a post directly, newest first — and a way to undo it. Links you insert from the editor are not listed: you review those before saving, and WordPress revisions already cover them.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($batches)) : ?>
        <?php
        /*
         * Bulk operations first, and separately.
         *
         * A link map or a site-wide URL change writes one log row per post.
         * Those rows are all individually undoable below, but undoing four
         * hundred of them by hand is recovery rather than undo — so the whole
         * operation gets one button.
         */
        ?>
        <div class="slk-card-block">
            <div class="slk-block-head">
                <h2><?php esc_html_e('Bulk changes', 'smartlinker'); ?></h2>
                <span class="description"><?php esc_html_e('Each of these changed many posts at once, and can be undone in one go.', 'smartlinker'); ?></span>
            </div>
            <table class="slk-table">
                <thead>
                    <tr>
                        <th style="text-align:left;"><?php esc_html_e('What changed', 'smartlinker'); ?></th>
                        <th style="text-align:left;width:150px;"><?php esc_html_e('When', 'smartlinker'); ?></th>
                        <th style="text-align:left;width:130px;"><?php esc_html_e('Posts', 'smartlinker'); ?></th>
                        <th style="text-align:left;width:120px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($batches as $b) : ?>
                    <tr class="slk-tr">
                        <td><?php echo esc_html($b->summary); ?></td>
                        <td><?php echo esc_html(mysql2date('j M Y, H:i', $b->created)); ?></td>
                        <td>
                            <?php
                            printf(
                                /* translators: 1: posts still undoable, 2: posts changed in total */
                                esc_html__('%1$s of %2$s undoable', 'smartlinker'),
                                esc_html(number_format_i18n($b->undoable)),
                                esc_html(number_format_i18n($b->posts))
                            );
                            ?>
                        </td>
                        <td>
                            <?php if ((int) $b->undoable > 0) : ?>
                                <a class="button" href="<?php echo esc_url(wp_nonce_url(
                                    add_query_arg([
                                        'page' => 'smartlinker_activity',
                                        'slk_undo_batch' => $b->batch,
                                    ], admin_url('admin.php')),
                                    'slk_undo_batch'
                                )); ?>"><?php
                                    printf(
                                        /* translators: %s: number of posts that would be restored */
                                        esc_html__('Undo all %s', 'smartlinker'),
                                        esc_html(number_format_i18n($b->undoable))
                                    );
                                ?></a>
                            <?php else : ?>
                                <span class="description"><?php esc_html_e('Already undone', 'smartlinker'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['undone'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php
            $n = (int) $_GET['undone'];
            echo $n > 1
                ? esc_html(sprintf(
                    /* translators: %d: number of posts restored */
                    _n('%d post restored and re-indexed.', '%d posts restored and re-indexed.', $n, 'smartlinker'),
                    $n
                ))
                : esc_html__('Change undone and the post re-indexed.', 'smartlinker');
        ?></p></div>
    <?php elseif (!empty($_GET['undo_err'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['undo_err'])); ?></p></div>
    <?php endif; ?>

    <?php if ($keep === 0) : ?>
        <div class="slk-callout slk-callout-warn">
            <?php
            printf(
                /* translators: %s: link to the settings page */
                esc_html__('The activity log is switched off, so nothing new is being recorded and applied changes cannot be undone. Turn it back on under %s.', 'smartlinker'),
                '<a href="' . esc_url(admin_url('admin.php?page=smartlinker_settings&tab=general')) . '">' . esc_html__('Settings → General', 'smartlinker') . '</a>'
            );
            ?>
        </div>
    <?php endif; ?>

    <?php if ($total === 0) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-backup"></span>
            <strong><?php esc_html_e('Nothing yet', 'smartlinker'); ?></strong>
            <?php esc_html_e('Apply a link from Link Opportunities, Orphaned Posts or Broken Links and it will appear here.', 'smartlinker'); ?>
        </div>
    <?php else : ?>

        <form method="get" class="slk-activity-filters">
            <input type="hidden" name="page" value="smartlinker_activity" />

            <label>
                <span class="description"><?php esc_html_e('Post', 'smartlinker'); ?></span>
                <select name="slk_post">
                    <option value="0"><?php esc_html_e('All posts', 'smartlinker'); ?></option>
                    <?php foreach ($posts as $pid => $ptitle) : ?>
                        <option value="<?php echo esc_attr($pid); ?>" <?php selected($filters['post'], $pid); ?>>
                            <?php echo esc_html($ptitle); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span class="description"><?php esc_html_e('Change', 'smartlinker'); ?></span>
                <select name="slk_action">
                    <option value=""><?php esc_html_e('All changes', 'smartlinker'); ?></option>
                    <?php foreach ($labels as $slug => $label) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['action'], $slug); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span class="description"><?php esc_html_e('State', 'smartlinker'); ?></span>
                <select name="slk_state">
                    <option value=""><?php esc_html_e('Any', 'smartlinker'); ?></option>
                    <option value="undoable" <?php selected($filters['state'], 'undoable'); ?>><?php esc_html_e('Not undone', 'smartlinker'); ?></option>
                    <option value="undone" <?php selected($filters['state'], 'undone'); ?>><?php esc_html_e('Undone', 'smartlinker'); ?></option>
                </select>
            </label>

            <button type="submit" class="button"><?php esc_html_e('Filter', 'smartlinker'); ?></button>
            <?php if ($filtered) : ?>
                <a class="button" href="<?php echo esc_url($base); ?>"><?php esc_html_e('Clear', 'smartlinker'); ?></a>
            <?php endif; ?>

            <span class="description slk-activity-count">
                <?php
                printf(
                    /* translators: 1: rows shown, 2: total entries, 3: retention cap */
                    esc_html__('Showing %1$s of %2$s kept (limit %3$s)', 'smartlinker'),
                    esc_html(number_format_i18n(count($rows))),
                    esc_html(number_format_i18n($total)),
                    esc_html(number_format_i18n($keep))
                );
                ?>
            </span>
        </form>

        <?php if (empty($rows)) : ?>
            <div class="slk-empty-state">
                <span class="dashicons dashicons-filter"></span>
                <strong><?php esc_html_e('Nothing matches that filter', 'smartlinker'); ?></strong>
                <?php esc_html_e('Widen it, or clear the filter to see everything.', 'smartlinker'); ?>
            </div>
        <?php else : ?>
        <table class="slk-table">
            <thead>
                <tr>
                    <th class="slk-th-actions" style="text-align:left;width:140px;"><?php esc_html_e('Change', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('What happened', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('In', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:150px;"><?php esc_html_e('When', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="width:110px;"><?php esc_html_e('Actions', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row) :
                $undo = wp_nonce_url(
                    add_query_arg(array_filter([
                        'slk_undo'   => (int) $row->id,
                        'slk_post'   => $filters['post'] ?: null,
                        'slk_action' => $filters['action'] ?: null,
                        'slk_state'  => $filters['state'] ?: null,
                    ]), $base),
                    'slk_undo'
                );
                $post = get_post($row->post_id);
                $gone = !$post;
                // Undo is only safe while the post still matches what we wrote.
                $stale = $post && md5($post->post_content) !== $row->after_hash;
                $user = get_userdata($row->user_id);
                ?>
                <tr class="slk-tr<?php echo $row->reverted ? ' slk-fixed' : ''; ?>">
                    <td><span class="slk-chip"><?php echo esc_html($labels[$row->action] ?? $row->action); ?></span></td>
                    <td>
                        <?php echo esc_html($row->summary); ?>
                        <?php if ($row->target_url) : ?>
                            <div class="slk-title-meta"><?php echo esc_html(wp_make_link_relative($row->target_url)); ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($post) : ?>
                            <a href="<?php echo esc_url(Slk_Admin::edit_url($row->post_id)); ?>"><?php echo esc_html(get_the_title($row->post_id)); ?></a>
                        <?php else : ?>
                            <span class="slk-metric-zero"><?php esc_html_e('deleted', 'smartlinker'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html(mysql2date('M j, H:i', $row->created)); ?>
                        <?php if ($user) : ?>
                            <div class="slk-title-meta"><?php echo esc_html($user->display_name); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="slk-td-actions">
                        <?php if ($row->reverted) : ?>
                            <span class="slk-badge slk-badge-good"><?php esc_html_e('Undone', 'smartlinker'); ?></span>
                        <?php elseif ($gone) : ?>
                            <span class="slk-badge slk-badge-warn" title="<?php esc_attr_e('The post was deleted, so there is nothing left to restore.', 'smartlinker'); ?>">
                                <?php esc_html_e('Post deleted', 'smartlinker'); ?>
                            </span>
                        <?php elseif ($stale) : ?>
                            <span class="slk-badge slk-badge-warn" title="<?php esc_attr_e('The post has been edited since, so undoing would discard that work.', 'smartlinker'); ?>">
                                <?php esc_html_e('Edited since', 'smartlinker'); ?>
                            </span>
                        <?php else : ?>
                            <a class="button button-small" href="<?php echo esc_url($undo); ?>"
                               onclick="return confirm('<?php echo esc_js(__('Undo this change? The post goes back to how it was.', 'smartlinker')); ?>');">
                                <?php esc_html_e('Undo', 'smartlinker'); ?>
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php endif; ?>
</div>
