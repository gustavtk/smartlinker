<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var WP_Post $post */
?>
<div class="slk-metabox" data-post-id="<?php echo esc_attr($post->ID); ?>">
    <p class="slk-metabox-intro">
        <?php esc_html_e('Scan this content for relevant internal links to your other posts and pages.', 'smartlinker'); ?>
    </p>
    <div class="slk-scan-bar">
        <button type="button" class="button button-primary slk-scan slk-scan-btn">
            <svg class="slk-scan-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            <?php esc_html_e('Find Link Suggestions', 'smartlinker'); ?>
        </button>
        <button type="button"
            class="button button-primary slk-ai-scan slk-scan-btn<?php echo empty($slk_ai_enabled) ? ' slk-scan-btn-locked' : ''; ?>"
            <?php disabled(empty($slk_ai_enabled)); ?>>
            <?php if (empty($slk_ai_enabled)) : ?>
                <svg class="slk-scan-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            <?php else : ?>
                <svg class="slk-scan-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>
            <?php endif; ?>
            <?php esc_html_e('AI Suggestions', 'smartlinker'); ?>
        </button>
        <?php /* The other direction — which existing posts should link HERE. */ ?>
        <button type="button" class="button button-primary slk-inbound-here slk-scan-btn"
            title="<?php esc_attr_e('Which of your posts should link to this one', 'smartlinker'); ?>">
            <svg class="slk-scan-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14M3 4v16"/></svg>
            <?php esc_html_e('Links In', 'smartlinker'); ?>
        </button>
    </div>
    <?php if (!empty($slk_ai_reason)) : ?>
        <?php
        /*
         * Deliberately a visible callout, not a Slk_Admin::help() bubble. The
         * bar is hoisted into the postbox header, and in the block editor that
         * header sits inside .edit-post-layout__metaboxes — a ~150px drawer
         * with overflow:auto, which clips an upward-opening bubble. A callout
         * in the panel body has room, and this explanation is the whole reason
         * the button looks broken, so it should not be hover-only anyway.
         */
        ?>
        <div class="slk-callout slk-callout-locked">
            <span class="dashicons dashicons-lock" aria-hidden="true"></span>
            <span>
                <?php foreach ($slk_ai_reason as $slk_line) : ?>
                    <span class="slk-locked-line"><?php echo esc_html($slk_line); ?></span>
                <?php endforeach; ?>
            </span>
        </div>
    <?php endif; ?>
    <p class="slk-scan-status"><span class="slk-status" role="status"></span></p>
    <div class="slk-suggestions"></div>
    <?php
    /*
     * Keywords live next to the suggestions on purpose. The best anchor tier
     * is an exact focus-keyword match, so when the results look thin the fix
     * is usually a missing keyword — and it should be fixable right here
     * rather than on another screen.
     */
    $slk_keywords = Slk_TargetKeyword::for_post($post->ID);
    ?>
    <div class="slk-kw-panel" data-post-id="<?php echo esc_attr($post->ID); ?>">
        <div class="slk-kw-head"><?php esc_html_e('Target keywords for this post', 'smartlinker'); ?><?php echo Slk_Admin::help([
            __('The phrases this page should rank for. When another post mentions one of them, SmartLinker offers it as the anchor — this is the strongest signal it has.', 'smartlinker'),
            __('A keyword marked SEO comes live from Rank Math, Yoast, AIOSEO or SEOPress and is managed there. Ones you add here are SmartLinker\'s own.', 'smartlinker'),
        ], 'left'); ?></div>

        <div class="slk-kw-chips">
            <?php foreach ($slk_keywords as $kw) : ?>
                <span class="slk-kw-chip<?php echo $kw['source'] === 'seo' ? ' is-seo' : ''; ?>" data-id="<?php echo esc_attr($kw['id']); ?>">
                    <?php echo esc_html($kw['keyword']); ?>
                    <?php if ($kw['source'] === 'seo') : ?>
                        <em class="slk-kw-src"><?php esc_html_e('SEO', 'smartlinker'); ?></em>
                    <?php else : ?>
                        <button type="button" class="slk-kw-del" aria-label="<?php esc_attr_e('Remove keyword', 'smartlinker'); ?>">&times;</button>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="slk-kw-add">
            <?php if (Slk_AI::is_configured()) : ?>
                <button type="button" class="slk-kw-extract">
                    <span class="slk-kw-spark">✨</span>
                    <?php esc_html_e('Re-extract keywords for this post (1 OpenAI call)', 'smartlinker'); ?>
                </button>
            <?php endif; ?>
            <span class="slk-kw-msg" role="status"></span>
        </div>
    </div>

    <p class="slk-note description">
        <?php esc_html_e('Tip: save the post first so suggestions reflect your latest content.', 'smartlinker'); ?>
    </p>
</div>
