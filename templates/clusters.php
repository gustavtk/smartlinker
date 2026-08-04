<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $snapshot */
$clusters = $snapshot['clusters'];
$totals   = $snapshot['totals'];
$is_ai    = $snapshot['source'] === 'ai';

$ai = Slk_AI::availability();
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Topic Clusters', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('See your whole site as one map. Spot the holes. Build real topical authority.', 'smartlinker'); ?>
    </p>

    <?php if (isset($_GET['themed'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php printf(
            /* translators: 1: number of themes, 2: seconds taken */
            esc_html__('AI grouped your content into %1$d themes in %2$ds.', 'smartlinker'),
            (int) $_GET['themed'],
            (int) ($_GET['secs'] ?? 0)
        ); ?></p></div>
    <?php elseif (!empty($_GET['unthemed'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Back to your own categories.', 'smartlinker'); ?></p></div>
    <?php elseif (!empty($_GET['theme_err'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['theme_err'])); ?></p></div>
    <?php endif; ?>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($totals['clusters'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Clusters', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($totals['pillars'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Pillars', 'smartlinker'); ?><?php echo Slk_Admin::help(__('The most-linked-to post in each cluster — the page the rest of your site already treats as the main one. A cluster with nothing pointing into it has no pillar yet.', 'smartlinker')); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($totals['posts'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Posts', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($totals['orphans'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Orphans', 'smartlinker'); ?></span>
        </div>
    </div>

    <div class="slk-cluster-bar">
        <div class="slk-view-toggle" role="group" aria-label="<?php esc_attr_e('Map view', 'smartlinker'); ?>">
            <button type="button" class="slk-view-btn is-on" data-view="size">
                <?php esc_html_e('Treemap', 'smartlinker'); ?>
            </button>
            <button type="button" class="slk-view-btn" data-view="health">
                <?php esc_html_e('Heatmap', 'smartlinker'); ?>
            </button>
            <?php echo Slk_Admin::help([
                __('Treemap sizes every cluster by how many posts it holds, so you can see the shape of your site at a glance.', 'smartlinker'),
                __('Heatmap keeps the same sizes but colours each cluster by how well it is linked together: the share of its posts that another post in the same cluster links to. Red means the posts sit side by side without referencing each other — that is the hole.', 'smartlinker'),
            ], 'left'); ?>
        </div>

        <div class="slk-cluster-actions">
            <?php if ($is_ai) : ?>
                <span class="slk-chip slk-chip-ai"><?php esc_html_e('AI themed', 'smartlinker'); ?></span>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=smartlinker_clusters&slk_untheme=1'), 'slk_cluster_untheme')); ?>">
                    <?php esc_html_e('Use my categories', 'smartlinker'); ?>
                </a>
            <?php else : ?>
                <span class="slk-chip"><?php esc_html_e('From your categories', 'smartlinker'); ?></span>
            <?php endif; ?>

            <form method="post" style="display:inline;margin:0;">
                <?php wp_nonce_field('slk_cluster_theme'); ?>
                <button type="submit" name="slk_theme_clusters" value="1"
                    class="button button-primary slk-scan-btn<?php echo $ai['enabled'] ? '' : ' slk-scan-btn-locked'; ?>"
                    <?php disabled(!$ai['enabled']); ?>>
                    <span class="dashicons <?php echo $ai['enabled'] ? 'dashicons-superhero' : 'dashicons-lock'; ?>"></span>
                    <?php echo $is_ai
                        ? esc_html__('Re-theme with AI', 'smartlinker')
                        : esc_html__('Theme with AI', 'smartlinker'); ?>
                </button>
            </form>
        </div>
    </div>

    <?php if (!$ai['enabled'] && !empty($ai['reason'])) : ?>
        <div class="slk-callout slk-callout-locked">
            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
            <span>
                <?php foreach ($ai['reason'] as $line) : ?>
                    <span class="slk-locked-line"><?php echo esc_html($line); ?></span>
                <?php endforeach; ?>
                <span class="slk-locked-line"><?php esc_html_e('The map below still works — it is built from your own categories.', 'smartlinker'); ?></span>
            </span>
        </div>
    <?php endif; ?>

    <?php if (empty($clusters)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-networking"></span>
            <strong><?php esc_html_e('Nothing to map yet', 'smartlinker'); ?></strong>
            <?php esc_html_e('Publish some content, then run a scan from the Links Report so SmartLinker can see how it connects.', 'smartlinker'); ?>
        </div>
    <?php else : ?>

        <div class="slk-treemap" data-clusters="<?php echo esc_attr(wp_json_encode(array_map(function ($c) {
            return [
                'name'    => $c['name'],
                'count'   => $c['count'],
                'health'  => $c['health'],
                'stage'   => $c['stage'],
                'orphans' => $c['orphans'],
                'pillar'  => $c['pillar'],
            ];
        }, $clusters))); ?>">
            <div class="slk-treemap-canvas" role="img"
                 aria-label="<?php esc_attr_e('Topic clusters sized by number of posts', 'smartlinker'); ?>"></div>
        </div>

        <h2><?php esc_html_e('Cluster detail', 'smartlinker'); ?></h2>
        <table class="slk-table">
            <thead>
                <tr>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Cluster', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:80px;"><?php esc_html_e('Posts', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:140px;"><?php esc_html_e('Linked up', 'smartlinker'); ?><?php echo Slk_Admin::help(__('The share of this cluster\'s posts that another post in the same cluster links to. Low means the posts are not referencing each other yet.', 'smartlinker')); ?></th>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Pillar', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:90px;"><?php esc_html_e('Orphans', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clusters as $c) :
                $pct = (int) round($c['health'] * 100);
                $tone = $pct >= 70 ? 'slk-match-high' : ($pct >= 35 ? 'slk-match-mid' : 'slk-match-low');
                ?>
                <tr class="slk-tr">
                    <td>
                        <div class="slk-title-wrap">
                            <span class="slk-title-link"><?php echo esc_html($c['name']); ?></span>
                            <?php if ($c['stage']) : ?>
                                <div class="slk-title-meta">
                                    <span class="slk-chip slk-stage slk-stage-<?php echo esc_attr(strtolower($c['stage'])); ?>"><?php echo esc_html($c['stage']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td><span class="slk-metric"><?php echo esc_html(number_format_i18n($c['count'])); ?></span></td>
                    <td><span class="slk-match <?php echo esc_attr($tone); ?>"><?php echo esc_html($pct . '%'); ?></span></td>
                    <td>
                        <?php if ($c['pillar_id']) : ?>
                            <a href="<?php echo esc_url(Slk_Admin::edit_url($c['pillar_id'])); ?>"><?php echo esc_html($c['pillar']); ?></a>
                            <div class="slk-title-meta"><?php printf(
                                /* translators: %d: number of inbound internal links */
                                esc_html(_n('%d inbound link', '%d inbound links', (int) $c['inbound'], 'smartlinker')),
                                (int) $c['inbound']
                            ); ?></div>
                        <?php else : ?>
                            <span class="slk-metric-zero"><?php esc_html_e('none yet', 'smartlinker'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="slk-metric<?php echo $c['orphans'] ? '' : ' slk-metric-zero'; ?>">
                            <?php echo esc_html(number_format_i18n($c['orphans'])); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
