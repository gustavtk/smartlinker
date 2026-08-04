<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $posts */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Why not?', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('When a link you expected does not appear, this shows you exactly which check it failed and what would have to change. It replays the same pipeline the engine uses, so the answer is the real reason, not a guess.', 'smartlinker'); ?>
    </p>

    <div class="slk-diagnose-form">
        <label>
            <span class="description"><?php esc_html_e('I am editing this post…', 'smartlinker'); ?></span>
            <select class="slk-search-select slk-diag-source">
                <option value=""><?php esc_html_e('Choose a post', 'smartlinker'); ?></option>
                <?php foreach ($posts as $p) : ?>
                    <option value="<?php echo esc_attr($p->ID); ?>"><?php echo esc_html(get_the_title($p->ID)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <span class="slk-diag-arrow" aria-hidden="true">→</span>

        <label>
            <span class="description"><?php esc_html_e('…and expected it to link to', 'smartlinker'); ?></span>
            <select class="slk-search-select slk-diag-target">
                <option value=""><?php esc_html_e('Choose a destination', 'smartlinker'); ?></option>
                <?php foreach ($posts as $p) : ?>
                    <option value="<?php echo esc_attr($p->ID); ?>"><?php echo esc_html(get_the_title($p->ID)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <button type="button" class="button button-primary slk-diag-run"><?php esc_html_e('Explain', 'smartlinker'); ?></button>
    </div>

    <div class="slk-diag-result" role="status" aria-live="polite"></div>
</div>
