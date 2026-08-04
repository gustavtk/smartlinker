<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $candidates */
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Add Inbound Internal Links', 'smartlinker'); ?></h1>
    <p class="description">
        <?php esc_html_e('Pick a post or page, and SmartLinker will find other content that could link to it. Insert a link with one click — it is added to the source post.', 'smartlinker'); ?>
    </p>

    <div class="slk-form-card">
        <h2><?php esc_html_e('Choose the content to build links to', 'smartlinker'); ?></h2>
        <div class="slk-field">
            <label class="slk-field-label" for="slk-target"><?php esc_html_e('Link to this content', 'smartlinker'); ?></label>
            <select id="slk-target" class="slk-search-select">
                <option value=""><?php esc_html_e('— Select a post or page —', 'smartlinker'); ?></option>
                <?php foreach ($candidates as $c) :
                    $label = ($c->post_title !== '' ? $c->post_title : '(no title)') .
                        ' — ' . sprintf(_n('%d inbound', '%d inbound', (int) $c->inbound, 'smartlinker'), (int) $c->inbound);
                    ?>
                    <option value="<?php echo esc_attr($c->ID); ?>"<?php echo ((int) $c->inbound === 0) ? ' data-orphan="1"' : ''; ?>>
                        <?php echo esc_html($label); ?><?php echo ((int) $c->inbound === 0) ? ' ⚠' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="slk-field-hint"><?php esc_html_e('Posts marked ⚠ have no inbound internal links yet (orphaned).', 'smartlinker'); ?></p>
        </div>

        <div class="slk-field-actions">
            <button type="button" class="button button-primary slk-inbound-scan">
                <span class="dashicons dashicons-search"></span>
                <?php esc_html_e('Find inbound suggestions', 'smartlinker'); ?>
            </button>
            <span class="slk-inbound-status slk-status" role="status"></span>
        </div>
    </div>

    <div class="slk-inbound-results"></div>
</div>
