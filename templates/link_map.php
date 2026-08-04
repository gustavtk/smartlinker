<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap slk-wrap">
    <h1><?php esc_html_e('Bulk Link Map', 'smartlinker'); ?></h1>
    <p class="slk-intro">
        <?php esc_html_e('Upload a CSV of keyword → destination URL, and SmartLinker will insert those links into matching content across your whole site in one pass. Links are written into your post content (the first matching phrase in each post), just like inserting a suggestion by hand.', 'smartlinker'); ?>
    </p>

    <?php if (isset($_GET['lm_added'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php printf(
            esc_html__('Done — inserted %1$d links across %2$d posts from %3$d rules.', 'smartlinker'),
            (int) $_GET['lm_added'],
            (int) ($_GET['lm_posts'] ?? 0),
            (int) ($_GET['lm_rules'] ?? 0)
        ); ?></p></div>
    <?php elseif (!empty($_GET['lm_err'])) : ?>
        <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Could not read that CSV. Use two columns: keyword, url.', 'smartlinker'); ?></p></div>
    <?php endif; ?>

    <div style="margin:12px 0;padding:16px;background:#fff;border:1px solid #dcdcde;border-radius:6px;max-width:640px;">
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('slk_linkmap'); ?>
            <p>
                <label><strong><?php esc_html_e('Link map CSV', 'smartlinker'); ?></strong><br>
                    <input type="file" name="csv" accept=".csv,text/csv" required style="margin-top:6px;" /></label>
            </p>
            <p>
                <label><?php esc_html_e('Max posts to link per rule:', 'smartlinker'); ?><?php echo Slk_Admin::help(__('Caps how many posts each keyword row will edit, so one common word cannot add links to hundreds of posts at once. Start small and re-run if needed.', 'smartlinker')); ?>
                    <input type="number" name="max_posts" value="25" min="1" max="500" style="width:80px;" /></label>
            </p>
            <p class="description" style="margin-bottom:12px;"><?php esc_html_e('CSV columns: keyword, url (a header row is optional). One link is added per matching post, skipping posts that already link to the URL.', 'smartlinker'); ?></p>
            <button type="submit" name="slk_linkmap_run" value="1" class="button button-primary"
                onclick="return confirm('<?php echo esc_js(__('This will insert links into your post content across the site. Continue?', 'smartlinker')); ?>');">
                <?php esc_html_e('Run Link Map', 'smartlinker'); ?>
            </button>
        </form>
    </div>

    <h2><?php esc_html_e('Example CSV', 'smartlinker'); ?></h2>
    <pre style="background:#f6f7f7;padding:12px;border-radius:5px;max-width:640px;">keyword,url
espresso,https://example.com/espresso/
cold brew,https://example.com/cold-brew/</pre>
</div>
