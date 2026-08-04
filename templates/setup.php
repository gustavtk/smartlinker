<?php
if (!defined('ABSPATH')) {
    exit;
}
/** @var array $setup_steps */
$required = array_filter($setup_steps, function ($s) {
    return empty($s['optional']);
});
$done = count(array_filter($required, function ($s) {
    return !empty($s['done']);
}));
$total = count($required);
$dismiss = wp_nonce_url(admin_url('admin.php?page=smartlinker&slk_setup_dismiss=1'), 'slk_setup_dismiss');
?>
<div class="slk-setup">
    <div class="slk-setup-head">
        <div>
            <div class="slk-setup-title"><?php esc_html_e('Getting started', 'smartlinker'); ?></div>
            <p class="slk-setup-sub">
                <?php esc_html_e('SmartLinker needs to look at your site before it can suggest anything. These take a couple of minutes and only need doing once.', 'smartlinker'); ?>
            </p>
        </div>
        <div class="slk-setup-count">
            <?php
            echo esc_html(sprintf(
                /* translators: 1: steps done, 2: steps total */
                __('%1$d of %2$d done', 'smartlinker'),
                $done,
                $total
            ));
            ?>
        </div>
    </div>

    <div class="slk-setup-bar">
        <span style="width:<?php echo esc_attr($total ? round(($done / $total) * 100) : 0); ?>%"></span>
    </div>

    <ol class="slk-setup-steps">
        <?php foreach ($setup_steps as $i => $s) : ?>
            <li class="slk-setup-step<?php echo !empty($s['done']) ? ' is-done' : ''; ?><?php echo !empty($s['optional']) ? ' is-optional' : ''; ?>">
                <span class="slk-setup-mark" aria-hidden="true"><?php echo !empty($s['done']) ? '' : esc_html($i + 1); ?></span>
                <div class="slk-setup-body">
                    <div class="slk-setup-step-title">
                        <?php echo esc_html($s['title']); ?>
                        <?php if (!empty($s['optional'])) : ?>
                            <span class="slk-setup-optional"><?php esc_html_e('optional', 'smartlinker'); ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="slk-setup-why"><?php echo esc_html($s['why']); ?></p>
                    <p class="slk-setup-detail"><?php echo esc_html($s['detail']); ?></p>
                </div>
                <div class="slk-setup-action">
                    <?php if (empty($s['done'])) : ?>
                        <a class="button<?php echo empty($s['optional']) ? ' button-primary' : ''; ?>"
                           href="<?php echo esc_url($s['action']); ?>"><?php echo esc_html($s['action_label']); ?></a>
                    <?php else : ?>
                        <span class="slk-setup-tick" aria-label="<?php esc_attr_e('Done', 'smartlinker'); ?>"></span>
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>

    <p class="slk-setup-foot">
        <a href="<?php echo esc_url($dismiss); ?>"><?php esc_html_e('Hide this', 'smartlinker'); ?></a>
        <span class="description"><?php esc_html_e('It disappears on its own once the required steps are done.', 'smartlinker'); ?></span>
    </p>
</div>
