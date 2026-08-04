<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var bool $configured @var bool $enabled @var string $model @var array $posts */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('AI Suggestions', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Let AI read a post and recommend the internal links that genuinely belong in it — scored by how relevant each target page is, with the anchor text already chosen. Pick a post below, or use the “AI Suggestions” button inside any editor.', 'smartlinker'); ?>
    </p>

    <?php if (!$configured) : ?>
        <div class="slk-callout slk-callout-warn">
            <strong><?php esc_html_e('Add your OpenAI API key to get started.', 'smartlinker'); ?></strong>
            <div class="slk-row-desc" style="margin-top:4px;">
                <?php esc_html_e('AI suggestions run on your own OpenAI account, so calls are billed to you and no data passes through us.', 'smartlinker'); ?>
            </div>
            <p style="margin:12px 0 0;">
                <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=smartlinker_settings')); ?>">
                    <?php esc_html_e('Open Settings', 'smartlinker'); ?>
                </a>
            </p>
        </div>
    <?php else : ?>
        <div class="slk-stat-tiles">
            <div class="slk-tile">
                <span class="slk-tile-cap"><?php esc_html_e('Status', 'smartlinker'); ?></span>
                <span class="slk-tile-num slk-good" style="font-size:20px;"><?php esc_html_e('Connected', 'smartlinker'); ?></span>
                <span class="slk-tile-label"><?php esc_html_e('API key saved', 'smartlinker'); ?></span>
            </div>
            <div class="slk-tile">
                <span class="slk-tile-cap"><?php esc_html_e('Model', 'smartlinker'); ?></span>
                <span class="slk-tile-num" style="font-size:20px;"><?php echo esc_html($model); ?></span>
                <span class="slk-tile-label"><?php esc_html_e('Billed to your OpenAI account', 'smartlinker'); ?></span>
            </div>
            <div class="slk-tile">
                <span class="slk-tile-cap"><?php esc_html_e('Editor button', 'smartlinker'); ?></span>
                <span class="slk-tile-num <?php echo $enabled ? 'slk-good' : 'slk-warn'; ?>" style="font-size:20px;">
                    <?php echo $enabled ? esc_html__('On', 'smartlinker') : esc_html__('Off', 'smartlinker'); ?>
                </span>
                <span class="slk-tile-label"><?php esc_html_e('Shows in the post editor', 'smartlinker'); ?></span>
            </div>
        </div>

        <?php $emb = Slk_Embedding::status(); $pct = $emb['total'] ? (int) round($emb['done'] / $emb['total'] * 100) : 0; ?>
        <div class="slk-card-block">
            <h2 style="margin-top:0;"><?php esc_html_e('Semantic index', 'smartlinker'); ?><?php echo Slk_Admin::help([
                __('An embedding is a numeric fingerprint of a page\'s meaning. With one stored per post, SmartLinker can tell that "which grinder should I buy" belongs with "Choosing a Burr Grinder" — two pages that share no words at all.', 'smartlinker'),
                __('Without it, suggestions fall back to comparing vocabulary, which only finds pages that happen to use the same words.', 'smartlinker'),
                __('Indexing is a one-off cost per post, re-run only when you edit that post. At current OpenAI prices a 500-post site costs well under one cent in total.', 'smartlinker'),
            ]); ?></h2>

            <?php if (!empty($_GET['embedded'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Semantic index is up to date.', 'smartlinker'); ?></p></div>
            <?php elseif (!empty($_GET['embed_cleared'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Semantic index cleared.', 'smartlinker'); ?></p></div>
            <?php elseif (!empty($_GET['embed_err'])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html(wp_unslash($_GET['embed_err'])); ?></p></div>
            <?php endif; ?>

            <p class="slk-row-desc" style="margin-top:0;">
                <?php printf(
                    /* translators: 1: posts indexed, 2: posts total, 3: percentage complete */
                    esc_html__('%1$d of %2$d posts indexed (%3$d%%).', 'smartlinker'),
                    (int) $emb['done'], (int) $emb['total'], $pct
                ); ?>
                <?php if ($emb['stale']) : ?>
                    <strong><?php printf(
                        /* translators: %d: number of posts not yet indexed */
                        esc_html(_n('%d still needs indexing.', '%d still need indexing.', (int) $emb['stale'], 'smartlinker')),
                        (int) $emb['stale']
                    ); ?></strong>
                <?php endif; ?>
            </p>

            <div class="slk-progress-track" style="margin:10px 0 14px;">
                <span class="slk-progress-fill" style="width:<?php echo (int) $pct; ?>%;"></span>
            </div>

            <p style="margin-bottom:0;display:flex;gap:10px;flex-wrap:wrap;">
                <?php if ($emb['stale']) : ?>
                    <a class="button button-primary slk-scan-btn" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=smartlinker_ai&slk_embed=1'), 'slk_embed')); ?>">
                        <?php esc_html_e('Build semantic index', 'smartlinker'); ?>
                    </a>
                <?php else : ?>
                    <span class="slk-badge slk-badge-good"><?php esc_html_e('Up to date', 'smartlinker'); ?></span>
                <?php endif; ?>
                <?php if ($emb['done']) : ?>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=smartlinker_ai&slk_embed_clear=1'), 'slk_embed_clear')); ?>"
                       onclick="return confirm('<?php echo esc_js(__('Delete every stored embedding? You can rebuild them, at the cost of another round of API calls.', 'smartlinker')); ?>');">
                        <?php esc_html_e('Clear index', 'smartlinker'); ?>
                    </a>
                <?php endif; ?>
            </p>
        </div>

        <div class="slk-card-block slk-ai-page">
            <h2 style="margin-top:0;"><?php esc_html_e('Analyse a post', 'smartlinker'); ?></h2>
            <p style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:0;">
                <select id="slk-ai-post" class="slk-search-select" style="min-width:360px;max-width:100%;">
                    <option value=""><?php esc_html_e('— Select a post or page —', 'smartlinker'); ?></option>
                    <?php foreach ($posts as $p) : ?>
                        <option value="<?php echo esc_attr($p->ID); ?>"><?php echo esc_html($p->post_title !== '' ? $p->post_title : '(no title)'); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="button button-primary slk-ai-page-scan">
                    <span class="dashicons dashicons-superhero"></span>
                    <?php esc_html_e('Get AI Suggestions', 'smartlinker'); ?>
                </button>
                <span class="slk-ai-page-status slk-status" role="status"></span>
            </p>
        </div>

        <div class="slk-ai-page-results"></div>
    <?php endif; ?>
</div>
