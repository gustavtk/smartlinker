<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $data @var string $view */
$rows = $data['rows'];
$stats = $data['stats'];
$base = Slk_Reports::url('placement');
$rebuild = wp_nonce_url(Slk_Reports::url('placement', ['slk_placement_rebuild' => 1]), 'slk_placement_rebuild');

$views = [
    'all'    => __('All posts', 'smartlinker'),
    'bottom' => __('Links buried at the end', 'smartlinker'),
    'top'    => __('Well placed', 'smartlinker'),
];

if ($view === 'bottom') {
    $rows = array_values(array_filter($rows, function ($r) {
        return $r['shape'] === 'bottom';
    }));
} elseif ($view === 'top') {
    $rows = array_values(array_filter($rows, function ($r) {
        return $r['shape'] === 'top';
    }));
}
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Link Placement', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Where your internal links sit inside each post. Every other report treats a link as a fact — it exists, it points somewhere, it works. None of them ask whether anyone reaches it. A link in the last paragraph of a long article is seen only by the few readers still there; one in the opening section is seen by nearly everyone.', 'smartlinker'); ?>
    </p>
    <p class="description" style="margin:-8px 0 16px;max-width:80ch;">
        <?php esc_html_e('Position is measured in visible text, not in the HTML, so a post that opens with a gallery or a table of contents is not reported as having links further down than a reader would experience.', 'smartlinker'); ?>
    </p>

    <?php if (!empty($_GET['rebuilt'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Recalculated.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div class="slk-stat-tiles slk-stat-tiles-wide">
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format($stats['avg'] * 100, 0)); ?>%</span>
            <span class="slk-tile-label"><?php esc_html_e('Average depth into a post', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $stats['bottom'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($stats['bottom'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Posts with links buried', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num"><?php echo esc_html(number_format_i18n($stats['first_q'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Links in the first quarter', 'smartlinker'); ?></span>
        </div>
        <div class="slk-tile">
            <span class="slk-tile-num<?php echo $stats['last_q'] ? ' slk-metric-warn' : ''; ?>"><?php echo esc_html(number_format_i18n($stats['last_q'])); ?></span>
            <span class="slk-tile-label"><?php esc_html_e('Links in the last quarter', 'smartlinker'); ?></span>
        </div>
    </div>

    <div class="slk-tabs slk-tabs-underline" style="margin-bottom:14px;">
        <?php foreach ($views as $key => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg('view', $key, $base)); ?>"
               class="slk-tab<?php echo $view === $key ? ' active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
    </div>

    <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
        <a class="button" href="<?php echo esc_url($rebuild); ?>"><?php esc_html_e('Recalculate', 'smartlinker'); ?></a>
        <?php if (!empty($rows)) : ?>
            <a class="button" href="<?php echo esc_url(Slk_CSV::export_url('placement')); ?>"><?php esc_html_e('Export CSV', 'smartlinker'); ?></a>
        <?php endif; ?>
        <span class="description">
            <?php
            printf(
                /* translators: 1: number of links, 2: number of posts, 3: date */
                esc_html__('%1$s links across %2$s posts. Built %3$s.', 'smartlinker'),
                esc_html(number_format_i18n($stats['links'])),
                esc_html(number_format_i18n($stats['posts'])),
                esc_html(mysql2date('j M Y, H:i', $data['generated']))
            );
            ?>
        </span>
    </p>

    <?php if ($view === 'bottom') : ?>
        <p class="description" style="margin-bottom:12px;">
            <?php
            printf(
                /* translators: %d: percentage threshold */
                esc_html__('Posts whose internal links average past the %d%% mark. Moving one link up into the opening section is usually a single sentence of work, and it is the cheapest improvement in this whole plugin.', 'smartlinker'),
                (int) (Slk_Placement::BOTTOM * 100)
            );
            ?>
        </p>
    <?php elseif ($view === 'top') : ?>
        <p class="description" style="margin-bottom:12px;">
            <?php esc_html_e('Posts already linking early. Shown so the standard is concrete rather than theoretical — these are what the buried ones should look like.', 'smartlinker'); ?>
        </p>
    <?php endif; ?>

    <?php if (empty($rows)) : ?>
        <div class="slk-empty-state">
            <span class="dashicons dashicons-editor-ol"></span>
            <strong><?php esc_html_e('Nothing to show', 'smartlinker'); ?></strong>
            <?php
            echo $stats['posts'] === 0
                ? esc_html__('No published post contains an internal link yet.', 'smartlinker')
                : esc_html__('Nothing matches this view.', 'smartlinker');
            ?>
        </div>
    <?php else : ?>
        <table class="slk-table slk-placement-table">
            <thead>
                <tr>
                    <th class="slk-th-actions" style="text-align:left;"><?php esc_html_e('Post', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:230px;"><?php esc_html_e('Where the links sit', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:110px;"><?php esc_html_e('Average', 'smartlinker'); ?></th>
                    <th class="slk-th-actions" style="text-align:left;width:80px;"><?php esc_html_e('Links', 'smartlinker'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($rows, 0, 300) as $r) : ?>
                <tr class="slk-tr">
                    <td>
                        <a class="slk-title-link" href="<?php echo esc_url($r['edit_url']); ?>"><?php echo esc_html($r['title']); ?></a>
                        <?php if ($r['shape'] === 'bottom') : ?>
                            <span class="slk-badge slk-badge-warn"><?php esc_html_e('buried', 'smartlinker'); ?></span>
                        <?php elseif ($r['shape'] === 'top') : ?>
                            <span class="slk-badge slk-badge-good"><?php esc_html_e('early', 'smartlinker'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php /* Each mark is one link, placed where it falls in the article. */ ?>
                        <div class="slk-place-track" title="<?php esc_attr_e('Left is the start of the post, right is the end. Each mark is one internal link.', 'smartlinker'); ?>">
                            <span class="slk-place-q"></span>
                            <?php foreach ($r['spots'] as $pos) : ?>
                                <span class="slk-place-dot<?php echo $pos >= 0.75 ? ' is-late' : ($pos <= 0.25 ? ' is-early' : ''); ?>"
                                      style="left:<?php echo esc_attr(number_format($pos * 100, 1)); ?>%"></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="slk-title-meta">
                            <?php esc_html_e('start', 'smartlinker'); ?> → <?php esc_html_e('end', 'smartlinker'); ?>
                        </div>
                    </td>
                    <td>
                        <span class="<?php echo $r['avg'] >= Slk_Placement::BOTTOM ? 'slk-metric-warn' : ''; ?>">
                            <?php echo esc_html(number_format($r['avg'] * 100, 0)); ?>%
                        </span>
                        <?php if ($r['last_quarter']) : ?>
                            <div class="slk-title-meta">
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: %d: number of links */
                                    _n('%d in the last quarter', '%d in the last quarter', $r['last_quarter'], 'smartlinker'),
                                    $r['last_quarter']
                                ));
                                ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(number_format_i18n($r['links'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
